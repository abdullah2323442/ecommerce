<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\VatReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The VAT (Mushak) report for the accountant (spec ch 25). Admin only: it carries sales. */
class VatReportController extends Controller
{
    public function __construct(private VatReport $report) {}

    public function index(Request $request): Response
    {
        $this->authorize('view-reports');
        [$from, $to, $kind] = $this->filters($request);

        return Inertia::render('admin/reports/vat', [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'kind' => $kind, 'kinds' => VatReport::KINDS, 'enabled' => $this->report->enabled(),
        ] + $this->report->build($from, $to, $kind));
    }

    /** A spreadsheet file for the accountant (opens in Excel; the BOM keeps Bangla names right). */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('view-reports');
        [$from, $to, $kind] = $this->filters($request);
        $rows = $this->report->csv($from, $to, $kind);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'vat-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: ?string} the period (this month by default, never backwards, at most a year) and the kind of sale */
    private function filters(Request $request): array
    {
        $parse = function (?string $v, Carbon $fallback): Carbon {
            try {
                return $v ? Carbon::parse($v, 'Asia/Dhaka')->startOfDay() : $fallback;
            } catch (\Throwable) {
                return $fallback;
            }
        };

        $now = now('Asia/Dhaka');
        $from = $parse($request->query('from'), $now->copy()->startOfMonth());
        $to = $parse($request->query('to'), $now->copy()->endOfMonth()->startOfDay());

        if ($to->lt($from)) {
            $to = $from->copy();
        }

        if ($from->diffInDays($to) > 366) {
            $to = $from->copy()->addDays(366);
        }

        $kind = $request->query('kind');

        return [$from, $to, is_string($kind) && in_array($kind, VatReport::KINDS, true) ? $kind : null];
    }
}
