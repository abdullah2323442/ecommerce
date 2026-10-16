<?php

namespace App\Services\Stock;

use App\Models\Setting;
use App\Models\StockLot;
use Illuminate\Support\Collection;

/** Many tiny, old lots of one medicine are hard to sell and easy to lose track of (spec 6.8). */
class LotWarnings
{
    public function manyLots(): int
    {
        return max(2, (int) Setting::read('stock.fragment_lots', 4));
    }

    public function smallUnits(): float
    {
        return max(0, (float) Setting::read('stock.small_lot_units', 2));
    }

    public function oldDays(): int
    {
        return max(1, (int) Setting::read('stock.old_lot_days', 90));
    }

    public function isSmallAndOld(StockLot $lot): bool
    {
        $date = $lot->invoice?->invoice_date ?? $lot->created_at;

        return (float) $lot->units_remaining > 0 && (float) $lot->units_remaining <= $this->smallUnits() && $date !== null && $date->lt(now()->subDays($this->oldDays())->startOfDay());
    }

    /**
     * For a set of medicines: how many lots are left and how many of those are small and old.
     *
     * @param  array<int, int>  $medicineIds
     * @return Collection<int, array{lots: int, small_old: int, warn: bool}>
     */
    public function summary(array $medicineIds): Collection
    {
        $lots = StockLot::with('invoice:id,invoice_date')->whereIn('medicine_id', $medicineIds)->where('units_remaining', '>', 0)->get()->groupBy('medicine_id');

        return collect($medicineIds)->mapWithKeys(function ($id) use ($lots) {
            $group = $lots->get($id, collect());
            $smallOld = $group->filter(fn (StockLot $l) => $this->isSmallAndOld($l))->count();

            return [$id => ['lots' => $group->count(), 'small_old' => $smallOld, 'warn' => $group->count() >= $this->manyLots() || $smallOld > 0]];
        });
    }
}
