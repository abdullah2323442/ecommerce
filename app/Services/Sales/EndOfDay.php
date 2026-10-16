<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\QuickNote;
use App\Models\StockMismatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What is still hanging when the shop closes (spec 6.6, "End of day"): open drafts by the person who opened them, orders nobody
 * has picked up, requests waiting for an answer, money not yet paid, and the cash not yet counted. Each person sees only the lines
 * their own role may act on; the money lines are for those who may read reports.
 */
class EndOfDay
{
    public function __construct(private ApprovalService $approvals, private PreOrders $preorders) {}

    /** @return array{items: list<array<string, mixed>>, clear: bool} */
    public function unresolved(User $by): array
    {
        $items = [];

        if ($by->can('use-counter')) {
            $drafts = Invoice::where('status', 'draft')->with('openedBy:id,name')->get()->groupBy(fn (Invoice $i) => $i->openedBy?->name ?? '');

            foreach ($drafts as $name => $group) {
                $items[] = [
                    'key' => 'drafts', 'who' => $name === '' ? null : $name, 'count' => $group->count(), 'href' => '/admin/counter',
                    'minutes' => (int) $group->min('opened_at')->diffInMinutes(now()),
                ];
            }

            $notes = QuickNote::whereNull('done_at')->count();
            if ($notes > 0) {
                $items[] = ['key' => 'notes', 'count' => $notes, 'href' => '/admin/counter'];
            }
        }

        if ($by->can('handle-orders')) {
            $waiting = Invoice::where('channel', 'online')->whereIn('status', ['placed', 'verified'])->count();
            if ($waiting > 0) {
                $items[] = ['key' => 'orders', 'count' => $waiting, 'href' => '/admin/orders'];
            }

            $pre = $this->preorders->attention();
            if ($pre > 0) {
                $items[] = ['key' => 'preorders', 'count' => $pre, 'href' => '/admin/preorders'];
            }
        }

        if ($by->can('decide-approvals') && ($n = $this->approvals->pendingCount()) > 0) {
            $items[] = ['key' => 'approvals', 'count' => $n, 'href' => '/admin/approvals'];
        }

        if ($by->can('manage-stock') && ($n = StockMismatch::where('status', 'open')->count()) > 0) {
            $items[] = ['key' => 'mismatches', 'count' => $n, 'href' => '/admin/stock/mismatches'];
        }

        if ($by->can('view-reports')) {
            $today = Invoice::where('status', 'final')->whereDate('finalised_at', today())->whereColumn('paid_amount', '<', 'total')
                ->selectRaw('count(*) as n, coalesce(sum(total - paid_amount), 0) as owed')->first();

            if ((int) $today->n > 0) {
                $items[] = ['key' => 'unpaid', 'count' => (int) $today->n, 'amount' => round((float) $today->owed, 2), 'href' => '/admin/day'];
            }

            if (! DB::table('day_closings')->where('day', today()->toDateString())->exists()) {
                $items[] = ['key' => 'cash', 'count' => 1, 'href' => '/admin/day'];
            }
        }

        return ['items' => $items, 'clear' => $items === []];
    }
}
