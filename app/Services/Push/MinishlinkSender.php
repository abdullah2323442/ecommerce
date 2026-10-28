<?php

namespace App\Services\Push;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Sends a notification over the Web Push standard (the browser's own push service, signed with our VAPID key). Works in Chrome, Edge, Firefox and the installed app on Android and iPhone. */
class MinishlinkSender implements PushSender
{
    public function send(array $subscription, string $payload, array $vapid): array
    {
        $push = new WebPush(['VAPID' => ['subject' => $vapid['subject'], 'publicKey' => $vapid['public_key'], 'privateKey' => $vapid['private_key']]], ['TTL' => 86400, 'urgency' => 'normal'], 10);

        $report = $push->sendOneNotification(Subscription::create([
            'endpoint' => $subscription['endpoint'], 'publicKey' => $subscription['p256dh'], 'authToken' => $subscription['auth'], 'contentEncoding' => 'aes128gcm',
        ]), $payload);

        return ['ok' => $report->isSuccess(), 'gone' => $report->isSubscriptionExpired()];
    }
}
