<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesReports;
use App\Services\Stock\ExpiryService;
use App\Services\Stock\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The admin's sales and ranking reports. Admin only: they carry profit (spec ch 26: staff do not see sales and profit). */
class SalesReportController extends Controller
{
    public function __construct(private SalesReports $reports) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-reports');
        $month = $this->month($request);
        [$from, $to] = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $prev = $month->copy()->subMonthNoOverflow();
        $lastYear = $month->copy()->subYearNoOverflow();
        $ordering = $this->reports->ordering($from, $to);

        return Inertia::render('admin/reports/monthly', [
            'month' => $month->format('Y-m'),
            'totals' => $this->reports->totals($from, $to),
            'previous' => $this->reports->totals($prev->copy()->startOfMonth(), $prev->copy()->endOfMonth()),
            'lastYear' => $this->reports->totals($lastYear->copy()->startOfMonth(), $lastYear->copy()->endOfMonth()),
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
            'tonight' => $this->reports->nightly(),
            // Money lost that the profit line alone hides: sales made below cost this month, and expired goods the company would not take back.
            'losses' => [
                'below_cost' => app(ReportService::class)->gross($month)['loss_sales_value'],
                'expired_not_taken' => round((float) app(ExpiryService::class)->expiredByCompany()->sum('lost'), 2),
            ],
            // "Last year this sold a lot at this time" (spec 6.9).
            'season' => $this->reports->seasonal()->take(15)->values(),
        ]);
    }

    /** Every sale line of the month as a spreadsheet file (opens in Excel; the BOM keeps Bangla names right). */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('view-reports');
        $month = $this->month($request);
        $rows = $this->reports->exportRows($month->copy()->startOfMonth(), $month->copy()->endOfMonth());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Memo', 'Date', 'Kind', 'Channel', 'Customer', 'Staff', 'Medicine', 'Strength', 'Unit', 'Quantity', 'Unit price', 'Line total', 'Rate', 'Payment']);

            foreach ($rows as $r) {
                fputcsv($out, [$r->number, $r->finalised_at, $r->kind, $r->channel, $r->customer, $r->staff, $r->brand_name, $r->strength, $r->unit, $r->quantity, $r->unit_price, round((float) $r->line_total, 2), $r->rate_layer, $r->payment_method]);
            }
            fclose($out);
        }, 'sales-'.$month->format('Y-m').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function month(Request $request): Carbon
    {
        try {
            return $request->query('month') ? Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth() : now()->startOfMonth();
        } catch (\Throwable) {
            return now()->startOfMonth();
        }
    }
}
