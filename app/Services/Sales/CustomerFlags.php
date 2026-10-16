<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;

/**
 * Problem-customer flagging (spec 4.4.9). An order cancelled after staff started picking it (or packed and never collected) costs
 * the counter work, so those are counted over the last 90 days. At the limit (setting `flags.cancel_limit`,
 * default 3) the shop is flagged for staff and admin. Nothing is blocked and the shop is never told.
 */
class CustomerFlags
{
    public function lateCancellations(User $customer): int
    {
        return Invoice::where('customer_id', $customer->id)->where('channel', 'online')->where('status', 'cancelled')
            ->whereHas('allLines', fn ($l) => $l->whereIn('verify_status', ['full', 'partial']))
            ->where('cancelled_at', '>=', now()->subDays(90))->count();
    }

    public function limit(): int
    {
        return max(1, (int) Setting::read('flags.cancel_limit', 3));
    }

    public function isFlagged(User $customer): bool
    {
        return $this->lateCancellations($customer) >= $this->limit();
    }
}
