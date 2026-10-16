<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\Notice;
use App\Models\QuantityStep;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The counter without internet (spec ch 8, "Working without internet"). While the line is up the counter device keeps a copy of
 * the medicine list and prices; when it is down, retail sales are made on that copy and kept on the device with a temporary number,
 * and sent here when the line is back. A sale is never lost: if the stock says it cannot have been sold, it is recorded anyway,
 * outside the system's stock, and the owner is told what to look at.
 *
 * Only retail sales go this way. A controlled medicine needs the buyer's details written down and cannot be sold offline.
 */
class OfflineCounter
{
    public function __construct(private InvoiceService $invoices) {}

    /** The biggest discount a staff member may give: their own limit, or the shop's. The owner has none. */
    public function limitFor(User $by): ?float
    {
        if ($by->account_type === AccountType::Admin) {
            return null;
        }

        return $by->discount_limit !== null ? (float) $by->discount_limit : (float) Setting::read('counter.staff_discount_limit', 5);
    }

    /**
     * A copy of what the counter needs to sell: names, packs, printed prices, quantity steps, barcodes and the one thing loss
     * protection needs: for a medicine whose margin is thinner than the staff limit, the most it may be discounted. Not the cost itself,
     * and not the stock.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $by): array
    {
        $limit = $this->limitFor($by);
        $floors = $this->thinMargins($limit);
        $steps = QuantityStep::all()->groupBy('medicine_id');

        $medicines = [];
        Medicine::query()->active()->with('company:id,name')->orderBy('id')->chunk(2000, function ($chunk) use (&$medicines, $floors, $steps) {
            foreach ($chunk as $m) {
                $row = [
                    'id' => $m->id, 'n' => $m->brand_name, 's' => $m->strength, 'f' => $m->form, 'c' => $m->company?->name,
                    'p' => $m->packaging_type === 'box' ? 'box' : 'pcs', 'sb' => $m->strips_per_box, 'pp' => $m->pieces_per_strip,
                    'mb' => $m->mrp_box !== null ? (float) $m->mrp_box : null, 'ms' => $m->mrp_strip !== null ? (float) $m->mrp_strip : null, 'mp' => $m->mrp_piece !== null ? (float) $m->mrp_piece : null,
                ];
                if ($m->is_controlled) {
                    $row['ctl'] = 1;
                }
                if (isset($floors[$m->id])) {
                    $row['ml'] = $floors[$m->id];
                }
                if ($steps->has($m->id)) {
                    $row['st'] = $steps[$m->id]->map(fn (QuantityStep $s) => [$s->unit, $s->min_qty, $s->kind === 'percent' ? 'p' : 't', (float) $s->value])->all();
                }
                $medicines[] = $row;
            }
        });

        return [
            'taken_at' => now()->toIso8601String(),
            'limit' => $limit,
            'shop' => ['name' => (string) Setting::read('shop.name', config('app.name')), 'phone' => config('brand.phone'), 'address' => config('brand.address')],
            'methods' => InvoiceService::PAYMENT_METHODS,
            'medicines' => $medicines,
            'codes' => MedicineBarcode::query()->pluck('medicine_id', 'code'),
        ];
    }

    /**
     * Medicines whose margin is thinner than the limit: medicine id => the most percent off the printed price that still leaves no loss.
     *
     * @return array<int, float>
     */
    public function thinMargins(?float $limit): array
    {
        if ($limit === null) {
            return [];
        }

        $cheapest = StockLot::where('units_remaining', '>', 0)->selectRaw('medicine_id, min(unit_cost) as cost')->groupBy('medicine_id')->pluck('cost', 'medicine_id');
        $out = [];

        Medicine::query()->active()->whereIn('id', $cheapest->keys())->get(['id', 'packaging_type', 'mrp_box', 'mrp_piece'])->each(function (Medicine $m) use ($cheapest, $limit, &$out) {
            $mrp = StockService::unitMrp($m);
            if ($mrp <= 0) {
                return;
            }

            $safe = floor((1 - (float) $cheapest[$m->id] / $mrp) * 10000) / 100;
            if ($safe < $limit) {
                $out[$m->id] = max(0.0, $safe);
            }
        });

        return $out;
    }

    /**
     * Record the sales a counter made offline. Each one is answered: its real memo number, or why it was not recorded.
     *
     * @param  list<array<string, mixed>>  $sales  ref, at, lines[id, unit, qty], discount, method, paid
     * @return list<array<string, mixed>>
     */
    public function sync(User $by, array $sales): array
    {
        return array_map(fn (array $sale) => $this->one($by, $sale), $sales);
    }

