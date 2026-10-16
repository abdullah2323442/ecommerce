<?php

namespace App\Services\Messages;

use App\Services\Integrations\Integrations;

/**
 * The text gateway the rest of the site uses. It follows what the owner set up: a live text service sends for real; none, or one in test mode,
 * only writes to the log, so nothing is spent by mistake.
 */
class ConfiguredSmsGateway implements SmsGateway
{
    public function __construct(private Integrations $integrations, private HttpSmsGateway $http, private LogSmsGateway $log) {}

    public function send(string $phone, string $text): void
    {
        $on = $this->integrations->activeOfType('sms');

        if ($on && $on[0] === 'sms:http' && ($on[1]['mode'] ?? 'test') === 'live') {
            $this->http->send($phone, $text);

            return;
        }

        $this->log->send($phone, $text);
    }
}
