<?php

namespace App\Services\Sales;

use App\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reports prepared ahead of time (spec ch 25 dev note: big reports are generated overnight, ready instantly in the morning).
 * A closed month is read from its kept copy. The month in progress is read from the copy made last night or later today; an older copy is made again
 * on the spot, so the owner never reads a report that is a day behind without knowing it.
 */
class ReportSnapshots
{
    public const KIND = 'monthly';

    public function __construct(private MonthlyReport $monthly, private ReportPeriod $period) {}

    /** The report for this month: the kept copy when it is good enough, otherwise one made now. */
    public function monthly(Carbon $label): ReportSnapshot
    {
        $kept = $this->find($label);
        $current = $label->format('Y-m') === $this->period->labelFor()->format('Y-m');

        if ($kept && (! $current || $kept->prepared_at->gte(today()))) {
            return $kept;
        }

        return $this->prepare($label);
    }

    public function find(Carbon $label): ?ReportSnapshot
    {
        return ReportSnapshot::where(['kind' => self::KIND, 'period' => $label->format('Y-m'), 'start_day' => $this->period->startDay()])->first();
    }

    public function prepare(Carbon $label, ?User $by = null): ReportSnapshot
    {
        $payload = json_decode(json_encode($this->monthly->build($label)), true);

        return ReportSnapshot::updateOrCreate(
            ['kind' => self::KIND, 'period' => $label->format('Y-m'), 'start_day' => $this->period->startDay()],
            ['payload' => $payload, 'prepared_at' => now(), 'prepared_by' => $by?->id],
        );
    }

    /** The night's work: this month's report and last month's (it can still change while corrections and late payments come in). @return int how many were prepared */
    public function prepareTonight(): int
    {
        $now = $this->period->labelFor();
        $this->prepare($now);
        $this->prepare($this->period->before($now));

        return 2;
    }
}
