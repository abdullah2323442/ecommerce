<?php

namespace App\Jobs;

use App\Services\Messages\ConfiguredSmsGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One text message, sent away from the buyer's request. During a festival rush a slow text service must not hold up checkout (spec ch 28: many orders at
 * once), so the order is saved first and the message follows. A failed send is tried again after half a minute, two minutes and ten minutes.
 */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 25;

    public function __construct(public string $phone, public string $text) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(ConfiguredSmsGateway $gateway): void
    {
        $gateway->send($this->phone, $this->text);
    }

    /** Gave up. The number is kept out of the log except for its last digits. */
    public function failed(Throwable $e): void
    {
        Log::warning('A text message could not be sent after several tries.', ['to' => '*******'.substr($this->phone, -3), 'why' => class_basename($e)]);
    }
}
