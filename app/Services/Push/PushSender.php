<?php

namespace App\Services\Push;

/** Whatever actually carries one notification to one browser. Kept apart so the rest of the site never needs the network to be tested. */
interface PushSender
{
    /**
     * @param  array{endpoint: string, p256dh: string, auth: string}  $subscription
     * @param  array{subject: string, public_key: string, private_key: string}  $vapid
     * @return array{ok: bool, gone: bool} gone means the browser dropped the subscription, so it should be forgotten
     */
    public function send(array $subscription, string $payload, array $vapid): array;
}
