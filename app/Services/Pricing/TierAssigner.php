<?php

namespace App\Services\Pricing;

use App\Models\Tier;
use App\Models\TierChange;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;

/** Moves a customer between tiers and records who changed what (spec PRC-3). Manual rates are untouched (PRC-2). */
class TierAssigner
{
    public function __construct(private ActivityLogger $activity) {}

    public function assign(User $customer, ?Tier $to, ?User $changedBy): void
    {
        DB::transaction(function () use ($customer, $to, $changedBy) {
            $from = $customer->tier_id;
            $customer->forceFill(['tier_id' => $to?->id])->save();

            TierChange::create([
                'user_id' => $customer->id,
                'changed_by' => $changedBy?->id,
                'from_tier_id' => $from,
                'to_tier_id' => $to?->id,
                'created_at' => now(),
            ]);

            $this->activity->record('customer.tier_changed', $customer, [
                'from_tier_id' => $from,
                'to_tier_id' => $to?->id,
            ], $changedBy);
        });
    }
}
