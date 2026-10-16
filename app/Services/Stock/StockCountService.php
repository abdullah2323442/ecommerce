<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock count mode (spec CNT-1, CNT-2).
 *
 * A session snapshots what the system says for the medicines in scope. Staff enter what they counted. Sales
 * may go on meanwhile, so the system figure is moved forward by what was sold since the count started
 * (counted items are compared with what should be on the shelf now, not with the old snapshot). Differences
 * become adjustments only when the admin approves, recorded as count adjustments, never as sales or losses.
 */
class StockCountService
{
    public function __construct(private StockService $stock) {}

    /** @param array{scope?: string, company_id?: int|null, shelf?: string|null} $scope */
    public function start(array $scope, User $by): StockCount
    {
        $kind = $scope['scope'] ?? 'all';

        return DB::transaction(function () use ($scope, $kind, $by) {
            $count = StockCount::create([
                'scope' => $kind,
                'company_id' => $kind === 'company' ? ($scope['company_id'] ?? null) : null,
                'shelf' => $kind === 'shelf' ? ($scope['shelf'] ?? null) : null,
                'status' => 'open',
                'started_by' => $by->id,
                'started_at' => now(),
            ]);

            $lots = StockLot::query()->where('units_remaining', '>', 0)->whereNull('expired_at')
                ->when($kind === 'shelf', fn ($q) => $q->where('shelf', $scope['shelf'] ?? ''))
                ->when($kind === 'company', fn ($q) => $q->whereHas('medicine', fn ($m) => $m->where('company_id', $scope['company_id'] ?? 0)))
                ->get()->groupBy('medicine_id');

            foreach ($lots as $medicineId => $set) {
                StockCountLine::create(['stock_count_id' => $count->id, 'medicine_id' => $medicineId, 'system_units' => (float) $set->sum('units_remaining')]);
            }

            return $count;
        });
    }

    public function pause(StockCount $count): void
    {
        if ($count->isOpen()) {
            $count->update(['status' => $count->status === 'paused' ? 'open' : 'paused']);
        }
    }

    /** Record a counted number. A medicine that was not in the snapshot (found on the shelf, not in the system) is added with 0. */
    public function record(StockCount $count, Medicine $medicine, float $counted): StockCountLine
    {
        if (! $count->isOpen()) {
            throw new StockException('This count is closed.');
        }

        $line = StockCountLine::firstOrNew(['stock_count_id' => $count->id, 'medicine_id' => $medicine->id]);
        $line->system_units ??= 0;
        $line->counted_units = $counted;
        $line->save();

        return $line;
    }

    /** Add a scanned item: the first scan counts 1, each further scan adds 1 (no duplicate line). */
    public function scan(StockCount $count, Medicine $medicine): StockCountLine
    {
        $current = (float) (StockCountLine::where('stock_count_id', $count->id)->where('medicine_id', $medicine->id)->value('counted_units') ?? 0);

        return $this->record($count, $medicine, $current + 1);
    }

    /**
     * The comparison the admin reviews. Only lines that were counted are compared; a line not counted yet
     * is shown as pending, never as a shortage.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function review(StockCount $count): Collection
    {
        return $count->lines()->with('medicine.company:id,name')->get()->map(function (StockCountLine $line) use ($count) {
            $soldSince = $this->soldSince($count, $line->medicine_id);
            $expected = round((float) $line->system_units - $soldSince, 4);
            $counted = $line->counted_units === null ? null : (float) $line->counted_units;
            $diff = $counted === null ? null : round($counted - $expected, 2);
            $cost = $this->averageCost($line->medicine_id);

            return [
                'line_id' => $line->id,
                'medicine_id' => $line->medicine_id,
                'brand_name' => $line->medicine->brand_name,
                'strength' => $line->medicine->strength,
                'company' => $line->medicine->company?->name,
                'system' => (float) $line->system_units,
                'sold_since' => $soldSince,
                'expected' => $expected,
                'counted' => $counted,
                'difference' => $diff,
                'difference_value' => $diff === null ? null : round($diff * $cost, 2),
            ];
        })->sortBy('brand_name')->values();
    }

    /** Admin only. Applies every counted difference as a count adjustment and closes the session. */
    public function approve(StockCount $count, User $by): StockCount
    {
        if ($by->account_type !== AccountType::Admin) {
            throw StockException::adminOnly();
        }

        if (! $count->isOpen()) {
            throw new StockException('This count is closed.');
        }

        return DB::transaction(function () use ($count, $by) {
            $total = 0.0;

            foreach ($this->review($count) as $row) {
                if ($row['difference'] === null || $row['difference'] === 0.0) {
                    continue;
                }

                $this->adjust(Medicine::findOrFail($row['medicine_id']), $row['difference'], $by, $count);
                $total += (float) $row['difference_value'];
            }

            $count->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'difference_value' => round($total, 2)]);

            return $count->fresh();
        });
    }

    public function cancel(StockCount $count): void
    {
        if ($count->isOpen()) {
            $count->update(['status' => 'cancelled']);
        }
    }

    private function soldSince(StockCount $count, int $medicineId): float
    {
        return (float) abs(StockMovement::where('medicine_id', $medicineId)->where('type', 'sale')->where('reversed', false)->where('created_at', '>=', $count->started_at)->sum('units'));
    }

    private function averageCost(int $medicineId): float
    {
        $lots = StockLot::where('medicine_id', $medicineId)->where('units_remaining', '>', 0)->get();
        $units = (float) $lots->sum('units_remaining');

        return $units > 0 ? (float) $lots->sum(fn (StockLot $l) => (float) $l->units_remaining * (float) $l->unit_cost) / $units : (float) (StockLot::where('medicine_id', $medicineId)->latest('id')->value('unit_cost') ?? 0);
    }

    /** A signed change to the lots, recorded as a count adjustment (not a sale, not a loss). */
    private function adjust(Medicine $medicine, float $delta, User $by, StockCount $count): void
    {
        $lots = StockLot::where('medicine_id', $medicine->id)->orderBy('id')->lockForUpdate()->get();

        if ($delta > 0) {
            $lot = $lots->last();
            if ($lot === null) {
                return; // found on the shelf but never bought: it needs a purchase entry to get a cost
            }
            $lot->update(['units_remaining' => round((float) $lot->units_remaining + $delta, 4)]);
            $this->log($medicine, $lot, $delta, $by, $count);

            return;
        }

        $left = -$delta;
        foreach ($lots->filter(fn (StockLot $l) => (float) $l->units_remaining > 0) as $lot) {
            $take = min($left, (float) $lot->units_remaining);
            $lot->update(['units_remaining' => round((float) $lot->units_remaining - $take, 4)]);
            $this->log($medicine, $lot, -$take, $by, $count);
            $left = round($left - $take, 4);

            if ($left <= 0) {
                break;
            }
        }
    }

    private function log(Medicine $medicine, StockLot $lot, float $units, User $by, StockCount $count): void
    {
        StockMovement::create([
            'medicine_id' => $medicine->id, 'stock_lot_id' => $lot->id, 'type' => 'count', 'units' => $units, 'unit_cost' => $lot->unit_cost,
            'reason' => 'count', 'note' => "Stock count #{$count->id}", 'user_id' => $by->id,
        ]);
    }

    /** Companies for the scope picker. */
    public function companies(): Collection
    {
        return Company::whereHas('medicines')->orderBy('name')->get(['id', 'name']);
    }
}
