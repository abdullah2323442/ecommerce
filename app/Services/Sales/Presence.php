<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\User;

/**
 * Who is working on an order right now (spec ch 7, staff concurrency). A screen that is open tells the server every half
 * minute; anyone else who opens the order sees "Rina is working on this" for as long as that is fresh. Others can still enter.
 */
class Presence
{
    /** How long a heartbeat counts. The screen sends one every 30 seconds. */
    public const FRESH_SECONDS = 90;

    public function touch(Invoice $invoice, User $by): void
    {
        $invoice->forceFill(['working_by' => $by->id, 'working_at' => now()])->saveQuietly();
    }

    /** The name of someone else working on it now, or null. */
    public function otherOn(Invoice $invoice, User $me): ?string
    {
        if ($invoice->working_by === null || $invoice->working_by === $me->id || $invoice->working_at === null || $invoice->working_at->lt(now()->subSeconds(self::FRESH_SECONDS))) {
            return null;
        }

        return User::whereKey($invoice->working_by)->value('name');
    }
}
