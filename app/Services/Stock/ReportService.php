<?php

namespace App\Services\Stock;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\StockLot;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Stock and profit reports (spec 4.3.9): the low-stock alert, "what finished today" for the morning order,
 * and gross profit less shop expenses for net profit. Profit uses the cost of the lot each sale came from.
 */
class ReportService
{
    /**
     * Medicines at or under their reorder level, emptiest first. A medicine with a level and no stock at all
     * is the most urgent of all.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lowStock(): Collection
    {
        $onHand = StockLot::query()->selectRaw('medicine_id, sum(units_remaining) as units')->groupBy('medicine_id')->pluck('units', 'medicine_id');

        return Medicine::query()->active()->whereNotNull('reorder_level')->with('company:id,name')->get()
            ->map(fn (Medicine $m) => ['medicine' => $m, 'on_hand' => (float) ($onHand[$m->id] ?? 0)])
            ->filter(fn (array $r) => $r['on_hand'] <= (float) $r['medicine']->reorder_level)
            ->map(fn (array $r) => [
                'medicine_id' => $r['medicine']->id,
                'brand_name' => $r['medicine']->brand_name,
                'strength' => $r['medicine']->strength,
                'company' => $r['medicine']->company?->name,
                'on_hand' => $r['on_hand'],
                'reorder_level' => (float) $r['medicine']->reorder_level,
            ])
            ->sortBy('on_hand')->values();
    }

    /**
     * Medicines whose last unit went today: for the morning ordering list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function finishedToday(?Carbon $today = null): Collection
    {
        $today ??= now();
        $touched = StockMovement::query()->whereIn('type', ['sale', 'expired', 'count', 'correction'])
            ->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])->pluck('medicine_id')->unique();

        $onHand = StockLot::query()->whereIn('medicine_id', $touched)->selectRaw('medicine_id, sum(units_remaining) as units')->groupBy('medicine_id')->pluck('units', 'medicine_id');

        return Medicine::query()->whereIn('id', $touched)->with('company:id,name')->get()
            ->filter(fn (Medicine $m) => (float) ($onHand[$m->id] ?? 0) <= 0 && StockLot::where('medicine_id', $m->id)->exists())
            ->map(fn (Medicine $m) => ['medicine_id' => $m->id, 'brand_name' => $m->brand_name, 'strength' => $m->strength, 'company' => $m->company?->name])
            ->values();
    }

    /**
     * Sales profit for a month: what was charged less the cost of the lots sold. Loss sales are included and counted separately.
     * An end discount on a memo comes off the memo as a whole, not off a line, so it is taken off here too: without that a sale
     * the owner pushed below cost with a 30% end discount would look like a profit.
     */
    public function gross(Carbon $month): array
    {
        $sales = StockMovement::query()->where('type', 'sale')->where('reversed', false)
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->get(['invoice_line_id', 'units', 'unit_cost', 'unit_price', 'is_loss']);

        $revenue = 0.0;
        $cost = 0.0;
        $lossValue = 0.0;
        $perInvoice = []; // invoice id => [revenue, cost, line loss]
        $invoiceOfLine = InvoiceLine::whereIn('id', $sales->pluck('invoice_line_id')->filter()->unique())->pluck('invoice_id', 'id');

        foreach ($sales as $s) {
            $units = abs((float) $s->units);
            $lineRevenue = $units * (float) $s->unit_price;
            $lineCost = $units * (float) $s->unit_cost;
            $lineLoss = $s->is_loss ? $lineCost - $lineRevenue : 0.0;
            $revenue += $lineRevenue;
            $cost += $lineCost;
            $lossValue += $lineLoss;

            $invoice = $invoiceOfLine[$s->invoice_line_id] ?? null;
            if ($invoice !== null) {
                $perInvoice[$invoice] ??= [0.0, 0.0, 0.0];
                $perInvoice[$invoice][0] += $lineRevenue;
                $perInvoice[$invoice][1] += $lineCost;
                $perInvoice[$invoice][2] += $lineLoss;
            }
        }

        $discounted = Invoice::whereIn('id', array_keys($perInvoice))->where('status', 'final')->where('discount_total', '>', 0)->pluck('discount_total', 'id');
        foreach ($discounted as $id => $discount) {
            [$invoiceRevenue, $invoiceCost, $lineLoss] = $perInvoice[$id];
            $revenue -= (float) $discount;
            // The discount can turn a memo that made money line by line into one that lost it overall; the part not already counted is a loss too.
            $lossValue += max(0.0, max(0.0, $invoiceCost - ($invoiceRevenue - (float) $discount)) - $lineLoss);
        }

        return ['revenue' => round($revenue, 2), 'cost' => round($cost, 2), 'profit' => round($revenue - $cost, 2), 'loss_sales_value' => round($lossValue, 2), 'sales_lines' => $sales->count()];
    }

    /** Expenses counted in a month: one-off ones dated in it, plus each recurring one that is running in it. */
    public function expenses(Carbon $month): Collection
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        return Expense::query()->where(function ($q) use ($start, $end) {
            $q->where(fn ($one) => $one->where('recurring', false)->whereBetween('spent_on', [$start->toDateString(), $end->toDateString()]))
                ->orWhere(fn ($rec) => $rec->where('recurring', true)->where('spent_on', '<=', $end->toDateString())
                    ->where(fn ($until) => $until->whereNull('recurring_until')->orWhere('recurring_until', '>=', $start->toDateString())));
        })->orderBy('category')->get();
    }

    /** @return array{gross: float, expenses: float, net: float, by_category: array<string, float>} */
    public function net(Carbon $month): array
    {
        $gross = $this->gross($month)['profit'];
        $expenses = $this->expenses($month);

        return [
            'gross' => $gross,
            'expenses' => round((float) $expenses->sum('amount'), 2),
            'net' => round($gross - (float) $expenses->sum('amount'), 2),
            'by_category' => $expenses->groupBy('category')->map(fn (Collection $set) => round((float) $set->sum('amount'), 2))->all(),
        ];
    }

    /** The last N months, oldest first, for the trend. */
    public function trend(int $months = 12, ?Carbon $until = null): Collection
    {
        $until ??= now();

        return collect(range($months - 1, 0))->map(function (int $back) use ($until) {
            $month = $until->copy()->startOfMonth()->subMonths($back);

            return ['month' => $month->format('Y-m')] + $this->net($month);
        });
    }
}
