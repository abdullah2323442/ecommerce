<?php

namespace App\Jobs;

use App\Models\SyncEvent;
use App\Services\Sync\PharmaTrack;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One message to PharmaTrack, sent away from the request that caused it. A refusal is tried again after a minute, ten minutes and an hour; after that the owner can send it again from the PharmaTrack screen. */
class PushToPharmaTrack implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public function __construct(public int $eventId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 600, 3600];
    }

    public function handle(PharmaTrack $sync): void
    {
        $event = SyncEvent::find($this->eventId);

        if ($event === null || $event->status === 'ok') {
            return;
        }

        if (! $sync->push($event)) {
            throw new \RuntimeException('PharmaTrack did not accept it.');
        }
    }
}
