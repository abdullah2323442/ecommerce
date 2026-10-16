<?php

namespace App\Services\Stock;

use App\Models\Medicine;
use App\Models\Notice;
use App\Models\StockMismatch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * "Report mismatch" (spec 6.7). Staff cannot correct stock, so they report what they found and the admin hears at once.
 * The system number is kept with the report for the admin; the person reporting never gets it back.
 */
class MismatchService
{
    public function __construct(private StockService $stock) {}

    public function report(Medicine $medicine, float $found, User $by, ?string $note = null): StockMismatch
    {
        if ($found < 0) {
            throw new StockException('The number found cannot be below zero.');
        }

        $report = StockMismatch::create([
            'medicine_id' => $medicine->id, 'reported_by' => $by->id, 'found' => $found,
            'system_units' => $this->stock->onHand($medicine), 'note' => $note ? mb_substr($note, 0, 300) : null,
        ]);

        $text = fn (float $n) => rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');

        foreach (User::withPermission('stock')->pluck('id') as $adminId) {
            if ($adminId !== $by->id) {
                Notice::create(['user_id' => $adminId, 'kind' => 'stock_mismatch', 'body' => mb_substr($by->name.': '.$medicine->brand_name.' - found '.$text($found).', system says '.$text((float) $report->system_units), 0, 290)]);
            }
        }

        return $report;
    }

    /** Open reports, newest first. `clash` is true when the same medicine has two open reports that disagree. */
    public function open(): Collection
    {
        $rows = StockMismatch::with(['medicine:id,brand_name,strength,form,packaging_type', 'reporter:id,name'])->where('status', 'open')->latest('id')->get();
        $clash = $rows->groupBy('medicine_id')->map(fn (Collection $g) => $g->pluck('found')->map(fn ($f) => (string) $f)->unique()->count() > 1);

        return $rows->map(fn (StockMismatch $m) => [
            'id' => $m->id,
            'medicine' => ['id' => $m->medicine->id, 'name' => trim($m->medicine->brand_name.' '.$m->medicine->strength), 'form' => $m->medicine->form],
            'by' => $m->reporter?->name,
            'found' => (float) $m->found,
            'system_then' => (float) $m->system_units,
            'system_now' => $this->stock->onHand($m->medicine),
            'note' => $m->note,
            'at' => $m->created_at->toDateTimeString(),
            'clash' => (bool) $clash->get($m->medicine_id),
        ])->values();
    }

    public function openCount(): int
    {
        return StockMismatch::where('status', 'open')->count();
    }

    /** The admin fixes the number to what was found, or dismisses the report. Either way every open report on that medicine is closed. */
    public function resolve(StockMismatch $report, string $action, User $by): void
    {
        if ($report->status !== 'open') {
            throw new StockException('This report is already closed.');
        }

        if ($action === 'fix') {
            $this->stock->correct($report->medicine, (float) $report->found, 'entry_mistake', $by, 'From a mismatch report by '.($report->reporter?->name ?? 'staff'));
        }

        StockMismatch::where('medicine_id', $report->medicine_id)->where('status', 'open')
            ->update(['status' => $action === 'fix' ? 'fixed' : 'dismissed', 'resolved_by' => $by->id, 'resolved_at' => now()]);
    }
}
