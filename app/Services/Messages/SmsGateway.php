<?php

namespace App\Services\Messages;

/** Sends one text message. The real provider is chosen later (SMS and OTP are deferred); until then messages go to the log. */
interface SmsGateway
{
    public function send(string $phone, string $text): void;
}
