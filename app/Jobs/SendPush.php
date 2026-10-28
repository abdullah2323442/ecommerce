<?php

namespace App\Jobs;

use App\Services\Push\Notifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One person's notification, sent away from the request that caused it, so a slow push service never holds up an order. */
class SendPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 40;

    public function __construct(public int $userId, public string $title, public string $body, public ?string $link = null) {}

    public function handle(Notifications $push): void
    {
        $push->deliver($this->userId, $this->title, $this->body, $this->link);
    }
}
