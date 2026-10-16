<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\StockLot;
use App\Services\Stock\ReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The admin's monthly report (spec 6.9, ch 25): how each medicine moves, boxes needed per day, what stock should be,
 * what to order, company and customer rankings, profit ranking, month-to-month, day by day. Only finished memos
 * count; a corrected memo is cancelled, so it never counts twice.
 *
 * Profit is the price charged less the cost of the lot each sale came from, less any end discount. Sales that took
 * nothing from stock (Master Stock Sync off) have no lot cost, so they are in sales but not in profit.
 */
class SalesReports
{
    public function __construct(private ReturnService $returns) {}

    /** Per medicine over a period: units sold (boxes, or pieces for loose items), revenue, cost, profit. */
    public function perMedicine(Carbon $from, Carbon $to): Collection
    {
        return $this->movements($from, $to)->groupBy('sm.medicine_id', 'm.brand_name', 'm.strength', 'm.packaging_type', 'c.name', 'm.company_id')
            ->get([
                'sm.medicine_id as id', 'm.brand_name as name', 'm.strength', 'm.packaging_type as pack', 'c.name as company', 'm.company_id',
                DB::raw('sum(abs(sm.units)) as units'), DB::raw('sum(abs(sm.units) * sm.unit_price) as revenue'), DB::raw('sum(abs(sm.units) * sm.unit_cost) as cost'),
            ])->map(fn ($r) => [
                'id' => (int) $r->id, 'name' => $r->name, 'strength' => $r->strength, 'company' => $r->company, 'company_id' => (int) $r->company_id, 'pack' => $r->pack,
                'units' => round((float) $r->units, 2), 'revenue' => round((float) $r->revenue, 2), 'profit' => round((float) $r->revenue - (float) $r->cost, 2),
            ]);
    }

    /**
     * Demand and ordering for each medicine: boxes per day, the stock level it should have (lead days of sales plus a
     * cushion, settings `reports.lead_days` 7 and `reports.cushion_days` 3), what is on hand, and how many to order.
     * Two ways: buy more when it will run out inside the lead time; buy less when it is sitting (over `reports.slow_days`, 60).
     */
    public function ordering(Carbon $from, Carbon $to): Collection
    {
        $days = max(1, (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())) + 1);
        $lead = (int) Setting::read('reports.lead_days', 7);
        $cushion = (int) Setting::read('reports.cushion_days', 3);
        $slow = (int) Setting::read('reports.slow_days', 60);
        $onHand = StockLot::query()->selectRaw('medicine_id, sum(units_remaining) as units')->groupBy('medicine_id')->pluck('units', 'medicine_id');