    /** @param array<string, mixed> $sale */
    private function one(User $by, array $sale): array
    {
        $ref = (string) $sale['ref'];

        // The same sale sent twice (a slow line, a retry) is recorded once.
        if ($done = Invoice::where('offline_ref', $ref)->first()) {
            return ['ref' => $ref, 'status' => 'synced', 'id' => $done->id, 'number' => $done->number, 'flags' => $done->offline_flags ?? []];
        }

        $medicines = Medicine::whereIn('id', collect($sale['lines'])->pluck('id'))->get()->keyBy('id');

        foreach ($sale['lines'] as $line) {
            $m = $medicines->get($line['id']);
            if ($m === null) {
                return ['ref' => $ref, 'status' => 'refused', 'code' => 'unknown', 'name' => null, 'reason' => 'A medicine on this sale is not in the list any more.'];
            }
            if ($m->is_controlled) {
                return ['ref' => $ref, 'status' => 'refused', 'code' => 'controlled', 'name' => $m->brand_name, 'reason' => $m->brand_name.' is a controlled medicine. Its buyer must be written down, so it is not sold offline. Enter this sale on the counter.'];
            }
            if (! in_array($line['unit'], InvoiceService::UNITS, true) || ($line['unit'] !== 'piece' && $m->packaging_type !== 'box')) {
                return ['ref' => $ref, 'status' => 'refused', 'code' => 'unit', 'name' => $m->brand_name, 'reason' => $m->brand_name.' cannot be sold in that unit.'];
            }
        }

        try {
            return DB::transaction(fn () => $this->record($by, $sale, $ref, $medicines));
        } catch (StockException $e) {
            return ['ref' => $ref, 'status' => 'refused', 'code' => 'other', 'name' => null, 'reason' => $e->getMessage()];
        }
    }

    /** @param Collection<int, Medicine> $medicines */
    private function record(User $by, array $sale, string $ref, $medicines): array
    {
        $at = Carbon::parse($sale['at']);
        $at = $at->isFuture() ? now() : $at;
        $flags = ['out_of_stock' => [], 'loss' => [], 'discount_reduced' => false];

        $invoice = $this->invoices->open('retail', $by);
        $invoice->update(['opened_at' => $at, 'offline_ref' => $ref, 'offline_at' => $at, 'note' => 'Offline sale '.$ref]);

        foreach ($sale['lines'] as $line) {
            $m = $medicines[$line['id']];

            try {
                $this->invoices->addLine($invoice->fresh(), $m, $line['unit'], (float) $line['qty'], $by);
            } catch (StockException $e) {
                // It was sold. The stock cannot say so: it goes on the memo outside the system's stock, and the owner decides.
                $this->invoices->addLine($invoice->fresh(), $m, $line['unit'], (float) $line['qty'], $by, null, true);
                $flags[str_contains($e->getMessage(), 'loss') ? 'loss' : 'out_of_stock'][] = $m->brand_name;
            }
        }

        $wanted = max(0.0, min(100.0, (float) ($sale['discount'] ?? 0)));
        $limit = $this->limitFor($by);
        $allowed = $limit === null ? $wanted : min($wanted, $limit);
        $floors = $this->thinMargins($limit);
        foreach ($medicines as $m) {
            if (isset($floors[$m->id])) {
                $allowed = min($allowed, $floors[$m->id]);
            }
        }
        $flags['discount_reduced'] = $allowed + 0.0001 < $wanted;
        if ($allowed > 0) {
            $this->invoices->setEndDiscount($invoice->fresh(), $allowed, $by);
        }

        $done = $this->invoices->finalise($invoice->fresh(), $by, (string) ($sale['method'] ?? 'cash'), isset($sale['paid']) ? (float) $sale['paid'] : null);
        // The sale belongs to the day it was made, not the day it was sent.
        $done->update(['finalised_at' => $at, 'completed_at' => $at, 'offline_flags' => $flags]);

        $look = array_filter($flags);
        if ($look) {
            $this->tell($done, $ref, $flags);
        }

        return ['ref' => $ref, 'status' => 'synced', 'id' => $done->id, 'number' => $done->number, 'flags' => $flags];
    }

    /** The owner hears what to look at, in plain words (spec ch 8: "Stock went below zero for these items"). */
    private function tell(Invoice $invoice, string $ref, array $flags): void
    {
        $parts = [];
        if ($flags['out_of_stock']) {
            $parts[] = 'stock went below zero for '.implode(', ', $flags['out_of_stock']);
        }
        if ($flags['loss']) {
            $parts[] = implode(', ', $flags['loss']).' sold below cost';
        }
        if ($flags['discount_reduced']) {
            $parts[] = 'the discount was cut to the allowed limit';
        }

        foreach (User::withPermission('stock') as $owner) {
            Notice::create(['user_id' => $owner->id, 'kind' => 'offline_conflict', 'body' => mb_substr('Offline sale '.$invoice->number.' ('.$ref.'): '.implode('; ', $parts), 0, 290)]);
        }
    }
}
