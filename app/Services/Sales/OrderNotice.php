<?php

namespace App\Services\Sales;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Orders are never refused (spec 4.4.8): there is no cut-off. At night or on a closed day the shop is only
 * told when the order will be looked at. Settings: orders.weekly_off (weekday numbers, 0 = Sunday, default
 * Friday), orders.holidays (dates), orders.night_from / orders.night_to (hours).
 */
class OrderNotice
{
    /** @return 'holiday'|'night'|null */
    public function at(?Carbon $when = null): ?string
    {
        $when ??= now();

        $off = (array) Setting::read('orders.weekly_off', [5]);
        $holidays = (array) Setting::read('orders.holidays', []);

        if (in_array($when->dayOfWeek, $off, true) || in_array($when->toDateString(), $holidays, true)) {
            return 'holiday';
        }

        $from = (int) Setting::read('orders.night_from', 22);
        $to = (int) Setting::read('orders.night_to', 8);
        $hour = $when->hour;

        return ($from > $to ? ($hour >= $from || $hour < $to) : ($hour >= $from && $hour < $to)) ? 'night' : null;
    }
}
