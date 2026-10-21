<?php

namespace App\Services\Admin;

use App\Models\CustomOrder;
use App\Models\FoodOrder;
use App\Models\GameOrder;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Payment;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shop as a whole, for the admin dashboard: what was sold in the last thirty days across the pharmacy, the market, food, game top-ups and
 * made-to-order work, set against the thirty days before. Cancelled and failed orders never count as sales. Everything is read live, in a handful of
 * grouped queries, and cached for a minute so a busy dashboard does not repeat them.
 */
class CommerceStats
{
    public const DAYS = 30;

    /** key => label (the page translates the label). */
    public const SOURCES = ['pharmacy' => 'Pharmacy', 'market' => 'Market', 'food' => 'Food', 'games' => 'Game top-up', 'custom' => 'Made to order'];

    /** @return array<string, mixed> */
    public function all(): array
    {
        return cache()->remember('dashboard.commerce', 60, fn () => $this->build());
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        $to = now()->endOfDay();
        $from = now()->subDays(self::DAYS - 1)->startOfDay();
        $prevFrom = $from->copy()->subDays(self::DAYS);

        // Each source: a query that yields (day, orders, money) for sales that count, between two moments.
        $rows = fn (string $source, Carbon $a, Carbon $b) => $this->daily($source, $a, $b);

        $days = [];
        for ($d = $from->copy(); $d <= $to; $d->addDay()) {
            $days[$d->toDateString()] = array_fill_keys(array_keys(self::SOURCES), 0.0) + ['orders' => 0];
        }

        $totals = array_fill_keys(array_keys(self::SOURCES), ['money' => 0.0, 'orders' => 0]);
        $prev = ['money' => 0.0, 'orders' => 0];

        foreach (array_keys(self::SOURCES) as $source) {
            foreach ($rows($source, $from, $to) as $r) {
                if (isset($days[$r->day])) {
                    $days[$r->day][$source] += (float) $r->money;
                    $days[$r->day]['orders'] += (int) $r->orders;
                }
                $totals[$source]['money'] += (float) $r->money;
                $totals[$source]['orders'] += (int) $r->orders;
            }
            foreach ($rows($source, $prevFrom, $from->copy()->subSecond()) as $r) {
                $prev['money'] += (float) $r->money;
                $prev['orders'] += (int) $r->orders;
            }
        }

        $money = round(collect($totals)->sum('money'), 2);
        $orders = (int) collect($totals)->sum('orders');
        $newNow = User::where('created_at', '>=', $from)->count();
        $newBefore = User::whereBetween('created_at', [$prevFrom, $from->copy()->subSecond()])->count();

        return [
            'days' => self::DAYS,
            'kpis' => [
                'revenue' => ['value' => $money, 'was' => round($prev['money'], 2)],
                'orders' => ['value' => $orders, 'was' => $prev['orders']],
                'average' => ['value' => $orders > 0 ? round($money / $orders, 2) : 0.0, 'was' => $prev['orders'] > 0 ? round($prev['money'] / $prev['orders'], 2) : 0.0],
                'customers' => ['value' => $newNow, 'was' => $newBefore],
            ],
            'series' => collect($days)->map(fn ($v, $day) => ['day' => $day, 'orders' => $v['orders']] + collect(array_keys(self::SOURCES))->mapWithKeys(fn ($s) => [$s => round($v[$s], 2)])->all())->values()->all(),
            'sources' => collect(self::SOURCES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'money' => round($totals[$key]['money'], 2), 'orders' => $totals[$key]['orders']])->values()->all(),
            'categories' => $this->categories($from, $to),
            'pipeline' => $this->pipeline($from),
            'payment' => $this->payment($from),
            'recent' => $this->recent(),
            'open' => [
                'tickets' => SupportTicket::where('status', 'open')->count(),
                'listings_waiting' => Listing::where('status', 'pending')->count(),
                'refunds_due' => Payment::where('status', 'refund_due')->count(),
            ],
        ];
    }

    /** @return Collection<int, object{day: string, orders: int, money: float}> */
    private function daily(string $source, Carbon $a, Carbon $b)
    {
        [$table, $money, $at, $where] = match ($source) {
            'pharmacy' => ['invoices', 'total', 'created_at', fn ($q) => $q->whereNull('cancelled_at')->whereIn('status', ['verified', 'final'])],
            'market' => ['market_orders', 'total', 'created_at', fn ($q) => $q->where('status', '!=', 'cancelled')->where(fn ($w) => $w->where('pay_by', 'cod')->orWhereNotNull('paid_at'))],
            'food' => ['food_orders', 'total', 'created_at', fn ($q) => $q->where('status', '!=', 'cancelled')],
            'games' => ['game_orders', 'price', 'created_at', fn ($q) => $q->whereNotIn('status', ['failed', 'cancelled'])],
            default => ['custom_orders', 'price', 'created_at', fn ($q) => $q->whereNull('cancelled_at')->whereNotNull('price')],
        };

        return DB::table($table)->tap($where)->whereBetween($at, [$a, $b])
            ->selectRaw("date({$at}) as day, count(*) as orders, coalesce(sum({$money}), 0) as money")->groupBy('day')->get();
    }

    /** What sold best among the market's kinds of business, and the pharmacy beside them. @return list<array{name: string, money: float}> */
    private function categories(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('market_orders')->join('listings', 'listings.id', '=', 'market_orders.listing_id')->join('sectors', 'sectors.id', '=', 'listings.sector_id')
            ->where('market_orders.status', '!=', 'cancelled')->whereBetween('market_orders.created_at', [$from, $to])
            ->selectRaw('sectors.name as name, sectors.name_bn as name_bn, sum(market_orders.total) as money')->groupBy('sectors.id', 'sectors.name', 'sectors.name_bn')->orderByDesc('money')->limit(6)->get()
            ->map(fn ($r) => ['name' => $r->name, 'name_bn' => $r->name_bn, 'money' => round((float) $r->money, 2)]);

        return $rows->all();
    }

    /** Where orders are in their journey, in four plain steps, for the last thirty days. @return array{waiting: int, moving: int, done: int, cancelled: int} */
    private function pipeline(Carbon $from): array
    {
        $out = ['waiting' => 0, 'moving' => 0, 'done' => 0, 'cancelled' => 0];
        $map = [
            ['invoices', ['placed' => 'waiting', 'verified' => 'moving', 'final' => 'done'], 'cancelled_at'],
            ['market_orders', ['placed' => 'waiting', 'accepted' => 'moving', 'shipped' => 'moving', 'delivered' => 'done'], null],
            ['food_orders', ['placed' => 'waiting', 'confirmed' => 'moving', 'cooking' => 'moving', 'ready' => 'moving', 'out' => 'moving', 'delivered' => 'done'], null],
        ];

        foreach ($map as [$table, $steps, $cancelCol]) {
            $q = DB::table($table)->where('created_at', '>=', $from);
            $rows = (clone $q)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

            foreach ($rows as $status => $n) {
                $out[$steps[$status] ?? ($status === 'cancelled' ? 'cancelled' : 'moving')] += (int) $n;
            }

            if ($cancelCol) {
                $out['cancelled'] += (clone $q)->whereNotNull($cancelCol)->count();
            }
        }

        return $out;
    }

    /** Cash on delivery against paid online, in money. @return array{online: float, cash: float} */
    private function payment(Carbon $from): array
    {
        $market = DB::table('market_orders')->where('status', '!=', 'cancelled')->where('created_at', '>=', $from)->selectRaw("coalesce(sum(case when pay_by = 'online' then total else 0 end), 0) as online, coalesce(sum(case when pay_by = 'online' then 0 else total end), 0) as cash")->first();
        $shop = DB::table('invoices')->whereNull('cancelled_at')->whereIn('status', ['verified', 'final'])->where('created_at', '>=', $from)->selectRaw("coalesce(sum(case when payment_method = 'online' then total else 0 end), 0) as online, coalesce(sum(case when payment_method = 'online' then 0 else total end), 0) as cash")->first();

        return ['online' => round((float) $market->online + (float) $shop->online, 2), 'cash' => round((float) $market->cash + (float) $shop->cash, 2)];
    }

    /** The latest orders from the pharmacy and the market, newest first. @return list<array<string, mixed>> */
    private function recent(): array
    {
        $shop = Invoice::where('channel', 'online')->latest('id')->limit(6)->get()->map(fn (Invoice $i) => ['source' => 'pharmacy', 'label' => $i->number ?? 'ORD'.$i->id, 'total' => (float) $i->total, 'status' => $i->cancelled_at ? 'cancelled' : $i->status, 'at' => $i->created_at->toIso8601String(), 'href' => '/admin/orders/'.$i->id]);
        $market = MarketOrder::latest('id')->limit(6)->get()->map(fn (MarketOrder $o) => ['source' => 'market', 'label' => $o->title, 'total' => (float) $o->total, 'status' => $o->status, 'at' => $o->created_at->toIso8601String(), 'href' => null]);
        $food = FoodOrder::latest('id')->limit(3)->get()->map(fn (FoodOrder $o) => ['source' => 'food', 'label' => 'F'.$o->id, 'total' => (float) $o->total, 'status' => $o->status, 'at' => $o->created_at->toIso8601String(), 'href' => null]);
        $games = GameOrder::latest('id')->limit(3)->get()->map(fn (GameOrder $o) => ['source' => 'games', 'label' => $o->game_name.' · '.$o->package_name, 'total' => (float) $o->price, 'status' => $o->status, 'at' => $o->created_at->toIso8601String(), 'href' => null]);
        $custom = CustomOrder::latest('id')->limit(3)->get()->map(fn (CustomOrder $o) => ['source' => 'custom', 'label' => $o->material_name ?? 'C'.$o->id, 'total' => (float) $o->price, 'status' => $o->status, 'at' => $o->created_at->toIso8601String(), 'href' => null]);

        return $shop->concat($market)->concat($food)->concat($games)->concat($custom)->sortByDesc('at')->take(8)->values()->all();
    }
}
