<?php

namespace App\Services\Security;

use App\Enums\AccountType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Staff accounts can be signed in on only so many devices at once (spec ch 26: a separate account for every person,
 * and a limit on devices). When a new sign-in would go over the limit, the devices that were used longest ago are signed
 * out. Sessions live in the database, so this is a delete, not a guess. The limit is a setting; customers are not limited.
 */
class SessionLimiter
{
    public const DEFAULT = 3;

    public function __construct(private ActivityLogger $activity) {}

    public function limit(): int
    {
        return max(1, (int) Setting::read('security.session_limit', self::DEFAULT));
    }

    /** Called just before the new sign-in is saved: keep the most recent (limit - 1) old sessions and drop the rest. */
    public function enforce(User $user): int
    {
        if ($user->account_type !== AccountType::Staff) {
            return 0;
        }

        $old = DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->pluck('id')->slice($this->limit() - 1);

        if ($old->isEmpty()) {
            return 0;
        }

        DB::table('sessions')->whereIn('id', $old->all())->delete();
        $this->activity->record('security.signed_out_old_devices', $user, ['count' => $old->count(), 'limit' => $this->limit()], $user);

        return $old->count();
    }
}
