<?php

namespace App\Services\Ledger;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The credit ledger, customers' side (baki khata, spec ch 28 note 12 and ch 06): what each customer still owes us on finished memos left Due,
 * their statement, and taking a payment. A payment fills the oldest memo first, and every part of it is kept as a row against its memo.
 * A memo sold with no customer on it has nobody to chase, so it is counted apart and never listed under a name.
 */
class Receivables
{
    public const METHODS = ['cash', 'bkash', 'nagad', 'rocket', 'bank', 'card'];

    public function __construct(private ActivityLogger $activity) {}

    /** A customer whose oldest unpaid memo is older than this many days is late (a setting). */
    public function overdueDays(): int
    {
        return max(1, (int) Setting::read('ledger.overdue_days', 30));
    }

    private function open()
    {
        return Invoice::query()->where('status', 'final')->whereColumn('paid_amount', '<', 'total');
    }

    public function totalOwed(): float
    {
        return round((float) $this->open()->whereNotNull('customer_id')->selectRaw('sum(total - paid_amount) as owed')->value('owed'), 2);
    }

    /** What one customer still owes us on finished memos. */
    public function owedBy(User $customer): float
    {
        return round((float) $this->open()->where('customer_id', $customer->id)->selectRaw('sum(total - paid_amount) as owed')->value('owed'), 2);
    }

    /** What was left Due with no customer on the memo: it cannot be chased from here, only from All orders. @return array{count: int, owed: float} */
    public function withoutCustomer(): array
    {
        $row = $this->open()->whereNull('customer_id')->selectRaw('count(*) as n, sum(total - paid_amount) as owed')->first();

        return ['count' => (int) ($row->n ?? 0), 'owed' => round((float) ($row->owed ?? 0), 2)];
    }

