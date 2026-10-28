<?php

namespace App\Services\Sales;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The month a report covers. Most months start on the 1st; a business that closes its books on another day sets that day (spec ch 06 monthly report,
 * item 596), and then "October" runs from that day in October to the day before it in November. A month is named by the month it starts in.
 */
class ReportPeriod
{
    public function startDay(): int
    {
        return min(28, max(1, (int) Setting::read('report.month_start_day', 1)));
    }

    /** The month whose period holds this day, as the first of that month. */
    public function labelFor(?Carbon $day = null): Carbon
    {
        $day = ($day ?? now())->copy();
        $label = $day->copy()->startOfMonth();

        return $day->day < $this->startDay() ? $label->subMonthNoOverflow() : $label;
    }

    /** @return array{0: Carbon, 1: Carbon} first moment and last moment of the period named for this month */
    public function range(Carbon $label): array
    {
        $from = $label->copy()->startOfMonth()->day($this->startDay())->startOfDay();
        $to = $from->copy()->addMonthNoOverflow()->subDay()->endOfDay();

        return [$from, $to];
    }

    /** The label of the period before this one. */
    public function before(Carbon $label): Carbon
    {
        return $label->copy()->startOfMonth()->subMonthNoOverflow();
    }
}
