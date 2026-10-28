<?php

namespace App\Jobs;

use App\Services\Marketing\BulkSms;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One person's text in a bulk campaign. The sending and the writing down of how it went are in BulkSms::deliver; a failure is kept on the person, not retried, so nobody gets a text twice. */
class SendCampaignSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25;

    public function __construct(public int $recipientId) {}

    public function handle(BulkSms $bulk): void
    {
        $bulk->deliver($this->recipientId);
    }
}
