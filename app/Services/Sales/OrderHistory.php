<?php

namespace App\Services\Sales;

use App\Models\CustomOrder;
use App\Models\FoodOrder;
use App\Models\GameOrder;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\User;

/**
 * Everything a buyer has ordered, from every part of the site, in one list (spec ch 13: "order history, profile"). Each row links to that order's own
 * page by its private link. Only the buyer's own orders appear, and only words every buyer may see (spec ch 5).
 */
class OrderHistory
{
    public const LIMIT = 60;

    public function __construct(private InvoiceService $invoices) {}

    /** @return array<int, array{kind: string, title: string, number: ?string, state: string, total: float, at: string, link: string}> newest first */
    public function for(User $user): array
    {
        $rows = collect();

        Invoice::where('customer_id', $user->id)->where('channel', 'online')->where('kind', 'retail')->whereNotNull('public_token')->latest('id')->limit(self::LIMIT)->get()
            ->each(fn (Invoice $i) => $rows->push([
                'kind' => 'medicine', 'title' => 'Medicine order', 'number' => $i->number, 'total' => $this->invoices->totals($i)['total'],
                'state' => $i->status === 'cancelled' ? 'cancelled' : ($i->completed_at !== null ? 'done' : 'open'), 'at' => ($i->placed_at ?? $i->created_at)->toIso8601String(), 'link' => '/orders/'.$i->public_token,
            ]));

        MarketOrder::where('buyer_id', $user->id)->latest('id')->limit(self::LIMIT)->get()
            ->each(fn (MarketOrder $o) => $rows->push([
                'kind' => 'market', 'title' => $o->title, 'number' => $o->number(), 'total' => (float) $o->total,
                'state' => $o->status === 'delivered' ? 'done' : (in_array($o->status, ['cancelled', 'returned'], true) ? 'cancelled' : 'open'), 'at' => $o->created_at->toIso8601String(), 'link' => '/market/orders/'.$o->public_token,
            ]));

        FoodOrder::where('buyer_id', $user->id)->with('restaurant:id,name')->latest('id')->limit(self::LIMIT)->get()
            ->each(fn (FoodOrder $o) => $rows->push([
                'kind' => 'food', 'title' => $o->restaurant?->name ?? 'Food', 'number' => $o->number(), 'total' => (float) $o->total,
                'state' => $o->status === 'delivered' ? 'done' : ($o->status === 'cancelled' ? 'cancelled' : 'open'), 'at' => $o->created_at->toIso8601String(), 'link' => '/food/orders/'.$o->public_token,
            ]));

        GameOrder::where('buyer_id', $user->id)->latest('id')->limit(self::LIMIT)->get()
            ->each(fn (GameOrder $o) => $rows->push([
                'kind' => 'game', 'title' => $o->game_name.' · '.$o->package_name, 'number' => $o->number(), 'total' => (float) $o->price,
                'state' => $o->status === 'topped_up' ? 'done' : (in_array($o->status, ['cancelled', 'failed'], true) ? 'cancelled' : 'open'), 'at' => $o->created_at->toIso8601String(), 'link' => '/gaming/orders/'.$o->public_token,
            ]));

        CustomOrder::where('buyer_id', $user->id)->latest('id')->limit(self::LIMIT)->get()
            ->each(fn (CustomOrder $o) => $rows->push([
                'kind' => 'custom', 'title' => $o->kind === 'wood' ? 'Wood, made to measure' : 'Clothing, made to measure', 'number' => $o->number(), 'total' => (float) ($o->price ?? 0),
                'state' => $o->status === 'collected' ? 'done' : ($o->status === 'cancelled' ? 'cancelled' : 'open'), 'at' => $o->created_at->toIso8601String(), 'link' => '/custom/'.$o->public_token,
            ]));

        return $rows->sortByDesc('at')->take(self::LIMIT)->values()->all();
    }
}