    /**
     * The customers who owe us, the biggest debt and the oldest first.
     *
     * @return Collection<int, array{id: int, name: string, phone: ?string, owed: float, memos: int, oldest_days: int, late: bool}>
     */
    public function owing(string $q = ''): Collection
    {
        $like = '%'.addcslashes($q, '%_\\').'%';

        $rows = $this->open()->whereNotNull('customer_id')
            ->when($q !== '', fn ($w) => $w->whereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like)))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, sum(total - paid_amount) as owed, count(*) as memos, min(coalesce(finalised_at, created_at)) as oldest')
            ->get();

        $people = User::whereIn('id', $rows->pluck('customer_id'))->get(['id', 'name', 'phone'])->keyBy('id');
        $limit = $this->overdueDays();

        return $rows->map(function ($r) use ($people, $limit) {
            $days = (int) floor(now()->diffInDays($r->oldest, true));

            return [
                'id' => (int) $r->customer_id, 'name' => $people[$r->customer_id]->name ?? '', 'phone' => $people[$r->customer_id]->phone ?? null,
                'owed' => round((float) $r->owed, 2), 'memos' => (int) $r->memos, 'oldest_days' => $days, 'late' => $days > $limit,
            ];
        })->sortByDesc('owed')->values();
    }

    /** How many customers are late and how much they owe, for the owner's home screen. @return array{customers: int, owed: float} */
    public function late(): array
    {
        $late = $this->owing()->where('late', true);

        return ['customers' => $late->count(), 'owed' => round((float) $late->sum('owed'), 2)];
    }

    /**
     * One customer's account: the memos still unpaid, oldest first, and what they paid and when.
     *
     * @return array{owed: float, open: list<array<string, mixed>>, payments: list<array<string, mixed>>}
     */
    public function statement(User $customer): array
    {
        $open = $this->open()->where('customer_id', $customer->id)->orderByRaw('coalesce(finalised_at, created_at)')->orderBy('id')->get();
        $limit = $this->overdueDays();

        $payments = InvoicePayment::where('customer_id', $customer->id)->with(['invoice:id,number', 'receiver:id,name'])->latest('received_at')->latest('id')->limit(100)->get();

        return [
            'owed' => round((float) $open->sum(fn (Invoice $i) => (float) $i->total - (float) $i->paid_amount), 2),
            'open' => $open->map(function (Invoice $i) use ($limit) {
                $days = (int) floor(now()->diffInDays($i->finalised_at ?? $i->created_at, true));

                return [
                    'id' => $i->id, 'number' => $i->number, 'when' => ($i->finalised_at ?? $i->created_at)?->toIso8601String(), 'total' => (float) $i->total,
                    'paid' => (float) $i->paid_amount, 'due' => round((float) $i->total - (float) $i->paid_amount, 2), 'days' => $days, 'late' => $days > $limit,
                ];
            })->values()->all(),
            'payments' => $payments->map(fn (InvoicePayment $p) => [
                'id' => $p->id, 'memo' => $p->invoice?->number, 'amount' => (float) $p->amount, 'method' => $p->method, 'note' => $p->note,
                'when' => $p->received_at->toIso8601String(), 'by' => $p->receiver?->name,
            ])->all(),
        ];
    }

    /**
     * A payment from a customer, put against their oldest unpaid memos first. It may not be more than they owe.
     *
     * @return array{applied: list<array{memo: ?string, amount: float}>}
     *
     * @throws StockException
     */
    public function receive(User $customer, float $amount, string $method, ?string $note, User $by): array
    {
        $this->check($amount, $method);

        $applied = DB::transaction(function () use ($customer, $amount, $method, $note, $by) {
            $memos = $this->open()->where('customer_id', $customer->id)->orderByRaw('coalesce(finalised_at, created_at)')->orderBy('id')->lockForUpdate()->get();
            $owed = round((float) $memos->sum(fn (Invoice $i) => (float) $i->total - (float) $i->paid_amount), 2);

            if ($owed <= 0) {
                throw new StockException('This customer owes nothing.');
            }

            if ($amount > $owed + 0.001) {
                throw new StockException('That is more than is owed.');
            }

            $left = round($amount, 2);
            $applied = [];
            foreach ($memos as $memo) {
                if ($left <= 0) {
                    break;
                }

                $part = min($left, round((float) $memo->total - (float) $memo->paid_amount, 2));
                $this->put($memo, $part, $method, $note, $by);
                $applied[] = ['memo' => $memo->number, 'amount' => $part];
                $left = round($left - $part, 2);
            }

            return $applied;
        });

        $this->activity->record('ledger.payment', $customer, ['amount' => round($amount, 2), 'method' => $method, 'memos' => count($applied)], $by);

        return ['applied' => $applied];
    }

    /**
     * A payment on one memo that was left Due. Never past what the memo still owes.
     *
     * @throws StockException
     */
    public function receiveOnInvoice(Invoice $invoice, float $amount, string $method, ?string $note, User $by): InvoicePayment
    {
        $this->check($amount, $method);

        return DB::transaction(function () use ($invoice, $amount, $method, $note, $by) {
            $memo = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($memo->status !== 'final') {
                throw new StockException('Only a finished memo can take a payment.');
            }

            $owed = round((float) $memo->total - (float) $memo->paid_amount, 2);
            if ($owed <= 0) {
                throw new StockException('This memo is already paid.');
            }

            if ($amount > $owed + 0.001) {
                throw new StockException('That is more than is owed.');
            }

            return $this->put($memo, round($amount, 2), $method, $note, $by);
        });
    }

    private function put(Invoice $memo, float $amount, string $method, ?string $note, User $by): InvoicePayment
    {
        $owedBefore = round((float) $memo->total - (float) $memo->paid_amount, 2);
        $memo->update(['paid_amount' => round((float) $memo->paid_amount + $amount, 2)]);
        $this->activity->record('invoice.payment', $memo, ['amount' => $amount, 'method' => $method, 'owed_before' => $owedBefore], $by);

        return InvoicePayment::create([
            'invoice_id' => $memo->id, 'customer_id' => $memo->customer_id, 'amount' => $amount, 'method' => $method,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null, 'received_by' => $by->id, 'received_at' => now(),
        ]);
    }

    /** @throws StockException */
    private function check(float $amount, string $method): void
    {
        if ($amount <= 0) {
            throw new StockException('Write an amount above zero.');
        }

        if (! in_array($method, self::METHODS, true)) {
            throw new StockException('Unknown payment method.');
        }
    }
}
