<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\User;
use App\Services\Stock\StockException;

/**
 * Bulk order upload (spec ch 14, Phase 2): a trade buyer who orders the same long list every time uploads it as a CSV instead of
 * searching line by line. Each row is "name, quantity, unit": the name may carry the strength ("Napa 500mg"); the unit is
 * piece, strip or box and defaults to a box. Rows that match go into the buyer's order to read through and place; rows that do not
 * are listed with the reason, never guessed at.
 */
class BulkOrder
{
    public const MAX_ROWS = 300;

    public function __construct(private InvoiceService $invoices) {}

    /**
     * @return array{added: int, problems: array<int, array{line: int, text: string, reason: string}>}
     *
     * @throws StockException
     */
    public function import(Invoice $cart, User $by, string $csv): array
    {
        $rows = $this->parse($csv);

        if ($rows === []) {
            throw new StockException('The list is empty. Each line is: name, quantity, unit.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new StockException(__('The list has more than {n} lines. Split it in two.', ['n' => self::MAX_ROWS]));
        }

        $added = 0;
        $problems = [];

        foreach ($rows as $row) {
            try {
                [$medicine, $unit] = $this->match($row);
                $this->invoices->addLine($cart, $medicine, $unit, $row['quantity'], $by);
                $added++;
            } catch (StockException $e) {
                $problems[] = ['line' => $row['line'], 'text' => $row['raw'], 'reason' => __($e->getMessage())];
            }
        }

        return ['added' => $added, 'problems' => $problems];
    }

    /** @return array<int, array{line: int, raw: string, name: string, quantity: float, unit: ?string}> */
    public function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $i => $text) {
            if (trim($text) === '') {
                continue;
            }

            $cells = array_map('trim', str_getcsv($text, str_contains($text, "\t") ? "\t" : (substr_count($text, ';') > substr_count($text, ',') ? ';' : ',')));
            $quantity = $cells[1] ?? '';

            // A first line whose quantity is a word is the heading.
            if ($i === 0 && ! is_numeric($quantity)) {
                continue;
            }

            $unit = isset($cells[2]) && $cells[2] !== '' ? mb_strtolower($cells[2]) : null;
            $out[] = ['line' => $i + 1, 'raw' => mb_substr(trim($text), 0, 120), 'name' => $cells[0] ?? '', 'quantity' => is_numeric($quantity) ? (float) $quantity : 0.0, 'unit' => $unit];
        }

        return $out;
    }

    /**
     * @return array{0: Medicine, 1: string}
     *
     * @throws StockException
     */
    private function match(array $row): array
    {
        if ($row['name'] === '') {
            throw new StockException('There is no name on this line.');
        }

        if ($row['quantity'] <= 0 || $row['quantity'] > 100000) {
            throw new StockException('The quantity must be a number more than zero.');
        }

        if ($row['unit'] !== null && ! in_array($row['unit'], ['piece', 'strip', 'box'], true)) {
            throw new StockException('The unit must be piece, strip or box.');
        }

        $needle = $this->plain($row['name']);
        $first = preg_split('/\s+/', trim($row['name']))[0];
        $found = Medicine::where('is_active', true)->where('brand_name', 'like', addcslashes($first, '%_\\').'%')
            ->get()->filter(fn (Medicine $m) => $this->plain($m->brand_name) === $needle || $this->plain($m->brand_name.' '.$m->strength) === $needle)->values();

        if ($found->isEmpty()) {
            throw new StockException('We could not find this product.');
        }

        // The very same name for several strengths: asking for the name alone is a guess, so it is refused.
        $exact = $found->filter(fn (Medicine $m) => $this->plain($m->brand_name.' '.$m->strength) === $needle);
        $pick = $exact->count() === 1 ? $exact->first() : ($found->count() === 1 ? $found->first() : null);

        if ($pick === null) {
            throw new StockException('Several products have this name. Add the strength, like Napa 500mg.');
        }

        $unit = $row['unit'] ?? ($pick->packaging_type === 'box' ? 'box' : 'piece');

        if ($pick->packaging_type !== 'box' && $unit !== 'piece') {
            throw new StockException('This product is only sold by the piece.');
        }

        return [$pick, $unit];
    }

    private function plain(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text)) ?? '';
    }
}
