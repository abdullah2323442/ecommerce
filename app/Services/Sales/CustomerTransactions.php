<?php

namespace App\Services\Sales;

use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Preorder;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Everything one customer has done with the shop, newest first, on one list (spec ch 25: "all transactions on one page",
 * so the owner can talk to them on the phone without jumping between screens): orders and memos, returns, pre-orders and
 * credit moves.
 */
class CustomerTransactions
{
    public function __construct(private InvoiceService $invoices) {}

    /** @return Collection<int, array{type: string, id: int, when: string, title: string, status: string, amount: ?float, link: ?string}> */
    public function for(User $customer, int $limit = 60): Collection
    {
        $rows = collect();

        Invoice::where('customer_id', $customer->id)->where('status', '!=', 'draft')->latest('id')->limit($limit)->get()->each(function (Invoice $i) use ($rows) {
            $rows->push([
                'type' => 'memo', 'id' => $i->id, 'when' => ($i->finalised_at ?? $i->placed_at ?? $i->opened_at)->toIso8601String(),
                'title' => $i->number ?? '#'.$i->id, 'status' => $i->status, 'amount' => $this->invoices->totals($i)['total'],
                'link' => $i->channel === 'online' && $i->status !== 'final' ? "/admin/orders/{$i->id}" : "/admin/counter/{$i->id}",
                'detail' => trim($i->kind.' · '.$i->channel.($i->paid_amount < $i->total && $i->status === 'final' ? ' · due' : '')),
            ]);
        });

        SaleReturn::where('customer_id', $customer->id)->latest('id')->limit($limit)->get()->each(function (SaleReturn $r) use ($rows) {
            $rows->push([
                'type' => 'return', 'id' => $r->id, 'when' => $r->created_at->toIso8601String(), 'title' => '#'.$r->id, 'status' => $r->status,
                'amount' => (float) $r->amount, 'link' => '/admin/returns', 'detail' => $r->resolution,
            ]);
        });

        Preorder::where('customer_id', $customer->id)->with('lines.medicine:id,brand_name,strength')->latest('id')->limit($limit)->get()->each(function (Preorder $p) use ($rows) {
            $first = $p->lines->first();
            $rows->push([
                'type' => 'preorder', 'id' => $p->id, 'when' => $p->created_at->toIso8601String(), 'title' => $first ? trim($first->medicine->brand_name.' '.$first->medicine->strength) : '#'.$p->id,
                'status' => $p->status, 'amount' => null, 'link' => '/admin/preorders', 'detail' => '',
            ]);
        });

        CreditEntry::where('user_id', $customer->id)->latest('id')->limit($limit)->get()->each(function (CreditEntry $c) use ($rows) {
            $rows->push([
                'type' => 'credit', 'id' => $c->id, 'when' => $c->created_at->toIso8601String(), 'title' => $c->reason, 'status' => (float) $c->amount >= 0 ? 'added' : 'used',
                'amount' => (float) $c->amount, 'link' => null, 'detail' => $c->sector,
            ]);
        });

        return $rows->sortByDesc('when')->take($limit)->values();
    }
}