        return $this->perMedicine($from, $to)->map(function (array $r) use ($days, $lead, $cushion, $slow, $onHand) {
            $perDay = $r['units'] / $days;
            $level = (int) ceil($perDay * ($lead + $cushion));
            $have = (float) ($onHand[$r['id']] ?? 0);
            $suggest = max(0, (int) ceil($level - $have));

            return $r + [
                'per_day' => round($perDay, 2), 'ideal_level' => $level, 'on_hand' => round($have, 2), 'order' => $suggest,
                'advice' => $suggest > 0 ? 'more' : ($perDay > 0 && $have > $perDay * $slow ? 'less' : null),
            ];
        })->sortByDesc('units')->values();
    }

    /**
     * The seasonal forecast (spec 6.9): "last year this sold a lot at this time". For the next `reports.season_days` (30) days of this year we look at
     * the same days last year, and set it against the last 30 days now. A medicine that sold a lot then, and is not yet selling that much, is
     * about to move: how many to have is last year's pace for the lead time plus the cushion, less what is on hand.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function seasonal(?Carbon $on = null): Collection
    {
        $on = ($on ?? now('Asia/Dhaka'))->copy()->startOfDay();
        $span = max(7, (int) Setting::read('reports.season_days', 30));
        $lead = (int) Setting::read('reports.lead_days', 7);
        $cushion = (int) Setting::read('reports.cushion_days', 3);
        $then = $this->perMedicine($on->copy()->subYear()->startOfDay(), $on->copy()->subYear()->addDays($span - 1)->endOfDay())->keyBy('id');
        $now = $this->perMedicine($on->copy()->subDays($span), $on->copy()->subDay()->endOfDay())->keyBy('id');
        $onHand = StockLot::query()->selectRaw('medicine_id, sum(units_remaining) as units')->groupBy('medicine_id')->pluck('units', 'medicine_id');

        return $then->map(function (array $r) use ($now, $onHand, $span, $lead, $cushion) {
            $thenPerDay = $r['units'] / $span;
            $nowPerDay = (($now[$r['id']]['units'] ?? 0)) / $span;
            $have = (float) ($onHand[$r['id']] ?? 0);

            return $r + [
                'then_per_day' => round($thenPerDay, 2), 'now_per_day' => round($nowPerDay, 2), 'on_hand' => round($have, 2),
                'rise' => $nowPerDay > 0 ? round($thenPerDay / $nowPerDay, 1) : null,
                'order' => max(0, (int) ceil($thenPerDay * ($lead + $cushion) - $have)),
            ];
        })
            // Worth saying: it sold a fair amount then, and it is selling clearly less now (or not at all).
            ->filter(fn (array $r) => $r['units'] >= 5 && $r['then_per_day'] > $r['now_per_day'] * 1.5)
            ->sortByDesc('units')->values();
    }

    /** Stock that is sitting: on hand, nothing sold in the period. Cheapest thing to leave off the next order. */
    public function notMoving(Carbon $from, Carbon $to, int $limit = 15): Collection
    {
        $sold = $this->perMedicine($from, $to)->pluck('id')->all();

        return StockLot::query()->join('medicines', 'medicines.id', '=', 'stock_lots.medicine_id')->where('stock_lots.units_remaining', '>', 0)->whereNotIn('stock_lots.medicine_id', $sold)
            ->groupBy('medicines.id', 'medicines.brand_name', 'medicines.strength')->orderByDesc(DB::raw('sum(stock_lots.units_remaining * stock_lots.unit_cost)'))->limit($limit)
            ->get(['medicines.brand_name as name', 'medicines.strength', DB::raw('sum(stock_lots.units_remaining) as units'), DB::raw('sum(stock_lots.units_remaining * stock_lots.unit_cost) as value')])
            ->map(fn ($r) => ['name' => $r->name, 'strength' => $r->strength, 'units' => round((float) $r->units, 2), 'value' => round((float) $r->value, 2)]);
    }

    public function companies(Carbon $from, Carbon $to): Collection
    {
        return $this->perMedicine($from, $to)->groupBy('company_id')->map(fn (Collection $g) => [
            'company' => $g->first()['company'], 'units' => round($g->sum('units'), 2), 'revenue' => round($g->sum('revenue'), 2), 'profit' => round($g->sum('profit'), 2),
        ])->sortByDesc('revenue')->values();
    }

    /** Who bought how much, and what Siam made from them. */
    public function customers(Carbon $from, Carbon $to, int $limit = 20): Collection
    {
        $rows = Invoice::query()->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])->whereNotNull('customer_id')->with('customer:id,name,phone,area')
            ->get()->groupBy('customer_id');

        $profit = $this->movements($from, $to)->whereNotNull('i.customer_id')->groupBy('i.customer_id')
            ->get(['i.customer_id', DB::raw('sum(abs(sm.units) * (sm.unit_price - sm.unit_cost)) as p')])->pluck('p', 'customer_id');

        return $rows->map(fn (Collection $g, $id) => [
            'id' => (int) $id, 'name' => $g->first()->customer?->name, 'phone' => $g->first()->customer?->phone, 'area' => $g->first()->customer?->area,
            'memos' => $g->count(), 'total' => round((float) $g->sum('total'), 2), 'profit' => round((float) ($profit[$id] ?? 0), 2),
        ])->sortByDesc('total')->take($limit)->values();
    }

    /** Customers who used to buy and have gone quiet (spec 6.9 "inactive customers"). */
    public function inactive(int $days = 30, int $limit = 15): Collection
    {
        $since = now()->subDays($days);

        return Invoice::query()->whereNotNull('completed_at')->whereNotNull('customer_id')->with('customer:id,name,phone,area')->get()->groupBy('customer_id')
            ->map(fn (Collection $g) => ['name' => $g->first()->customer?->name, 'phone' => $g->first()->customer?->phone, 'last' => $g->max('completed_at'), 'total' => round((float) $g->sum('total'), 2), 'memos' => $g->count()])
            ->filter(fn (array $r) => $r['last'] !== null && $r['last']->lt($since))->sortByDesc('total')->take($limit)
            ->map(fn (array $r) => ['name' => $r['name'], 'phone' => $r['phone'], 'last' => $r['last']->toDateString(), 'days' => (int) $r['last']->diffInDays(now()), 'total' => $r['total'], 'memos' => $r['memos']])->values();
    }

    /** One line of totals for a period, for month-to-month and year-on-year. */
    public function totals(Carbon $from, Carbon $to): array
    {
        $inv = Invoice::query()->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to]);
        $count = (clone $inv)->count();
        $sales = round((float) (clone $inv)->sum('total'), 2);
        $discount = round((float) (clone $inv)->sum('discount_total'), 2);
        $m = $this->movements($from, $to)->get([DB::raw('sum(abs(sm.units) * (sm.unit_price - sm.unit_cost)) as p')])->first();

        return ['memos' => $count, 'sales' => $sales, 'average' => $count ? round($sales / $count, 2) : 0.0, 'profit' => round((float) ($m->p ?? 0) - $discount, 2)];
    }

    /** Sales split by kind of sale and channel (spec ch 25: each sector separately). */
    public function bySector(Carbon $from, Carbon $to): Collection
    {
        return Invoice::query()->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])->selectRaw('kind, channel, count(*) as memos, sum(total) as total')
            ->groupBy('kind', 'channel')->orderBy('kind')->get()->map(fn ($r) => ['kind' => $r->kind, 'channel' => $r->channel, 'memos' => (int) $r->memos, 'total' => round((float) $r->total, 2)]);
    }

    /** Day by day, to find where a month-end mismatch is (spec ch 25). */
    public function days(Carbon $from, Carbon $to): Collection
    {
        $inv = Invoice::query()->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])->get(['completed_at', 'total', 'discount_total', 'credit_applied', 'paid_amount', 'payment_method']);
        $profit = $this->movements($from, $to)->groupBy(DB::raw('date(i.completed_at)'))->get([DB::raw('date(i.completed_at) as d'), DB::raw('sum(abs(sm.units) * (sm.unit_price - sm.unit_cost)) as p')])->pluck('p', 'd');
        $returns = SaleReturn::query()->where('status', 'approved')->whereBetween('decided_at', [$from, $to])->get(['decided_at', 'amount'])->groupBy(fn ($r) => $r->decided_at->toDateString());

        return $inv->groupBy(fn ($i) => $i->completed_at->toDateString())->map(fn (Collection $g, string $d) => [
            'day' => $d, 'memos' => $g->count(), 'sales' => round((float) $g->sum('total'), 2), 'discount' => round((float) $g->sum('discount_total'), 2),
            'credit' => round((float) $g->sum('credit_applied'), 2), 'cash' => round((float) $g->where('payment_method', 'cash')->sum('paid_amount'), 2), 'due' => round((float) $g->sum('total') - (float) $g->sum('paid_amount'), 2),
            'profit' => round((float) ($profit[$d] ?? 0) - (float) $g->sum('discount_total'), 2), 'returns' => round((float) ($returns[$d] ?? collect())->sum('amount'), 2),
        ])->sortKeys()->values();
    }

    /** The night summary: everything the owner wants on one screen (spec ch 25). */
    public function nightly(?Carbon $day = null): array
    {
        $day ??= now();
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $t = $this->totals($from, $to);

        return $t + [
            'cash_expected' => round((float) Invoice::where('status', 'final')->whereBetween('finalised_at', [$from, $to])->where('payment_method', 'cash')->sum('paid_amount'), 2),
            'open_drafts' => Invoice::where('status', 'draft')->count(),
            'returns' => SaleReturn::whereBetween('created_at', [$from, $to])->count(),
            'finished' => app(ReportService::class)->finishedToday($day)->count(),
            'low_stock' => app(ReportService::class)->lowStock()->count(),
            'pending_approvals' => app(ApprovalService::class)->pendingCount(),
        ];
    }

    /** Sale lines of finished memos for a period, one row each, for the accountant or a spreadsheet (spec ch 25). */
    public function exportRows(Carbon $from, Carbon $to): Collection
    {
        return DB::table('invoice_lines as il')->join('invoices as i', 'i.id', '=', 'il.invoice_id')->join('medicines as m', 'm.id', '=', 'il.medicine_id')->leftJoin('users as c', 'c.id', '=', 'i.customer_id')->leftJoin('users as s', 's.id', '=', 'i.finalised_by')
            ->whereNotNull('i.completed_at')->whereBetween('i.completed_at', [$from, $to])->whereNull('il.removed_at')->where('il.quantity', '>', 0)->orderBy('i.finalised_at')->orderBy('il.id')
            ->get(['i.number', 'i.finalised_at', 'i.kind', 'i.channel', 'c.name as customer', 's.name as staff', 'm.brand_name', 'm.strength', 'il.unit', 'il.quantity', 'il.unit_price', DB::raw('(il.quantity * il.unit_price) as line_total'), 'il.rate_layer', 'i.payment_method']);
    }

    public function stockUnitsLabel(Medicine $m): string
    {
        return $m->packaging_type === 'box' ? 'box' : 'piece';
    }

    /** Sale movements of finished memos in a period: the base of every profit figure. */
    private function movements(Carbon $from, Carbon $to)
    {
        return DB::table('stock_movements as sm')->join('invoice_lines as il', 'il.id', '=', 'sm.invoice_line_id')->join('invoices as i', 'i.id', '=', 'il.invoice_id')
            ->join('medicines as m', 'm.id', '=', 'sm.medicine_id')->leftJoin('companies as c', 'c.id', '=', 'm.company_id')
            ->where('sm.type', 'sale')->where('sm.reversed', false)->whereNotNull('i.completed_at')->whereBetween('i.completed_at', [$from, $to]);
    }
}
