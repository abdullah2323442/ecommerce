<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The month-end tier task (spec 4.4.10). A shop is shown, only at the end of the month, how much business it
 * gave this month (online and counter together) and how much more would have reached the next tier. Nothing is
 * live during the month, so nobody force-buys to climb. When the target is met it comes to the admin, who checks
 * and upgrades by hand; the upgrade sends a congratulations notice.
 */
class TierUpgrade
{
    /** The month-end window: the last N days of the month (setting `report.month_end_days`, default 3). */
    public function inWindow(?Carbon $when = null): bool
    {
        $when ??= now();
        $days = max(1, (int) Setting::read('report.month_end_days', 3));

        return $when->day > $when->daysInMonth - $days;
    }

    public function monthTotal(User $customer, ?Carbon $when = null): float
    {
        $when ??= now();

        return round((float) Invoice::where('customer_id', $customer->id)->where('status', 'final')
            ->whereBetween('completed_at', [$when->copy()->startOfMonth(), $when->copy()->endOfMonth()])->sum('total'), 2);
    }

    /** The next tier up that can be earned by buying: higher discount than now, and a target set. */
    public function nextTier(User $customer): ?Tier
    {
        $now = (float) ($customer->tier?->discount_percent ?? -1);

        return Tier::whereNotNull('upgrade_target')->where('discount_percent', '>', $now)->orderBy('discount_percent')->first();
    }

    /** @return array{tier: string, target: float, gap: float}|null */
    public function task(User $customer): ?array
    {
        $next = $this->nextTier($customer);

        if (! $next) {
            return null;
        }

        $target = (float) $next->upgrade_target;

        return ['tier' => $next->name, 'target' => $target, 'gap' => max(0.0, round($target - $this->monthTotal($customer), 2))];
    }

    /**
     * Shops that have met the next tier's target this month, waiting for the admin to check and upgrade.
     *
     * @return list<array{id: int, name: string, phone: ?string, tier: ?string, next_tier_id: int, next_tier: string, total: float, target: float}>
     */
    public function candidates(): array
    {
        $out = [];

        foreach (User::where('account_type', AccountType::Paikari->value)->whereNull('suspended_at')->with('tier')->get() as $shop) {
            $next = $this->nextTier($shop);

            if (! $next) {
                continue;
            }

            $total = $this->monthTotal($shop);

            if ($total >= (float) $next->upgrade_target) {
                $out[] = ['id' => $shop->id, 'name' => $shop->name, 'phone' => $shop->phone, 'tier' => $shop->tier?->name, 'next_tier_id' => $next->id, 'next_tier' => $next->name, 'total' => $total, 'target' => (float) $next->upgrade_target];
            }
        }

        return $out;
    }
}
