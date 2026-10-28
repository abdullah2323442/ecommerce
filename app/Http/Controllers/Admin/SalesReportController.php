<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\ReportPeriod;
use App\Services\Sales\ReportSnapshots;
use App\Services\Sales\SalesReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The admin's sales and ranking reports. Admin only: they carry profit (spec ch 26: staff do not see sales and profit). */
class SalesReportController extends Controller
{
    public function __construct(private SalesReports $reports, private ReportPeriod $period, private ReportSnapshots $snapshots) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-reports');
        $month = $this->month($request);
        $snapshot = $this->snapshots->monthly($month);

        // The report itself is the same for everyone who may see it, so it is read ready-made; only tonight's summary is worked out as it is opened.
        return Inertia::render('admin/reports/monthly', $snapshot->payload + [
            'tonight' => $this->reports->nightly(),
            'prepared_at' => $snapshot->prepared_at->toIso8601String(),
            'startDay' => $this->period->startDay(),
        ]);
    }

    /** Make this month's report again now, instead of waiting for the night. */
    public function prepare(Request $request): RedirectResponse
    {
        $this->authorize('view-reports');
        $month = $this->month($request);
        $this->snapshots->prepare($month, $request->user());

        return to_route('admin.reports.index', ['month' => $month->format('Y-m')])->with('status', __('The report is prepared again.'));
    }

    /** Every sale line of the month as a spreadsheet file (opens in Excel; the BOM keeps Bangla names right). */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('view-reports');
        $month = $this->month($request);
        [$from, $to] = $this->period->range($month);
        $rows = $this->reports->exportRows($from, $to);

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
            return $request->query('month') ? Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth() : $this->period->labelFor();
        } catch (\Throwable) {
            return $this->period->labelFor();
        }
    }
}
