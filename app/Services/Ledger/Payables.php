<?php

namespace App\Services\Ledger;

use App\Models\PurchaseInvoice;
use App\Models\StockSource;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The credit ledger, companies' side (spec ch 28 note 12): what we still owe each company or supplier and the day it is due.
 * A purchase is paid in full when entered unless the buyer says otherwise; what is left stays owed until it is paid here.
 * A payment fills the invoice that falls due first, and every part is kept as a row.
 */
class Payables
{
    public const METHODS = ['cash', 'bkash', 'nagad', 'rocket', 'bank', 'cheque'];

    public function __construct(private ActivityLogger $activity) {}

    private function open()
    {
        return PurchaseInvoice::query()->whereColumn('paid_amount', '<', 'total_cost');
    }

    public function totalOwed(): float
    {
        return round((float) $this->open()->selectRaw('sum(total_cost - paid_amount) as owed')->value('owed'), 2);
    }

    /**
     * Every source we owe money to, the most overdue first.
     *
     * @return Collection<int, array{id: int, name: string, type: string, owed: float, invoices: int, next_due: ?string, late_owed: float}>
     */
    public function owing(): Collection
    {
        $rows = $this->open()->groupBy('stock_source_id')->selectRaw(
            'stock_source_id, sum(total_cost - paid_amount) as owed, count(*) as invoices, min(due_date) as next_due, '
            .'sum(case when due_date is not null and due_date < ? then total_cost - paid_amount else 0 end) as late_owed',
            [today()->toDateString()],
        )->get();

        $sources = StockSource::whereIn('id', $rows->pluck('stock_source_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->stock_source_id, 'name' => $sources[$r->stock_source_id]->name ?? '', 'type' => $sources[$r->stock_source_id]->type ?? 'company',
            'owed' => round((float) $r->owed, 2), 'invoices' => (int) $r->invoices, 'next_due' => $r->next_due ? substr((string) $r->next_due, 0, 10) : null, 'late_owed' => round((float) $r->late_owed, 2),
        ])->sortBy([['late_owed', 'desc'], ['owed', 'desc']])->values();
    }

    /** What is past its due day, for the owner's home screen. @return array{invoices: int, owed: float} */
    public function late(): array
    {
        $q = $this->open()->whereNotNull('due_date')->whereDate('due_date', '<', today());

        return ['invoices' => (clone $q)->count(), 'owed' => round((float) (clone $q)->selectRaw('sum(total_cost - paid_amount) as owed')->value('owed'), 2)];
    }

    /**
     * One company's account: the invoices still unpaid (due soonest first) and what we paid it.
     *
     * @return array{owed: float, open: list<array<string, mixed>>, payments: list<array<string, mixed>>}
     */
    public function statement(StockSource $source): array
    {
        $open = $this->open()->where('stock_source_id', $source->id)->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('invoice_date')->orderBy('id')->get();

        $payments = SupplierPayment::where('stock_source_id', $source->id)->with(['purchase:id,invoice_number', 'payer:id,name'])->latest('paid_at')->latest('id')->limit(100)->get();

        return [
            'owed' => round((float) $open->sum(fn (PurchaseInvoice $p) => $p->owed()), 2),
            'open' => $open->map(fn (PurchaseInvoice $p) => [
                'id' => $p->id, 'number' => $p->invoice_number, 'date' => $p->invoice_date?->toDateString(), 'total' => (float) $p->total_cost, 'paid' => (float) $p->paid_amount,
                'owed' => $p->owed(), 'due_date' => $p->due_date?->toDateString(), 'late' => $p->due_date !== null && $p->due_date->lt(today()),
            ])->values()->all(),
            'payments' => $payments->map(fn (SupplierPayment $p) => [
                'id' => $p->id, 'invoice' => $p->purchase?->invoice_number, 'amount' => (float) $p->amount, 'method' => $p->method, 'note' => $p->note,
                'when' => $p->paid_at->toIso8601String(), 'by' => $p->payer?->name,
            ])->all(),
        ];
    }

    /**
     * A payment to a company, put against the invoice due first. It may not be more than we owe it.
     *
     * @return array{applied: list<array{invoice: ?string, amount: float}>}
     *
     * @throws StockException
     */
    public function pay(StockSource $source, float $amount, string $method, ?string $note, User $by): array
    {
        if ($amount <= 0) {
            throw new StockException('Write an amount above zero.');
        }

        if (! in_array($method, self::METHODS, true)) {
            throw new StockException('Unknown payment method.');
        }

        $applied = DB::transaction(function () use ($source, $amount, $method, $note, $by) {
            $invoices = $this->open()->where('stock_source_id', $source->id)->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('invoice_date')->orderBy('id')->lockForUpdate()->get();
            $owed = round((float) $invoices->sum(fn (PurchaseInvoice $p) => $p->owed()), 2);

            if ($owed <= 0) {
                throw new StockException('We owe this source nothing.');
            }

            if ($amount > $owed + 0.001) {
                throw new StockException('That is more than we owe.');
            }

            $left = round($amount, 2);
            $applied = [];
            foreach ($invoices as $invoice) {
                if ($left <= 0) {
                    break;
                }

                $part = min($left, $invoice->owed());
                $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + $part, 2)]);
                SupplierPayment::create([
                    'purchase_invoice_id' => $invoice->id, 'stock_source_id' => $source->id, 'amount' => $part, 'method' => $method,
                    'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null, 'paid_by' => $by->id, 'paid_at' => now(),
                ]);
                $applied[] = ['invoice' => $invoice->invoice_number, 'amount' => $part];
                $left = round($left - $part, 2);
            }

            return $applied;
        });

        $this->activity->record('payables.payment', $source, ['amount' => round($amount, 2), 'method' => $method, 'invoices' => count($applied)], $by);

        return ['applied' => $applied];
    }
}
