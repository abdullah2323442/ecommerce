<?php

namespace App\Services\Gaming;

use App\Models\GameOrder;

/**
 * Sends the diamonds to the player once the payment is in (spec ch 18). 'manual' means no provider is connected: the order waits in the admin
 * panel and we top up by hand, which is the backup the spec asks for.
 */
interface TopUpProvider
{
    /** @return array{status: 'done'|'failed'|'manual', ref?: string, reason?: string} */
    public function send(GameOrder $order): array;
}
