<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Medicine;
use App\Models\PurchaseInvoice;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sync\PharmaTrack;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock for Siam's pharmacy (spec ch 06). Quantities are in the medicine's stock unit: a box for boxed
 * medicines, a piece for loose ones. Purchase costs are in taka and locked when a lot is saved.
 */
class StockService
{
    public const SALE_OFFER = 'offer';

    /** A sale made in PharmaTrack, which already happened and only has to be written down here. */
    public const SALE_EXTERNAL = 'external';

    public const SALE_PAIKARI = 'paikari';

    public const SALE_RETAIL = 'retail';

    public const REASONS = ['broken', 'lost', 'stolen', 'entry_mistake'];

    public function __construct(private PurchasePricing $pricing) {}

    /** MRP of one stock unit. Stock always sells at the current MRP (STK-7). */
    public static function unitMrp(Medicine $m): float
    {
        return (float) ($m->packaging_type === 'box' ? $m->mrp_box : $m->mrp_piece);
    }

    // ---------------------------------------------------------------- receiving

    /**
     * Enter a purchase invoice (STK-1 to STK-9).
     *
     * Each line: medicine_id, units, bonus?, company_percent? (typed once for a whole company list or per line),
     * mpo_percent? (cascades on top), shelf?, expiry_date?. A line with no usable purchase % cannot be saved (STK-8).
     *
     * @param  array<string, mixed>  $data  invoice_number?, invoice_date, note?, lines[]
     *
     * @throws StockException
     */
    public function receive(StockSource $source, array $data, ?User $by = null, bool $confirmDuplicate = false): PurchaseInvoice
    {
        $number = isset($data['invoice_number']) && $data['invoice_number'] !== '' ? (string) $data['invoice_number'] : null;

        if ($number !== null && ! $confirmDuplicate && PurchaseInvoice::where('stock_source_id', $source->id)->where('invoice_number', $number)->exists()) {
            throw StockException::duplicateInvoice($number);
        }

        $invoice = DB::transaction(function () use ($source, $data, $by, $number) {
            $invoice = PurchaseInvoice::create([
                'stock_source_id' => $source->id,
                'invoice_number' => $number,
                'invoice_date' => $data['invoice_date'],
                'entered_by' => $by?->id,
                'note' => $data['note'] ?? null,
            ]);

            $total = 0.0;
            foreach ($data['lines'] as $line) {
                $medicine = Medicine::findOrFail($line['medicine_id']);
                $base = isset($line['company_percent']) && $line['company_percent'] !== '' ? (float) $line['company_percent'] : $this->pricing->resolvePercent($source, $medicine);

                if ($base === null) {
                    throw StockException::noPercent($medicine->brand_name);
                }

                $percents = [$base];
                if (isset($line['mpo_percent']) && $line['mpo_percent'] !== '') {
                    $percents[] = (float) $line['mpo_percent'];
                }

                $mrp = self::unitMrp($medicine);
                $math = PurchasePricing::line($mrp, $percents, (float) $line['units'], (float) ($line['bonus'] ?? 0));

                $lot = StockLot::create([
                    'medicine_id' => $medicine->id,
                    'purchase_invoice_id' => $invoice->id,
                    'units_paid' => $line['units'],
                    'units_bonus' => $line['bonus'] ?? 0,
                    'units_received' => $math['received'],
                    'units_remaining' => $math['received'],
                    'unit_cost' => $math['unit_cost'],
                    'mrp_unit_at_purchase' => $mrp,
                    'purchase_percent' => $math['percent'],
                    'shelf' => $line['shelf'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                ]);

                StockMovement::create([
                    'medicine_id' => $medicine->id,
                    'stock_lot_id' => $lot->id,
                    'type' => 'purchase',
                    'units' => $math['received'],
                    'unit_cost' => $math['unit_cost'],
                    'user_id' => $by?->id,
                ]);

                $total += $math['total_paid'];
            }

            $total = round($total, 2);
            // Paid in full when it is entered, unless the buyer says how much is paid now; what is left is owed to the company on a day (spec ch 28 note 12).
            $paid = isset($data['paid_now']) && $data['paid_now'] !== '' ? round((float) $data['paid_now'], 2) : $total;
            if ($paid < 0 || $paid > $total) {
                throw new StockException('The amount paid cannot be more than the purchase total.');
            }

            $due = $paid < $total ? ($data['due_date'] ?? null) : null;
            if ($paid < $total && ! $due) {
                throw new StockException('Write the day the rest is due.');
            }

            $invoice->update(['total_cost' => $total, 'paid_amount' => $paid, 'due_date' => $due]);

            return $invoice->load('lots');
        });

        foreach ($invoice->lots as $lot) {
            $this->tell(fn () => app(PharmaTrack::class)->announceChange(Medicine::findOrFail($lot->medicine_id), (float) $lot->units_received, 'Purchase received', 'receipt:lot'.$lot->id.':'.now()->timestamp));
        }

        return $invoice;
    }

    // ------------------------------------------------------------------ reading

    public function onHand(Medicine $medicine): float
    {
        return (float) StockLot::where('medicine_id', $medicine->id)->sum('units_remaining');
    }

    /** Money tied up in stock, at purchase cost. */
    public function stockValue(): float
    {
        return round((float) StockLot::where('units_remaining', '>', 0)->sum(DB::raw('units_remaining * unit_cost')), 2);
    }

    /**
     * LOS-1: would selling one unit at this price lose money on every lot in stock? With no stock there is
     * no purchase price to compare, so nothing can be called a loss yet.
     */
    public function isLoss(Medicine $medicine, float $unitPrice): bool
    {
        $cheapest = StockLot::where('medicine_id', $medicine->id)->where('units_remaining', '>', 0)->min('unit_cost');

        return $cheapest !== null && $unitPrice < (float) $cheapest;
    }

    /**
     * LOS-1 for a rate being set: a percent off MRP, or a fixed taka price per piece (which the pricing engine
     * converts to a percent the same way). True when the resulting unit price is below every lot's cost.
     */
    public function rateWouldLose(Medicine $medicine, ?float $percent, ?float $fixedPricePerPiece): bool
    {
        if ($percent === null && $fixedPricePerPiece !== null && (float) $medicine->mrp_piece > 0) {
            $percent = max(0.0, (1 - $fixedPricePerPiece / (float) $medicine->mrp_piece) * 100);
        }

        if ($percent === null) {
            return false;
        }

        return $this->isLoss($medicine, self::unitMrp($medicine) * (1 - $percent / 100));
    }

    // ------------------------------------------------------------------ selling

    public function masterSyncEnabled(): bool
    {
        return (bool) Setting::read('stock.master_sync', false);
    }

    public function setMasterSync(bool $on): void
    {
        Setting::write('stock.master_sync', $on);
    }

    /**
     * Take stock out for a sale (spec 4.3.4). Offer orders always deduct; normal paikari and retail sales
     * deduct only when Master Stock Sync is on, so the code exists now and stays off at launch.
     *
     * Lots are not FIFO. The lot used is the dearest one that still leaves a profit at this price; if none
     * does, the cheapest lot is used and the sale is a loss (LOS-3/5: only the admin may make one).
     *
     * @return Collection<int, StockMovement>|null null when this kind of sale does not deduct stock yet
     *
     * @throws StockException
     */
    public function sell(Medicine $medicine, float $units, float $unitPrice, string $kind, ?User $by = null, ?int $invoiceLineId = null, ?array $onlyLots = null): ?Collection
    {
        if ($kind !== self::SALE_OFFER && $kind !== self::SALE_EXTERNAL && ! $this->masterSyncEnabled()) {
            return null;
        }

        return DB::transaction(function () use ($medicine, $units, $unitPrice, $by, $invoiceLineId, $onlyLots, $kind) {
            $lots = StockLot::where('medicine_id', $medicine->id)->where('units_remaining', '>', 0)
                ->when($onlyLots !== null, fn ($q) => $q->whereIn('id', $onlyLots))->lockForUpdate()->get();

            if ((float) $lots->sum('units_remaining') < $units) {
                throw StockException::insufficient($medicine->brand_name);
            }

            $left = $units;
            $movements = collect();

            while ($left > 0.0001) {
                $available = $lots->filter(fn (StockLot $l) => (float) $l->units_remaining > 0);
                $profitable = $available->filter(fn (StockLot $l) => (float) $l->unit_cost <= $unitPrice);

                $lot = $profitable->isNotEmpty()
                    ? $profitable->sortByDesc(fn (StockLot $l) => [(float) $l->unit_cost, -$l->id])->first()
                    : $available->sortBy(fn (StockLot $l) => [(float) $l->unit_cost, $l->id])->first();

                $isLoss = (float) $lot->unit_cost > $unitPrice;
                // A sale PharmaTrack already made cannot be refused: it is recorded, loss and all, and the owner sees it.
                if ($isLoss && $kind !== self::SALE_EXTERNAL && $by?->account_type !== AccountType::Admin) {
                    throw StockException::loss($medicine->brand_name);
                }

                $take = min($left, (float) $lot->units_remaining);
                $lot->units_remaining = round((float) $lot->units_remaining - $take, 4);
                $lot->save();

                $movements->push(StockMovement::create([
                    'medicine_id' => $medicine->id,
                    'stock_lot_id' => $lot->id,
                    'invoice_line_id' => $invoiceLineId,
                    'type' => 'sale',
                    'units' => -$take,
                    'unit_cost' => $lot->unit_cost,
                    'unit_price' => $unitPrice,
                    'is_loss' => $isLoss,
                    'user_id' => $by?->id,
                ]));

                $left = round($left - $take, 4);
            }

            return $movements;
        });
    }

    /**
     * Put back exactly what an invoice line took (a removed line, a cancelled invoice, a line changed to another
     * quantity). The sale movements stay in the history, marked reversed, and a return movement records the units.
     */
    public function restore(int $invoiceLineId, ?User $by = null): float
    {
        return DB::transaction(function () use ($invoiceLineId, $by) {
            $restored = 0.0;
            $movements = StockMovement::where('invoice_line_id', $invoiceLineId)->where('type', 'sale')->where('reversed', false)->lockForUpdate()->get();

            foreach ($movements as $movement) {
                $units = abs((float) $movement->units);
                $lot = StockLot::whereKey($movement->stock_lot_id)->lockForUpdate()->first();

                if ($lot) {
                    $lot->units_remaining = round((float) $lot->units_remaining + $units, 4);
                    $lot->save();
                }

                $movement->update(['reversed' => true]);
                StockMovement::create([
                    'medicine_id' => $movement->medicine_id, 'stock_lot_id' => $movement->stock_lot_id, 'invoice_line_id' => $invoiceLineId,
                    'type' => 'return', 'units' => $units, 'unit_cost' => $movement->unit_cost, 'unit_price' => $movement->unit_price, 'user_id' => $by?->id,
                ]);
                $restored += $units;
            }

            return round($restored, 4);
        });
    }

    /**
     * A customer returns some of what a line sold (spec ch 12). The units go back into the very lot they came out of,
     * at that lot's price, and a lot that had run out reopens. The sale shrinks by the same amount so profit stays right.
     * Returns how many units went back (zero when the sale took nothing from stock, e.g. Master Stock Sync was off).
     */
    public function returnUnits(int $invoiceLineId, float $units, ?User $by = null): float
    {
        $medicineId = null;
        $back = DB::transaction(function () use ($invoiceLineId, $units, $by, &$medicineId) {
            $left = $units;
            $back = 0.0;
            $movements = StockMovement::where('invoice_line_id', $invoiceLineId)->where('type', 'sale')->where('reversed', false)->orderByDesc('id')->lockForUpdate()->get();

            foreach ($movements as $movement) {
                if ($left <= 0.0001) {
                    break;
                }

                $sold = abs((float) $movement->units);
                $take = min($left, $sold);
                $lot = StockLot::whereKey($movement->stock_lot_id)->lockForUpdate()->first();

                if ($lot) {
                    $lot->units_remaining = round((float) $lot->units_remaining + $take, 4);
                    $lot->save();
                }

                if ($take >= $sold - 0.0001) {
                    $movement->update(['reversed' => true]);
                } else {
                    $movement->update(['units' => -round($sold - $take, 2)]);
                }

                StockMovement::create([
                    'medicine_id' => $movement->medicine_id, 'stock_lot_id' => $movement->stock_lot_id, 'invoice_line_id' => $invoiceLineId,
                    'type' => 'return', 'reason' => 'customer_return', 'units' => $take, 'unit_cost' => $movement->unit_cost, 'unit_price' => $movement->unit_price, 'user_id' => $by?->id,
                ]);

                $left = round($left - $take, 4);
                $back += $take;
                $medicineId = $movement->medicine_id;
            }

            return round($back, 4);
        });

        if ($back > 0 && $medicineId !== null) {
            $this->tell(fn () => app(PharmaTrack::class)->announceChange(Medicine::findOrFail($medicineId), $back, 'Customer return', 'return:line'.$invoiceLineId.':'.now()->format('YmdHisu')));
        }

        return $back;
    }

    // -------------------------------------------------------------- corrections

    /**
     * COR-1: only the admin changes a stock number, always with a reason. It is an entry fix, not a sale.
     *
     * @throws StockException
     */
    public function correct(Medicine $medicine, float $newOnHand, string $reason, User $by, ?string $note = null): float
    {
        if ($by->account_type !== AccountType::Admin) {
            throw StockException::adminOnly();
        }

        $delta = DB::transaction(function () use ($medicine, $newOnHand, $reason, $by, $note) {
            $lots = StockLot::where('medicine_id', $medicine->id)->orderBy('id')->lockForUpdate()->get();
            $delta = round($newOnHand - (float) $lots->sum('units_remaining'), 4);

            if ($delta === 0.0) {
                return 0.0;
            }

            if ($delta > 0) {
                $lot = $lots->last() ?? throw new StockException('Enter this medicine through a purchase first, so it has a cost.');
                $lot->units_remaining = round((float) $lot->units_remaining + $delta, 4);
                $lot->save();
                $this->logCorrection($medicine, $lot, $delta, $reason, $by, $note);

                return $delta;
            }

            $left = -$delta;
            foreach ($lots->filter(fn (StockLot $l) => (float) $l->units_remaining > 0) as $lot) {
                $take = min($left, (float) $lot->units_remaining);
                $lot->units_remaining = round((float) $lot->units_remaining - $take, 4);
                $lot->save();
                $this->logCorrection($medicine, $lot, -$take, $reason, $by, $note);
                $left = round($left - $take, 4);

                if ($left <= 0) {
                    break;
                }
            }

            return $delta;
        });

        if ($delta !== 0.0) {
            $this->tell(fn () => app(PharmaTrack::class)->announceChange($medicine, $delta, 'Correction: '.$reason, 'fix:'.$medicine->id.':'.now()->format('YmdHisu')));
        }

        return $delta;
    }

    /** Telling PharmaTrack must never undo or hold up a change that has already been made here. */
    private function tell(callable $do): void
    {
        try {
            $do();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function logCorrection(Medicine $medicine, StockLot $lot, float $units, string $reason, User $by, ?string $note): void
    {
        StockMovement::create([
            'medicine_id' => $medicine->id,
            'stock_lot_id' => $lot->id,
            'type' => 'correction',
            'units' => $units,
            'unit_cost' => $lot->unit_cost,
            'reason' => $reason,
            'note' => $note,
            'user_id' => $by->id,
        ]);
    }
}
