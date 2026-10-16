<?php

namespace App\Services\Messages;

use App\Jobs\SendSms;

/** What the rest of the site talks to: it only puts the message in the queue and returns at once. The real sending is done by the SendSms job. */
class QueuedSmsGateway implements SmsGateway
{
    public function send(string $phone, string $text): void
    {
        SendSms::dispatch($phone, $text);
    }
}
