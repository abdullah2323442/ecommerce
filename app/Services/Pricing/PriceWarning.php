<?php

namespace App\Services\Pricing;

use App\Models\Setting;

/**
 * A price entered wrong (spec ch 04): 50 instead of 500, and someone buys it. A price far below the one before it, or zero, is not refused but
 * must be confirmed: "This is 90% lower than the previous price. Are you sure?" How far counts as far is a setting (`prices.warn_drop_percent`).
 */
class PriceWarning
{
    public function threshold(): int
    {
        return max(10, min(99, (int) Setting::read('prices.warn_drop_percent', 70)));
    }

    /**
     * Is the new price a worry, and how far down is it? Returns the percent lower (100 for zero), or null when it is fine.
     * A first price (nothing before it), or a price that went up, is never a worry.
     */
    public function drop(?float $before, ?float $after): ?int
    {
        if ($before === null || $before <= 0 || $after === null) {
            return null;
        }

        if ($after <= 0) {
            return 100;
        }

        $drop = (int) round((1 - $after / $before) * 100);

        return $drop >= $this->threshold() ? $drop : null;
    }

    public function message(int $drop): string
    {
        return $drop >= 100
            ? __('This sets the price to zero. Are you sure?')
            : __('This is {n}% lower than the previous price. Are you sure?', ['n' => $drop]);
    }
}
