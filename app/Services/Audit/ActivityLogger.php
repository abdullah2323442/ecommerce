<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

/** Writes one append-only row per important action (spec ch 25: who, what, when). */
class ActivityLogger
{
    public function record(string $action, ?Model $subject = null, array $changes = [], ?User $actor = null): ActivityLog
    {
        return ActivityLog::create([
            'actor_id' => ($actor ?? auth()->user())?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'changes' => $changes ?: null,
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
