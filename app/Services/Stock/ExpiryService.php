<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Expiry management (spec 4.3.7). The real risk is stationary stock: goods that move regularly need no
 * expiry entry. So the system looks for stagnant lots (nothing bought or sold for a while), for lots that
 * would take more than a year to sell, and for lots with a known date that is getting close.
 */
class ExpiryService
{
    public const DEFAULTS = ['stagnant_months' => 6, 'months_of_stock_limit' => 12, 'near_expiry_months' => 3];

    public const RETURN_STATES = ['pending', 'returned', 'not_taken'];

    public function setting(string $key): int
    {
        return (int) Setting::read("stock.{$key}", self::DEFAULTS[$key]);
    }

    public function saveSettings(array $values): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (isset($values[$key])) {
                Setting::write("stock.{$key}", (int) $values[$key]);
            }
        }
    }

    /**
     * Stock to look at on the shelf. Each row says why. A lot that has an expiry date is left to the date
     * (near-expiry), and a lot checked recently is left alone until the window passes again.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function needsChecking(?Carbon $now = null): Collection
    {
        $now ??= now();
        $stagnantMonths = $this->setting('stagnant_months');
        $limit = $this->setting('months_of_stock_limit');

        $lots = StockLot::query()->where('units_remaining', '>', 0)->whereNull('expired_at')->whereNull('expiry_date')
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', $now->copy()->subMonths($stagnantMonths)))
            ->with('medicine.company:id,name')->get()->groupBy('medicine_id');

        $rows = collect();

        foreach ($lots as $medicineId => $medicineLots) {
            $medicine = $medicineLots->first()->medicine;
            $lastMove = StockMovement::where('medicine_id', $medicineId)->whereIn('type', ['purchase', 'sale'])->max('created_at');
            $sold = (float) abs(StockMovement::where('medicine_id', $medicineId)->where('type', 'sale')->where('reversed', false)->where('created_at', '>=', $now->copy()->subMonths(6))->sum('units'));
            $monthly = $sold / 6;
            $onHand = (float) $medicineLots->sum('units_remaining');
            $monthsOfStock = $monthly > 0 ? round($onHand / $monthly, 1) : null;

            $stagnant = $lastMove === null || Carbon::parse($lastMove)->lt($now->copy()->subMonths($stagnantMonths));
            $tooMuch = $monthsOfStock !== null && $monthsOfStock > $limit;

            if (! $stagnant && ! $tooMuch) {
                continue;
            }

            $rows->push([
                'medicine_id' => $medicine->id,
                'brand_name' => $medicine->brand_name,
                'strength' => $medicine->strength,
                'company' => $medicine->company?->name,
                'on_hand' => $onHand,
                'value' => round((float) $medicineLots->sum(fn (StockLot $l) => (float) $l->units_remaining * (float) $l->unit_cost), 2),
                'last_move' => $lastMove ? Carbon::parse($lastMove)->toDateString() : null,
                'months_of_stock' => $monthsOfStock,
                'reason' => $stagnant ? 'stagnant' : 'too_much',
            ]);
        }

        return $rows->sortByDesc('value')->values();
    }

    /** Money tied up in goods that need checking, for the line at the top of the screen. */
    public function moneyAtRisk(?Carbon $now = null): float
    {
        return round((float) $this->needsChecking($now)->sum('value'), 2);
    }

    /**
     * "Checked, still good": the lots stay. An expiry date may be entered; if it is, the system reminds at the
     * right time and the six-month rule no longer applies to that lot.
     */
    public function markChecked(int $medicineId, ?string $expiryDate = null): int
    {
        $lots = StockLot::where('medicine_id', $medicineId)->where('units_remaining', '>', 0)->whereNull('expired_at')->get();

        foreach ($lots as $lot) {
            $lot->checked_at = now();
            if ($expiryDate !== null) {
                $lot->expiry_date = $expiryDate;
            }
            $lot->save();
        }

        return $lots->count();
    }

    /**
     * Lots with a known date inside the near-expiry window, with the lowest safe offer price: the lot's own
     * cost, so the offer still breaks even. Siam still approves any offer.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function nearExpiry(?Carbon $now = null): Collection
    {
        $now ??= now();

        return StockLot::query()->where('units_remaining', '>', 0)->whereNull('expired_at')->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $now->copy()->addMonths($this->setting('near_expiry_months'))->toDateString())
            ->with('medicine.company:id,name')->orderBy('expiry_date')->get()
            ->map(function (StockLot $lot) use ($now) {
                $mrp = StockService::unitMrp($lot->medicine);
                $cost = (float) $lot->unit_cost;

                return [
                    'lot_id' => $lot->id,
                    'medicine_id' => $lot->medicine_id,
                    'brand_name' => $lot->medicine->brand_name,
                    'strength' => $lot->medicine->strength,
                    'company' => $lot->medicine->company?->name,
                    'units' => (float) $lot->units_remaining,
                    'expiry_date' => $lot->expiry_date->toDateString(),
                    'days_left' => (int) $now->copy()->startOfDay()->diffInDays($lot->expiry_date, false),
                    'unit_cost' => $cost,
                    'safe_price' => ceil($cost * 100) / 100,
                    'safe_percent_off' => $mrp > 0 ? round(max(0, (1 - ceil($cost * 100) / 100 / $mrp) * 100), 1) : null,
                ];
            });
    }

    /** Move a lot's remaining units to the expired section. Admin only. Not a sale; the cost becomes a loss only if the company will not take it back. */
    public function expire(StockLot $lot, User $by): StockLot
    {
        if ($by->account_type !== AccountType::Admin) {
            throw StockException::adminOnly();
        }

        return DB::transaction(function () use ($lot, $by) {
            $lot = StockLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $units = (float) $lot->units_remaining;

            if ($units <= 0 || $lot->expired_at !== null) {
                return $lot;
            }

            $lot->update(['units_remaining' => 0, 'units_expired' => $units, 'expired_at' => now(), 'return_status' => 'pending']);

            StockMovement::create([
                'medicine_id' => $lot->medicine_id,
                'stock_lot_id' => $lot->id,
                'type' => 'expired',
                'units' => -$units,
                'unit_cost' => $lot->unit_cost,
                'user_id' => $by->id,
            ]);

            return $lot->fresh();
        });
    }

    public function setReturnStatus(StockLot $lot, string $status): StockLot
    {
        if ($lot->expired_at === null || ! in_array($status, self::RETURN_STATES, true)) {
            throw new StockException('Only an expired lot has a return status.');
        }

        $lot->update(['return_status' => $status]);

        return $lot;
    }

    /**
     * The expired section, by company: boxes and money per company, and the three states of each batch.
     * "Not taken back" counts as a loss; "pending" is money still hoped for.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function expiredByCompany(): Collection
    {
        return StockLot::query()->whereNotNull('expired_at')->with('medicine.company:id,name')->orderByDesc('expired_at')->get()
            ->groupBy(fn (StockLot $l) => $l->medicine->company?->name ?? '—')
            ->map(function (Collection $lots, string $company) {
                $money = fn (Collection $set) => round((float) $set->sum(fn (StockLot $l) => (float) $l->units_expired * (float) $l->unit_cost), 2);

                return [
                    'company' => $company,
                    'units' => (float) $lots->sum('units_expired'),
                    'value' => $money($lots),
                    'lost' => $money($lots->where('return_status', 'not_taken')),
                    'batches' => $lots->map(fn (StockLot $l) => [
                        'lot_id' => $l->id,
                        'medicine_id' => $l->medicine_id,
                        'brand_name' => $l->medicine->brand_name,
                        'strength' => $l->medicine->strength,
                        'units' => (float) $l->units_expired,
                        'value' => round((float) $l->units_expired * (float) $l->unit_cost, 2),
                        'expiry_date' => $l->expiry_date?->toDateString(),
                        'status' => $l->return_status,
                    ])->values(),
                ];
            })->sortBy('company')->values();
    }
}
