<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\TemporaryPower;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\Bin;
use App\Services\Security\TemporaryPowers;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff accounts and roles (spec ch 26). Every staff member has their own account, a role decides what they may do,
 * and closing an account keeps every memo and record they made. Changes here need the password again.
 */
class StaffController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(TemporaryPowers $powers): Response
    {
        $this->authorize('manage-accounts');

        return Inertia::render('admin/staff', [
            'staff' => User::where('account_type', AccountType::Staff->value)->with('role:id,name')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone, 'role_id' => $u->role_id, 'role' => $u->role?->name,
                'discount_limit' => $u->discount_limit !== null ? (float) $u->discount_limit : null, 'closed' => $u->suspended_at !== null,
                'two_factor' => app(\App\Services\Security\TwoFactor::class)->enabled($u),
            ]),
            'roles' => Role::withCount('users')->orderBy('name')->get()->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name, 'permissions' => $r->permissions, 'system' => $r->is_system, 'users' => $r->users_count]),
            'catalog' => config('permissions.catalog'),
            // Lent for a while (spec ch 25).
            'lendable' => $powers->lendable(),
            'lent' => $powers->current()->map(fn ($p) => ['id' => $p->id, 'who' => $p->user->name, 'permissions' => $p->permissions, 'until' => $p->ends_at->toIso8601String(), 'note' => $p->note, 'by' => $p->grantor?->name]),
            'ended' => $powers->recentlyEnded()->map(fn ($p) => ['id' => $p->id, 'who' => $p->user->name, 'permissions' => $p->permissions, 'ended' => ($p->revoked_at ?? $p->ends_at)->toIso8601String(), 'taken_back' => $p->revoked_at !== null]),
        ]);
    }

    /** Lend a staff member extra powers for a set time (spec ch 25). */
    public function lend(Request $request, User $user, TemporaryPowers $powers): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['permissions' => ['required', 'array', 'min:1'], 'permissions.*' => ['string'], 'hours' => ['required', 'integer', 'min:1', 'max:'.(TemporaryPowers::MAX_DAYS * 24)], 'note' => ['nullable', 'string', 'max:200']]);

        try {
            $powers->grant($user, $data['permissions'], now()->addHours((int) $data['hours']), $request->user(), $data['note'] ?? null);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['lend' => __($e->getMessage())]);
        }

        return back()->with('status', __('Lent. It ends by itself.'));
    }

    public function takeBack(Request $request, TemporaryPower $power, TemporaryPowers $powers): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $powers->revoke($power->load('user'), $request->user());

        return back()->with('status', __('Taken back.'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'password' => ['required', 'string', 'min:10', 'max:100'],
        ]);

        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => Hash::make($data['password'])]);
        $user->forceFill(['account_type' => AccountType::Staff, 'role_id' => $data['role_id'], 'email_verified_at' => now()])->save();
        $this->activity->record('staff.created', $user, ['role_id' => $data['role_id']], $request->user());

        return back()->with('status', __('Staff account created.'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $this->ensureStaff($user);
        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'discount_limit' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $before = ['role_id' => $user->role_id, 'discount_limit' => $user->discount_limit];
        $user->forceFill(['role_id' => $data['role_id'], 'discount_limit' => $data['discount_limit'] ?? null])->save();
        $this->activity->record('staff.updated', $user, ['before' => $before, 'after' => $data], $request->user());

        return back()->with('status', __('Saved.'));
    }

    /** A staff member who left: the account is closed, but their memos and records stay (spec ch 26). */
    public function close(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $this->ensureStaff($user);
        $user->forceFill(['suspended_at' => now(), 'suspended_until' => null, 'suspend_reason' => 'Account closed'])->save();
        $this->activity->record('staff.closed', $user, [], $request->user());

        return back()->with('status', __('Account closed. Their memos and records stay.'));
    }

    public function reopen(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $this->ensureStaff($user);
        $user->forceFill(['suspended_at' => null, 'suspended_until' => null, 'suspend_reason' => null])->save();
        $this->activity->record('staff.reopened', $user, [], $request->user());

        return back()->with('status', __('Account reopened.'));
    }

    // ---------------------------------------------------------------- roles

    public function storeRole(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')],
            'permissions' => ['array'],
            'permissions.*' => ['in:'.implode(',', array_keys(config('permissions.catalog')))],
        ]);

        $role = Role::create(['name' => $data['name'], 'permissions' => array_values($data['permissions'] ?? [])]);
        $this->activity->record('role.created', $role, ['permissions' => $role->permissions], $request->user());

        return back()->with('status', __('Role created.'));
    }

    /** Switch permissions on and off one by one. Takes effect at once for everyone in the role. */
    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['in:'.implode(',', array_keys(config('permissions.catalog')))],
        ]);

        $before = $role->permissions;
        $role->update(['name' => $data['name'], 'permissions' => array_values($data['permissions'] ?? [])]);
        $this->activity->record('role.updated', $role, ['before' => $before, 'after' => $role->permissions], $request->user());

        return back()->with('status', __('Role saved.'));
    }

    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('manage-accounts');

        if ($role->is_system || $role->users()->exists()) {
            return back()->withErrors(['role' => __('A starting role, or a role someone still has, cannot be deleted.')]);
        }

        app(Bin::class)->keep($role, $request->user());
        $role->delete();
        $this->activity->record('role.deleted', $role, [], $request->user());

        return back()->with('status', __('Role deleted.'));
    }

    // ---------------------------------------------------------------- the log

    /** Who did what, and who opened whose data (spec ch 26: a log of who viewed whose data and when). */
    public function activity(Request $request): Response
    {
        $this->authorize('manage-accounts');
        $filter = trim((string) $request->query('q', ''));

        $rows = ActivityLog::query()->when($filter !== '', fn ($q) => $q->where('action', 'like', '%'.addcslashes($filter, '%_\\').'%'))
            ->latest('id')->paginate(40)->withQueryString();
        $names = User::whereIn('id', $rows->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('admin/activity', [
            'rows' => $rows->through(fn (ActivityLog $l) => [
                'id' => $l->id, 'action' => $l->action, 'actor' => $names[$l->actor_id] ?? null, 'subject' => class_basename((string) $l->subject_type).($l->subject_id ? ' #'.$l->subject_id : ''),
                'when' => $l->created_at?->toIso8601String(), 'changes' => $l->changes,
            ]),
            'q' => $filter,
        ]);
    }

    private function ensureStaff(User $user): void
    {
        abort_unless($user->account_type === AccountType::Staff, 404);
    }
}
