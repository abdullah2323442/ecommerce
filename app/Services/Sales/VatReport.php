<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\SaleReturn;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The VAT (Mushak) report for the accountant (spec ch 25): the sales of a period and the VAT inside them, by VAT rate, with the returns taken
 * back out. The price on a memo already includes VAT, so each line's VAT is the part of its price that is tax; the end discount on the memo
 * lowers the sale and its VAT in the same proportion. Only finished memos count. Date range and kind of sale can be chosen.
 */
class VatReport
{
    public const KINDS = ['retail', 'paikari', 'wholesale'];

    public function enabled(): bool
    {
        return (bool) Setting::read('vat.enabled', false);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>, memos: array<int, array<string, mixed>>}
     */
    public function build(Carbon $from, Carbon $to, ?string $kind = null): array
    {
        $invoices = Invoice::whereNotNull('completed_at')->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($kind && in_array($kind, self::KINDS, true), fn ($q) => $q->where('kind', $kind))
            ->with(['lines' => fn ($q) => $q->whereNull('removed_at'), 'customer:id,name,business_name'])->orderBy('completed_at')->get();

        $rows = [];
        $memos = [];

        foreach ($invoices as $inv) {
            $gross = round((float) $inv->lines->sum(fn (InvoiceLine $l) => $l->lineTotal()), 2);
            // The end discount lowers the sale; the VAT in it falls by the same share.
            $share = $gross > 0 ? max(0.0, ($gross - (float) $inv->discount_total) / $gross) : 1.0;
            $memoVat = 0.0;
            $memoSale = 0.0;

            foreach ($inv->lines as $l) {
                $rate = number_format((float) $l->vat_percent, 2, '.', '');
                $sale = round($l->lineTotal() * $share, 2);
                $vat = round((float) $l->vat_amount * $share, 2);
                $rows[$rate] ??= ['rate' => (float) $rate, 'memos' => [], 'gross' => 0.0, 'vat' => 0.0, 'returned' => 0.0, 'returned_vat' => 0.0];
                $rows[$rate]['memos'][$inv->id] = true;
                $rows[$rate]['gross'] += $sale;
                $rows[$rate]['vat'] += $vat;
                $memoVat += $vat;
                $memoSale += $sale;
            }

            $memos[] = [
                'number' => $inv->number, 'date' => $inv->completed_at->setTimezone('Asia/Dhaka')->toDateString(), 'kind' => $inv->kind,
                'customer' => $inv->customer?->business_name ?: $inv->customer?->name, 'sale' => round($memoSale, 2), 'vat' => round($memoVat, 2),
            ];
        }

        // Returns approved in the period take their share of VAT back (the price paid, less the tax inside it).
        SaleReturn::where('status', 'approved')->whereBetween('decided_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with('lines.invoiceLine', 'invoice:id,kind')->get()
            ->filter(fn (SaleReturn $r) => ! $kind || $r->invoice?->kind === $kind)
            ->each(function (SaleReturn $r) use (&$rows) {
                foreach ($r->lines as $line) {
                    $rate = number_format((float) ($line->invoiceLine?->vat_percent ?? 0), 2, '.', '');
                    $rows[$rate] ??= ['rate' => (float) $rate, 'memos' => [], 'gross' => 0.0, 'vat' => 0.0, 'returned' => 0.0, 'returned_vat' => 0.0];
                    $rows[$rate]['returned'] += (float) $line->amount;
                    $rows[$rate]['returned_vat'] += (float) $rate > 0 ? round((float) $line->amount * (float) $rate / (100 + (float) $rate), 2) : 0.0;
                }
            });

        $rows = collect($rows)->sortByDesc('rate')->map(function (array $r) {
            $sale = round($r['gross'] - $r['returned'], 2);
            $vat = round($r['vat'] - $r['returned_vat'], 2);

            return [
                'rate' => $r['rate'], 'memos' => count($r['memos']), 'sales' => round($r['gross'], 2), 'vat' => round($r['vat'], 2), 'returned' => round($r['returned'], 2),
                'returned_vat' => round($r['returned_vat'], 2), 'net_sales' => $sale, 'net_vat' => $vat, 'without_vat' => round($sale - $vat, 2),
            ];
        })->values();

        return [
            'rows' => $rows->all(),
            'totals' => [
                'memos' => (float) count($memos), 'sales' => round($rows->sum('sales'), 2), 'vat' => round($rows->sum('vat'), 2), 'returned' => round($rows->sum('returned'), 2), 'returned_vat' => round($rows->sum('returned_vat'), 2),
                'net_sales' => round($rows->sum('net_sales'), 2), 'net_vat' => round($rows->sum('net_vat'), 2), 'without_vat' => round($rows->sum('without_vat'), 2),
            ],
            'memos' => $memos,
        ];
    }

    /**
     * The same report as rows for a spreadsheet: the summary by rate, then every memo.
     *
     * @return Collection<int, array<int, string|float|int|null>>
     */
    public function csv(Carbon $from, Carbon $to, ?string $kind = null): Collection
    {
        $r = $this->build($from, $to, $kind);
        $out = collect([
            ['VAT report', $from->toDateString(), $to->toDateString(), $kind ?: 'all'],
            [],
            ['VAT rate %', 'Memos', 'Sales (VAT included)', 'VAT', 'Returned', 'VAT returned', 'Net sales', 'Net VAT', 'Net sales without VAT'],
        ]);

        foreach ($r['rows'] as $row) {
            $out->push([$row['rate'], $row['memos'], $row['sales'], $row['vat'], $row['returned'], $row['returned_vat'], $row['net_sales'], $row['net_vat'], $row['without_vat']]);
        }

        $t = $r['totals'];
        $out->push(['Total', (int) $t['memos'], $t['sales'], $t['vat'], $t['returned'], $t['returned_vat'], $t['net_sales'], $t['net_vat'], $t['without_vat']]);
        $out->push([]);
        $out->push(['Memo', 'Date', 'Kind', 'Customer', 'Sale', 'VAT']);

        foreach ($r['memos'] as $m) {
            $out->push([$m['number'], $m['date'], $m['kind'], $m['customer'], $m['sale'], $m['vat']]);
        }

        return $out;
    }
}
