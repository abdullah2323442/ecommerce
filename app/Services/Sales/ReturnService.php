<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Returns (spec ch 12). Paikari buyers and retail walk-ins only, inside a short window counted from receipt, with a
 * reason always. Value is at the price they paid; credit or exchange is full value, cash is half. Goods go back
 * into the lot they came from and are sold again. Nothing moves until the admin approves.
 */
class ReturnService
{
    public const REASONS = ['wrong_item', 'damaged', 'near_expiry', 'ordered_by_mistake', 'staff_error', 'other'];

    public function __construct(private StockService $stock) {}

    public function windowDays(): int
    {
        return max(1, (int) Setting::read('returns.window_days', 3));
    }

    public function cashPercent(): float
    {
        return (float) Setting::read('returns.cash_percent', 50);
    }

    public function creditBalance(User $user, string $sector = 'pharmacy'): float
    {
        return round((float) CreditEntry::where('user_id', $user->id)->where('sector', $sector)->sum('amount'), 2);
    }

    /**
     * Everything a customer bought and could still return, newest first, for the return screen (shop or plain account).
     *
     * @return list<array<string, mixed>>
     */
    public function memosFor(User $user, ?string $from, ?string $to): array
    {
        return Invoice::where('customer_id', $user->id)->where('status', 'final')->with('lines.medicine:id,brand_name,strength')
            ->when($from, fn ($q) => $q->whereDate('finalised_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('finalised_at', '<=', $to))
            ->latest('finalised_at')->limit(30)->get()
            ->map(fn (Invoice $i) => [
                'id' => $i->id, 'number' => $i->number, 'when' => $i->finalised_at?->toIso8601String(),
                'lines' => $i->lines->map(fn (InvoiceLine $l) => [
                    'id' => $l->id, 'name' => $l->medicine->brand_name, 'strength' => $l->medicine->strength, 'unit' => $l->unit, 'quantity' => (float) $l->quantity,
                    'returnable' => $this->returnable($l), 'offer' => $l->offer_product_id !== null,
                ])->values()->all(),
            ])->values()->all();
    }

    /** Stock units of this line already returned or waiting to be (a rejected return frees them again). */
    public function returnedUnits(InvoiceLine $line): float
    {
        return round((float) SaleReturnLine::where('invoice_line_id', $line->id)
            ->whereHas('saleReturn', fn ($q) => $q->where('status', '!=', 'rejected'))->sum('stock_units'), 4);
    }

    /** Is this line inside the window and is anything left on it to return? */
    /** The return days count from when the goods were received, not from when the memo was made (spec ch 12). */
    public function windowStart(Invoice $invoice): ?Carbon
    {
        return $invoice->delivered_at ?? $invoice->finalised_at;
    }

    public function returnable(InvoiceLine $line): bool
    {
        $invoice = $line->invoice;

        return $invoice->status === 'final' && $this->windowStart($invoice) !== null
            && $this->windowStart($invoice)->gte(now()->subDays($this->windowDays())->startOfDay())
            && (float) $line->stock_units - $this->returnedUnits($line) > 0.0001 && ! $line->is_outside;
    }

    /**
     * Ask for a return against a finished memo. Only the admin may go outside the window.
     *
     * @param  list<array{line_id: int, unit: string, quantity: float}>  $items
     *
     * @throws StockException
     */
    public function request(Invoice $invoice, array $items, string $reasonCode, string $reason, string $resolution, User $by, ?float $expectedMoney = null, ?string $voicePath = null): SaleReturn
    {
        if ($invoice->status !== 'final') {
            throw new StockException('Only a finished memo can have a return.');
        }

        $customer = $invoice->customer;

        if ($customer && ! in_array($customer->account_type, [AccountType::Paikari, AccountType::Public], true)) {
            throw new StockException('Returns are only for shop customers and retail sales.');
        }

        if (! in_array($resolution, ['credit', 'exchange', 'cash'], true)) {
            throw new StockException('Pick credit, exchange or cash.');
        }

        if ($resolution === 'credit' && ! $customer) {
            throw new StockException('A retail sale has no account to credit. Use exchange or cash.');
        }

        if (trim($reason) === '') {
            throw new StockException('Give a reason for the return.');
        }

        if ($items === []) {
            throw new StockException('Pick what is being returned.');
        }

        $start = $this->windowStart($invoice);
        $outsideWindow = $start === null || $start->lt(now()->subDays($this->windowDays())->startOfDay());
        if ($outsideWindow && ! $by->can('approve-returns')) {
            throw new StockException('The return period for this memo is over.');
        }

        return DB::transaction(function () use ($invoice, $items, $reasonCode, $reason, $resolution, $by, $expectedMoney, $voicePath, $customer) {
            $return = SaleReturn::create([
                'customer_id' => $customer?->id, 'invoice_id' => $invoice->id, 'kind' => $customer ? ($customer->account_type === AccountType::Paikari ? 'paikari' : 'public') : 'retail', 'resolution' => $resolution,
                'reason_code' => in_array($reasonCode, self::REASONS, true) ? $reasonCode : 'other', 'reason' => mb_substr($reason, 0, 500),
                'voice_path' => $voicePath, 'expected_money' => $expectedMoney, 'created_by' => $by->id,
            ]);

            $total = 0.0;

            foreach ($items as $item) {
                $line = InvoiceLine::where('invoice_id', $invoice->id)->whereNull('removed_at')->find($item['line_id']);

                if (! $line) {
                    throw new StockException('That item is not on this memo.');
                }

                $unit = $item['unit'];
                $qty = (float) $item['quantity'];

                if (! in_array($unit, InvoiceService::UNITS, true) || $qty <= 0) {
                    throw new StockException('The quantity must be more than zero.');
                }

                $medicine = $line->medicine;
                $stockUnits = round($qty * InvoiceService::fraction($medicine, $unit), 4);

                if ($stockUnits > (float) $line->stock_units - $this->returnedUnits($line) + 0.0001) {
                    throw new StockException('That is more than was bought and not yet returned.');
                }

                // At the price they paid: the line price is per line unit, so scale it to the returned unit.
                $perUnit = round((float) $line->unit_price * InvoiceService::fraction($medicine, $unit) / InvoiceService::fraction($medicine, $line->unit), 4);
                $amount = round($perUnit * $qty, 2);
                $total += $amount;

                SaleReturnLine::create([
                    'sale_return_id' => $return->id, 'invoice_line_id' => $line->id, 'medicine_id' => $medicine->id, 'unit' => $unit, 'quantity' => $qty,
                    'stock_units' => $stockUnits, 'unit_price' => round($perUnit, 2), 'amount' => $amount, 'was_offer' => $line->offer_product_id !== null,
                ]);
            }

            $return->update(['amount' => round($total, 2)]);

            return $return->fresh('lines');
        });
    }

    /**
     * Return without a memo (rare, admin only): the price is typed by hand and the return carries its own mark.
     * The goods cannot go back to a lot, so no stock moves.
     */
    public function requestWithoutInvoice(Medicine $medicine, string $unit, float $quantity, float $price, string $reasonCode, string $reason, string $resolution, User $by, ?User $customer = null): SaleReturn
    {
        if (! $by->can('approve-returns')) {
            throw new StockException('Only the admin can take a return without a memo.');
        }

        if (trim($reason) === '' || $quantity <= 0 || $price <= 0) {
            throw new StockException('Give a reason, a quantity and a price.');
        }

        if ($resolution === 'credit' && ! $customer) {
            throw new StockException('A retail sale has no account to credit. Use exchange or cash.');
        }

        return DB::transaction(function () use ($medicine, $unit, $quantity, $price, $reasonCode, $reason, $resolution, $by, $customer) {
            $amount = round($price * $quantity, 2);
            $return = SaleReturn::create([
                'customer_id' => $customer?->id, 'kind' => $customer ? 'paikari' : 'retail', 'resolution' => $resolution, 'without_invoice' => true,
                'reason_code' => in_array($reasonCode, self::REASONS, true) ? $reasonCode : 'other', 'reason' => mb_substr($reason, 0, 500), 'amount' => $amount, 'created_by' => $by->id,
            ]);
            SaleReturnLine::create([
                'sale_return_id' => $return->id, 'medicine_id' => $medicine->id, 'unit' => $unit, 'quantity' => $quantity,
                'stock_units' => round($quantity * InvoiceService::fraction($medicine, $unit), 4), 'unit_price' => $price, 'amount' => $amount,
            ]);

            return $return->fresh('lines');
        });
    }

    /** The admin checks the goods and approves: stock goes back to its own lot, credit or cash is worked out. */
    public function approve(SaleReturn $return, User $by, ?string $fault = null, ?string $note = null): SaleReturn
    {
        if (! $by->can('approve-returns')) {
            throw StockException::adminOnly();
        }

        if ($return->status !== 'requested') {
            throw new StockException('This return has already been decided.');
        }

        return DB::transaction(function () use ($return, $by, $fault, $note) {
            $return = SaleReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $amount = (float) $return->amount;
            $credit = in_array($return->resolution, ['credit', 'exchange'], true) ? $amount : 0.0;
            $cash = $return->resolution === 'cash' ? round($amount * $this->cashPercent() / 100, 2) : 0.0;

            foreach ($return->lines as $line) {
                if ($line->invoice_line_id) {
                    $this->stock->returnUnits($line->invoice_line_id, (float) $line->stock_units, $by);
                }
            }

            if ($credit > 0 && $return->customer_id) {
                CreditEntry::create(['user_id' => $return->customer_id, 'amount' => $credit, 'reason' => 'return', 'sale_return_id' => $return->id]);
            } else {
                $credit = 0.0; // retail: no account, an exchange or a refund is handled by hand at the counter
            }

            $return->update([
                'status' => 'approved', 'credit_amount' => $credit, 'cash_amount' => $cash, 'fault' => in_array($fault, ['staff', 'shop'], true) ? $fault : null,
                'decision_note' => $note, 'decided_by' => $by->id, 'decided_at' => now(),
            ]);

            $this->warnAboutMistakes($return->fresh('invoice'));

            return $return->fresh('lines');
        });
    }

    public function reject(SaleReturn $return, User $by, ?string $note = null): SaleReturn
    {
        if (! $by->can('approve-returns')) {
            throw StockException::adminOnly();
        }

        if ($return->status !== 'requested') {
            throw new StockException('This return has already been decided.');
        }

        $return->update(['status' => 'rejected', 'decision_note' => $note, 'decided_by' => $by->id, 'decided_at' => now()]);

        return $return->fresh();
    }

    /**
     * Mistakes (spec ch 12): a staff fault warns the staff member who made the sale; a shop fault warns the shop.
     * The same reason from the same shop three times in 90 days sends the "happening repeatedly" warning by itself.
     */
    private function warnAboutMistakes(SaleReturn $return): void
    {
        if ($return->fault === 'staff' && $return->invoice?->finalised_by) {
            Notice::create(['user_id' => $return->invoice->finalised_by, 'kind' => 'staff_mistake', 'body' => 'Return '.$return->id]);
        }

        if ($return->fault === 'shop' && $return->customer_id) {
            Notice::create(['user_id' => $return->customer_id, 'kind' => 'shop_mistake', 'body' => $return->reason_code]);

            $times = SaleReturn::where('customer_id', $return->customer_id)->where('status', 'approved')->where('fault', 'shop')
                ->where('reason_code', $return->reason_code)->where('decided_at', '>=', now()->subDays(90))->count();

            if ($times >= 3) {
                Notice::create(['user_id' => $return->customer_id, 'kind' => 'repeat_mistake', 'body' => $return->reason_code]);
            }
        }
    }

    /**
     * Take any credit off a memo being finished ("Return product", spec ch 12). Returns the amount taken. Credit is
     * only spent in the sector it was earned in; retail has no account so it never applies.
     */
    public function spendCredit(Invoice $invoice, float $total): float
    {
        if (! $invoice->customer_id || $total <= 0) {
            return 0.0;
        }

        $balance = $this->creditBalance($invoice->customer, 'pharmacy');
        $take = round(min($balance, $total), 2);

        if ($take > 0) {
            CreditEntry::create(['user_id' => $invoice->customer_id, 'amount' => -$take, 'reason' => 'memo', 'invoice_id' => $invoice->id]);
        }

        return $take;
    }
}
