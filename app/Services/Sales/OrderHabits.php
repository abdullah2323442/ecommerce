<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use Illuminate\Support\Carbon;

/**
 * Habit-based reminders (spec ch 7): each shop gets a nudge at the time of day it usually orders, a different time for each.
 *
 * The usual time is worked out from the shop's own online orders of the last three months. Times go round the clock, so
 * 23:50 and 00:10 are close together; if a shop orders at all sorts of hours there is no habit and nothing is sent.
 * Nobody is reminded who turned it off, who already ordered today, or on a holiday or closed day.
 */
class OrderHabits
{
    public function __construct(private Messenger $messages, private OrderNotice $notice) {}

    public function enabled(): bool
    {
        return (bool) Setting::read('habits.enabled', true);
    }

    public function minOrders(): int
    {
        return max(2, (int) Setting::read('habits.min_orders', 3));
    }

    /** Minutes after midnight when this shop usually orders, or null when it has no regular time. */
    public function usualMinute(User $shop): ?int
    {
        $times = Invoice::where('customer_id', $shop->id)->where('channel', 'online')->whereNotNull('placed_at')
            ->where('placed_at', '>=', now()->subDays(90))->pluck('placed_at');

        if ($times->count() < $this->minOrders()) {
            return null;
        }

        $sin = $cos = 0.0;
        foreach ($times as $t) {
            $angle = ($t->hour * 60 + $t->minute) / 1440 * 2 * M_PI;
            $sin += sin($angle);
            $cos += cos($angle);
        }
        $n = $times->count();

        // How tightly the times bunch together: 1 is the same minute every day, 0 is spread evenly round the clock.
        if (hypot($sin / $n, $cos / $n) < 0.6) {
            return null;
        }

        $minute = (int) round(atan2($sin, $cos) / (2 * M_PI) * 1440);

        return ($minute + 1440) % 1440;
    }

    /** "21:30" for the screen. */
    public function usualTime(User $shop): ?string
    {
        $minute = $this->usualMinute($shop);

        return $minute === null ? null : sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    public function dueNow(User $shop, Carbon $now, int $windowMinutes): bool
    {
        if (! $shop->habit_reminders || $shop->isSuspended() || $shop->habit_reminded_on?->isSameDay($now)) {
            return false;
        }

        if ($this->notice->at($now) === 'holiday') {
            return false;
        }

        $usual = $this->usualMinute($shop);
        if ($usual === null) {
            return false;
        }

        $since = ($now->hour * 60 + $now->minute - $usual + 1440) % 1440;
        if ($since >= $windowMinutes) {
            return false;
        }

        return ! Invoice::where('customer_id', $shop->id)->where('channel', 'online')->whereDate('placed_at', $now->toDateString())->exists();
    }

    /** Send the reminders that are due. Returns how many went out. */
    public function remind(?Carbon $now = null, int $windowMinutes = 15): int
    {
        $now ??= now();

        if (! $this->enabled()) {
            return 0;
        }

        $sent = 0;
        User::whereIn('account_type', [AccountType::Paikari->value, AccountType::Wholesaler->value])->where('habit_reminders', true)->whereNull('suspended_at')
            ->whereHas('invoices', fn ($q) => $q->where('channel', 'online')->where('placed_at', '>=', now()->subDays(90)))
            ->each(function (User $shop) use ($now, $windowMinutes, &$sent) {
                if (! $this->dueNow($shop, $now, $windowMinutes)) {
                    return;
                }

                $shop->forceFill(['habit_reminded_on' => $now->toDateString()])->save();
                $this->messages->send($shop, 'order_habit', [], $shop->account_type === AccountType::Wholesaler ? '/wholesale' : '/paikari');
                $sent++;
            });

        return $sent;
    }
}
