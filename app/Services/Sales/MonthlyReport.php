<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Services\Stock\ExpiryService;
use App\Services\Stock\ReportService;
use Illuminate\Support\Carbon;

/**
 * The monthly ranking report (spec ch 06 and 25) worked out in one place, so it can be shown live or prepared overnight and kept (ReportSnapshots).
 * Nothing in it depends on who is looking, so one prepared copy serves everyone who may see the reports.
 */
class MonthlyReport
{
    public function __construct(private SalesReports $reports, private ReportPeriod $period) {}

    /** @return array<string, mixed> */
    public function build(Carbon $label): array
    {
        $label = $label->copy()->startOfMonth();
        [$from, $to] = $this->period->range($label);
        [$pFrom, $pTo] = $this->period->range($this->period->before($label));
        [$yFrom, $yTo] = $this->period->range($label->copy()->subYearNoOverflow());
        $ordering = $this->reports->ordering($from, $to);

        return [
            'month' => $label->format('Y-m'),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->reports->totals($from, $to),
            'previous' => $this->reports->totals($pFrom, $pTo),
            'lastYear' => $this->reports->totals($yFrom, $yTo),
            'sectors' => $this->reports->bySector($from, $to),
            'days' => $this->reports->days($from, $to),
            'ordering' => $ordering->take(40)->values(),
            'byProfit' => $ordering->sortByDesc('profit')->take(10)->values(),
            'fast' => $ordering->take(10)->values(),
            'slow' => $ordering->filter(fn (array $r) => $r['advice'] === 'less')->take(10)->values(),
            'notMoving' => $this->reports->notMoving($from, $to),
            'companies' => $this->reports->companies($from, $to)->take(10)->values(),
            'customers' => $this->reports->customers($from, $to),
            'inactive' => $this->reports->inactive(),
            // Money lost that the profit line alone hides: sales made below cost this period, and expired goods the company would not take back.
            'losses' => [
                'below_cost' => app(ReportService::class)->gross($label)['loss_sales_value'],
                'expired_not_taken' => round((float) app(ExpiryService::class)->expiredByCompany()->sum('lost'), 2),
            ],
            // "Last year this sold a lot at this time" (spec 6.9).
            'season' => $this->reports->seasonal()->take(15)->values(),
            'corrected' => $this->corrected($from, $to),
        ];
    }

    /**
     * Memos that were corrected in the period, kept apart so the totals can be read knowing which entries were replaced (spec ch 25: corrected entries are marked separately).
     *
     * @return array{count: int, difference: float, items: list<array<string, mixed>>}
     */
    public function corrected(Carbon $from, Carbon $to): array
    {
        $rows = Invoice::query()->whereNotNull('corrects_invoice_id')->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])
            ->get(['id', 'number', 'total', 'corrects_invoice_id', 'completed_at']);
        $old = Invoice::whereIn('id', $rows->pluck('corrects_invoice_id'))->get(['id', 'number', 'total'])->keyBy('id');

        $items = $rows->map(fn (Invoice $i) => [
            'number' => $i->number, 'replaces' => $old[$i->corrects_invoice_id]->number ?? null, 'was' => round((float) ($old[$i->corrects_invoice_id]->total ?? 0), 2),
            'now' => round((float) $i->total, 2), 'when' => $i->completed_at->toDateString(),
        ])->sortByDesc('when')->values();

        return ['count' => $items->count(), 'difference' => round((float) $items->sum(fn ($r) => $r['now'] - $r['was']), 2), 'items' => $items->take(20)->all()];
    }
}
