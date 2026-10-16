<?php

namespace App\Services\Pricing;

final class PriceRounding
{
    /** Round half-up to 2 decimals (config/pricing.php: rounding). */
    public static function round(float $amount): float
    {
        return round($amount + 1e-9, 2);
    }
}
