<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Buy again" (spec ch 13): someone who buys the same medicine every month or so is reminded when the next one is due. The rhythm is
 * worked out from their own finished orders: at least three purchases on different days, about as far apart each time. One reminder
 * for each purchase, never for a controlled medicine, and never to someone who turned it off.
 */
class BuyAgain
{
    private const PER_RUN = 3;

    public function __construct(private Messenger $messages) {}

    public function enabled(): bool
    {
        return (bool) Setting::read('buyagain.enabled', true);
    }

    public function minBuys(): int
    {
        return max(3, (int) Setting::read('buyagain.min_buys', 3));
    }

    /**
     * The medicines this buyer takes on a regular rhythm: medicine id => the usual days between buys.
     *
     * @return array<int, array{every: int, last: Carbon}>
     */
    public function rhythms(User $user): array
    {
        $rows = DB::table('invoice_lines as l')
            ->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->join('medicines as m', 'm.id', '=', 'l.medicine_id')
            ->where('i.customer_id', $user->id)->where('i.status', 'final')->where('i.finalised_at', '>=', now()->subDays(240))
            ->whereNull('l.removed_at')->where('l.is_outside', false)->where('m.is_active', true)->where('m.is_controlled', false)->whereNull('m.deleted_at')
            ->select('l.medicine_id', DB::raw('date(i.finalised_at) as day'))->distinct()->orderBy('day')->get()->groupBy('medicine_id');

        $out = [];
        foreach ($rows as $medicineId => $group) {
            $days = $group->pluck('day')->map(fn ($d) => Carbon::parse($d))->sort()->values();
            if ($days->count() < $this->minBuys()) {
                continue;
            }

            $gaps = [];
            for ($i = 1; $i < $days->count(); $i++) {
                $gaps[] = $days[$i - 1]->diffInDays($days[$i]);
            }
            $every = array_sum($gaps) / count($gaps);

            // About monthly: not every day, not once a year, and each gap close to the usual one.
            if ($every < 10 || $every > 60 || max(array_map(fn ($g) => abs($g - $every), $gaps)) > $every * 0.5) {
                continue;
            }

            $out[(int) $medicineId] = ['every' => (int) round($every), 'last' => $days->last()];
        }

        return $out;
    }

    /** Whether a reminder is due for this rhythm on this day: when the next one is about due, once for each purchase. */
    private function due(array $rhythm, int $medicineId, User $user, Carbon $now): bool
    {
        $since = $rhythm['last']->diffInDays($now->copy()->startOfDay());
        if ($since < $rhythm['every'] - 2 || $since > $rhythm['every'] + 14) {
            return false;
        }

        return ! DB::table('buy_again_reminders')->where('user_id', $user->id)->where('medicine_id', $medicineId)->where('reminded_at', '>', $rhythm['last']->copy()->endOfDay())->exists();
    }

    /** Send the reminders that are due today. Returns how many went out. */
    public function remind(?Carbon $now = null): int
    {
        $now ??= now();
        if (! $this->enabled()) {
            return 0;
        }

        $sent = 0;
        User::where('account_type', AccountType::Public->value)->where('buy_again', true)->whereNull('suspended_at')
            ->whereHas('invoices', fn ($q) => $q->where('status', 'final')->where('finalised_at', '>=', now()->subDays(240)))
            ->each(function (User $user) use ($now, &$sent) {
                $count = 0;
                foreach ($this->rhythms($user) as $medicineId => $rhythm) {
                    if ($count >= self::PER_RUN || ! $this->due($rhythm, $medicineId, $user, $now)) {
                        continue;
                    }

                    $medicine = Medicine::find($medicineId);
                    DB::table('buy_again_reminders')->insert(['user_id' => $user->id, 'medicine_id' => $medicineId, 'reminded_at' => $now]);
                    $this->messages->send($user, 'buy_again', ['name' => $medicine->brand_name, 'days' => $rhythm['every']], '/medicines/'.$medicineId);
                    $count++;
                    $sent++;
                }
            });

        return $sent;
    }
}
