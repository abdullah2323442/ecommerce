<?php

namespace App\Services\Pricing;

use App\Enums\AccountType;
use App\Models\CompanyRate;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\MedicineRate;
use App\Models\User;
use App\Services\Sales\TierOffers;

/**
 * Resolves the effective discount for one customer on one medicine (spec ch 04).
 *
 * Priority, highest first. The first layer that has a rate wins; lower layers are ignored:
 *   1. customer manual rate  (CustomerRate)
 *      (a live tier offer, spec 9.2, sits just under this: it can only improve a paikari buyer's own rate)
 *   2. medicine override     (MedicineRate)
 *   3. company rate          (CompanyRate)
 *   4. tier default          (Tier)
 *
 * Taka amounts are converted to a percentage of MRP, so every result is comparable.
 */
class PriceResolver
{
    public const LAYER_MANUAL = 'customer_manual';

    public const LAYER_MEDICINE = 'medicine';

    public const LAYER_COMPANY = 'company';

    public const LAYER_TIER = 'tier';

    public const LAYER_NONE = 'none';

    public const LAYER_OFFER = 'offer';

    public function __construct(private TierOffers $offers) {}

    public function resolve(User $customer, Medicine $medicine): DiscountResult
    {
        $manual = CustomerRate::query()->where('user_id', $customer->id)->where('medicine_id', $medicine->id)->first();
        if ($manual && $this->hasRate($manual)) {
            return $this->fromRate($manual, $medicine, self::LAYER_MANUAL);
        }

        $base = $this->resolveBase($customer, $medicine);

        // A tier offer reaches every paikari buyer without a manual rate, and never makes their price worse.
        if ($customer->account_type === AccountType::Paikari) {
            $offer = $this->offers->liveFor($medicine);

            if ($offer && (float) $offer->percent > $base->percent) {
                return new DiscountResult((float) $offer->percent, self::LAYER_OFFER);
            }
        }

        return $base;
    }

    private function resolveBase(User $customer, Medicine $medicine): DiscountResult
    {
        $override = MedicineRate::query()->where('medicine_id', $medicine->id)->first();
        if ($override && $this->hasRate($override)) {
            return $this->fromRate($override, $medicine, self::LAYER_MEDICINE);
        }

        $company = CompanyRate::query()->where('company_id', $medicine->company_id)->first();
        if ($company && $company->discount_percent !== null) {
            return new DiscountResult((float) $company->discount_percent, self::LAYER_COMPANY);
        }

        $tierPercent = $customer->tier?->discount_percent;
        if ($tierPercent !== null) {
            return new DiscountResult((float) $tierPercent, self::LAYER_TIER);
        }

        return new DiscountResult(0.0, self::LAYER_NONE);
    }

    /** Net price after the layer discount, rounded per config/pricing.php. */
    public function netPrice(User $customer, Medicine $medicine, float $mrp): float
    {
        $discount = $this->resolve($customer, $medicine);

        return PriceRounding::round($mrp * (1 - $discount->percent / 100));
    }

    private function hasRate(CustomerRate|MedicineRate $rate): bool
    {
        return $rate->discount_percent !== null || $rate->fixed_price !== null;
    }

    private function fromRate(CustomerRate|MedicineRate $rate, Medicine $medicine, string $layer): DiscountResult
    {
        if ($rate->discount_percent !== null) {
            return new DiscountResult((float) $rate->discount_percent, $layer);
        }

        $mrp = (float) $medicine->mrp_piece;
        if ($mrp <= 0) {
            return new DiscountResult(0.0, $layer);
        }

        $percentOff = (1 - ((float) $rate->fixed_price / $mrp)) * 100;

        return new DiscountResult(round(max(0.0, $percentOff), 2), $layer);
    }
}
