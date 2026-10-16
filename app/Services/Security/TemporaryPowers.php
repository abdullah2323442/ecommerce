<?php

namespace App\Services\Security;

use App\Enums\AccountType;
use App\Models\TemporaryPower;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Temporary extra powers for a staff member (spec ch 25): the owner is away and decisions would be stuck, so a sub-admin is given more for a set
 * time. They end by themselves and the owner can take them back at any moment. Managing staff and roles is never lent, so nobody can make
 * themselves or anyone else permanent through it.
 */
class TemporaryPowers
{
    /** Never lent, whatever the time. */
    public const NEVER = ['accounts'];

    public const MAX_DAYS = 30;

    public function __construct(private ActivityLogger $activity, private Messenger $messages) {}

    /** What can be lent: everything on the permission list except the ones that are never lent. */
    public function lendable(): array
    {
        return array_values(array_diff(array_keys((array) config('permissions.catalog')), self::NEVER));
    }

    /**
     * @param  array<int, string>  $permissions
     *
     * @throws StockException
     */
    public function grant(User $staff, array $permissions, Carbon $until, User $by, ?string $note = null): TemporaryPower
    {
        if ($staff->account_type !== AccountType::Staff || $staff->isSuspended()) {
            throw new StockException('Powers can only be lent to a staff member whose account is open.');
        }

        $permissions = array_values(array_unique(array_filter($permissions, fn ($p) => in_array($p, $this->lendable(), true))));

        if ($permissions === []) {
            throw new StockException('Pick at least one thing to lend.');
        }

        if ($until->lte(now()->addMinutes(30))) {
            throw new StockException('Lend them for at least half an hour.');
        }

        if ($until->gt(now()->addDays(self::MAX_DAYS))) {
            throw new StockException('Temporary powers can last at most 30 days. Change their role for longer.');
        }

        $power = TemporaryPower::create([
            'user_id' => $staff->id, 'permissions' => $permissions, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 200) : null,
            'granted_by' => $by->id, 'starts_at' => now(), 'ends_at' => $until,
        ]);
        $this->activity->record('staff.powers_lent', $staff, ['power' => $power->id, 'permissions' => $permissions, 'until' => $until->toIso8601String()], $by);
        $this->messages->send($staff, 'powers_lent', ['name' => implode(', ', array_map(fn ($p) => (string) config("permissions.catalog.$p", $p), $permissions)), 'until' => $until->setTimezone('Asia/Dhaka')->format('d M Y H:i')]);

        return $power;
    }

    public function revoke(TemporaryPower $power, User $by): TemporaryPower
    {
        if ($power->revoked_at === null && $power->ends_at->isFuture()) {
            $power->update(['revoked_at' => now()]);
            $this->activity->record('staff.powers_taken_back', $power->user, ['power' => $power->id], $by);
        }

        return $power->fresh();
    }

    /** Every permission in force for this person right now through a loan. @return list<string> */
    public function activeFor(User $user): array
    {
        return TemporaryPower::active()->where('user_id', $user->id)->get()->flatMap(fn (TemporaryPower $p) => (array) $p->permissions)
            ->filter(fn ($p) => ! in_array($p, self::NEVER, true))->unique()->values()->all();
    }

    /** @return Collection<int, TemporaryPower> in force or coming to an end soon, for the staff page */
    public function current(): Collection
    {
        return TemporaryPower::active()->with('user:id,name', 'grantor:id,name')->orderBy('ends_at')->get();
    }

    public function recentlyEnded(int $limit = 10): Collection
    {
        return TemporaryPower::where(fn ($q) => $q->whereNotNull('revoked_at')->orWhere('ends_at', '<=', now()))->with('user:id,name')->latest('id')->limit($limit)->get();
    }
}
