<?php

namespace App\Services\Messages;

use Illuminate\Support\Facades\Log;

/** No provider yet: the text is written to the log, so turning SMS on can be tried without spending anything. */
class LogSmsGateway implements SmsGateway
{
    public function send(string $phone, string $text): void
    {
        Log::info('SMS (no provider set up, not sent)', ['to' => $phone, 'text' => $text]);
    }
}
