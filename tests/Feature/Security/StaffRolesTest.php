<?php

namespace Tests\Feature\Security;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Notice;
use App\Models\Role;
use App\Models\User;
use App\Services\Sales\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function staff(?string $role = null, string $name = 'Robiul'): User
    {
        $u = User::factory()->create(['account_type' => AccountType::Staff, 'name' => $name]);

        if ($role) {
            $u->forceFill(['role_id' => Role::where('name', $role)->value('id')])->save();
        }

        return $u->fresh();
    }

    private function confirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_the_starting_roles_exist_and_a_staff_account_with_no_role_is_counter_staff(): void
    {
        $this->assertSame(['Counter staff', 'Delivery person', 'Order manager', 'Product manager', 'Sub-admin', 'Wholesale manager'], Role::orderBy('name')->pluck('name')->all());

        $plain = $this->staff();
        $this->assertTrue($plain->hasPermission('counter'));
        $this->assertFalse($plain->hasPermission('reports'), 'staff do not see sales and profit');
        $this->assertFalse($plain->hasPermission('stock'));
    }

    public function test_the_super_admin_can_do_everything_and_a_suspended_one_nothing(): void
    {
        $this->assertTrue($this->admin->can('manage-accounts'));
        $this->assertSame(array_keys(config('permissions.catalog')), $this->admin->permissions());

        $this->admin->forceFill(['suspended_at' => now()])->save();
        $this->assertFalse($this->admin->fresh()->can('manage-accounts'));
    }

    public function test_a_sub_admin_sees_sales_and_profit_but_cannot_manage_staff(): void
    {
        $sub = $this->staff('Sub-admin', 'Sub');

        $this->actingAs($sub)->get('/admin/reports')->assertOk();
        $this->get('/admin/stock')->assertOk();
        $this->get('/admin/staff')->assertRedirect('/');
        $this->get('/admin/activity')->assertRedirect('/');
    }

    public function test_counter_staff_never_see_reports_stock_offers_or_approvals(): void
    {
        $this->actingAs($this->staff());

        foreach (['/admin/reports', '/admin/stock', '/admin/offers', '/admin/approvals', '/admin/day', '/admin/staff'] as $url) {
            $this->get($url)->assertRedirect('/');
        }
        $this->get('/admin/counter')->assertOk();
        $this->get('/admin/returns')->assertOk();
        $this->get('/admin/orders')->assertOk();
    }

    public function test_each_role_gets_only_its_own_screens(): void
    {
        $this->actingAs($this->staff('Product manager'))->get('/admin/stock')->assertOk();
        $this->get('/admin/offers')->assertOk();
        $this->get('/admin/counter')->assertRedirect('/');
        $this->get('/admin/customers')->assertRedirect('/');

        $this->actingAs($this->staff('Wholesale manager'))->get('/admin/customers')->assertOk();
        $this->get('/admin/stock')->assertRedirect('/');
        $this->get('/admin/counter')->assertRedirect('/');
    }

    public function test_a_sub_admin_can_answer_approvals_and_is_the_one_told(): void
    {
        $sub = $this->staff('Sub-admin', 'Sub');
        $this->assertTrue($sub->can('decide-approvals'));

        $ids = User::withPermission('approvals')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->admin->id, $sub->id], $ids);
        $this->assertNotContains($this->staff()->id, $ids);
    }

    public function test_the_admin_builds_a_new_role_and_switches_permissions_one_by_one(): void
    {
        $this->actingAs($this->admin);
        $this->confirmed()->post('/admin/roles', ['name' => 'Night shift', 'permissions' => ['counter', 'reports']])->assertSessionHasNoErrors();
        $role = Role::where('name', 'Night shift')->firstOrFail();
        $this->assertSame(['counter', 'reports'], $role->permissions);

        $worker = $this->staff();
        $worker->forceFill(['role_id' => $role->id])->save();
        $this->assertTrue($worker->fresh()->hasPermission('reports'));

        $this->confirmed()->put("/admin/roles/{$role->id}", ['name' => 'Night shift', 'permissions' => ['counter']])->assertSessionHasNoErrors();
        $this->assertFalse($worker->fresh()->hasPermission('reports'), 'takes effect at once');
        $this->assertDatabaseHas('activity_logs', ['action' => 'role.updated']);
    }

    public function test_an_unknown_permission_is_refused(): void
    {
        $this->actingAs($this->admin);
        $this->confirmed()->post('/admin/roles', ['name' => 'Odd', 'permissions' => ['everything']])->assertSessionHasErrors('permissions.0');
    }

    public function test_a_starting_role_or_a_role_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin);
        $system = Role::where('name', 'Sub-admin')->firstOrFail();
        $this->confirmed()->delete("/admin/roles/{$system->id}")->assertSessionHasErrors('role');

        $mine = Role::create(['name' => 'Temp', 'permissions' => ['counter']]);
        $this->confirmed()->delete("/admin/roles/{$mine->id}")->assertSessionHasNoErrors();
        $this->assertNull(Role::find($mine->id));
    }

    public function test_the_admin_opens_a_staff_account_with_its_own_login_and_role(): void
    {
        $this->actingAs($this->admin);
        $role = Role::where('name', 'Order manager')->firstOrFail();

        $this->confirmed()->post('/admin/staff', ['name' => 'Shafiq', 'email' => 'shafiq@example.test', 'phone' => '01955000000', 'role_id' => $role->id, 'password' => 'a-long-password-1'])->assertSessionHasNoErrors();

        $u = User::where('email', 'shafiq@example.test')->firstOrFail();
        $this->assertSame(AccountType::Staff, $u->account_type);
        $this->assertSame($role->id, $u->role_id);
        $this->post('/logout');
        $this->post('/login', ['email' => 'shafiq@example.test', 'password' => 'a-long-password-1']);
        $this->assertAuthenticatedAs($u);
    }

    public function test_a_weak_password_or_a_used_email_is_refused(): void
    {
        $this->actingAs($this->admin);
        $role = Role::first();
        $this->confirmed()->post('/admin/staff', ['name' => 'X', 'email' => $this->admin->email, 'role_id' => $role->id, 'password' => 'short'])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_changes_need_the_password_again(): void
    {
        $this->actingAs($this->admin)->post('/admin/roles', ['name' => 'Sneaky', 'permissions' => ['reports']])->assertRedirect(route('password.confirm'));
        $this->assertNull(Role::where('name', 'Sneaky')->first());
    }

    public function test_a_per_staff_discount_limit_is_set_here_and_a_role_change_applies(): void
    {
        $u = $this->staff();
        $sub = Role::where('name', 'Sub-admin')->firstOrFail();

        $this->actingAs($this->admin);
        $this->confirmed()->put("/admin/staff/{$u->id}", ['role_id' => $sub->id, 'discount_limit' => 8])->assertSessionHasNoErrors();

        $this->assertSame('8.00', (string) $u->fresh()->discount_limit);
        $this->assertTrue($u->fresh()->hasPermission('reports'));
    }

    public function test_closing_an_account_keeps_the_records_and_blocks_the_work(): void
    {
        $u = $this->staff();
        $this->actingAs($this->admin);
        $this->confirmed()->post("/admin/staff/{$u->id}/close")->assertSessionHasNoErrors();

        $this->assertNotNull(User::find($u->id), 'the account and so its memos stay');
        $this->actingAs($u->fresh())->get('/admin/counter')->assertRedirect('/');

        $this->actingAs($this->admin);
        $this->confirmed()->post("/admin/staff/{$u->id}/reopen");
        $this->actingAs($u->fresh())->get('/admin/counter')->assertOk();
    }

    public function test_only_staff_accounts_are_managed_here(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($this->admin);
        $this->confirmed()->post("/admin/staff/{$shop->id}/close")->assertNotFound();
        $this->assertFalse($shop->fresh()->isSuspended());
    }

    public function test_a_suspension_for_days_lifts_by_itself(): void
    {
        Carbon::setTestNow('2026-10-13 10:00');
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($this->admin)->confirmed()->post("/admin/customers/{$shop->id}/suspend", ['reason' => 'Late payment', 'days' => 3])->assertSessionHasNoErrors();

        $this->assertTrue($shop->fresh()->isSuspended());
        $this->assertSame('Late payment', $shop->fresh()->suspend_reason);

        Carbon::setTestNow('2026-10-15 10:00');
        $this->assertTrue($shop->fresh()->isSuspended());
        Carbon::setTestNow('2026-10-16 11:00');
        $this->assertFalse($shop->fresh()->isSuspended(), 'three days are over');
        Carbon::setTestNow();
    }

    public function test_a_suspension_without_days_stays_until_someone_reopens_it(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($this->admin)->confirmed()->post("/admin/customers/{$shop->id}/suspend", ['reason' => 'Fraud']);
        Carbon::setTestNow(now()->addYear());
        $this->assertTrue($shop->fresh()->isSuspended());
        Carbon::setTestNow();

        $this->delete("/admin/customers/{$shop->id}/suspend");
        $this->assertFalse($shop->fresh()->isSuspended());
    }

    public function test_the_scheduled_tidy_clears_expired_suspensions(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $shop->forceFill(['suspended_at' => now()->subDays(5), 'suspended_until' => now()->subDay(), 'suspend_reason' => 'x'])->save();

        $this->artisan('schedule:test', ['--name' => 'accounts:lift-suspensions'])->assertSuccessful();
        $this->assertNull($shop->fresh()->suspended_at);
    }

    public function test_who_opened_whose_data_is_logged_for_staff_but_not_for_the_owner(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim']);
        $wm = $this->staff('Wholesale manager', 'Wasim');

        $this->actingAs($this->admin)->get("/admin/customers/{$shop->id}")->assertOk();
        $this->assertSame(0, ActivityLog::where('action', 'customer.viewed')->count());

        $this->actingAs($wm)->get("/admin/customers/{$shop->id}")->assertOk();
        $log = ActivityLog::where('action', 'customer.viewed')->firstOrFail();
        $this->assertSame($wm->id, $log->actor_id);
        $this->assertSame($shop->id, $log->subject_id);

        $this->actingAs($this->admin)->get('/admin/activity?q=viewed')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.actor', 'Wasim')->where('rows.data.0.action', 'customer.viewed'));
    }

    public function test_the_shared_flags_follow_the_role(): void
    {
        $this->actingAs($this->staff('Sub-admin'))->get('/admin/counter')->assertInertia(fn (Assert $p) => $p->where('auth.can.viewReports', true)->where('auth.can.manageAccounts', false));
        $this->actingAs($this->staff())->get('/admin/counter')->assertInertia(fn (Assert $p) => $p->where('auth.can.viewReports', false)->where('auth.can.useCounter', true));
    }

    public function test_a_blocked_try_notifies_everyone_who_can_answer_it(): void
    {
        $sub = $this->staff('Sub-admin', 'Sub');
        app(ApprovalService::class)->noteAttempt($this->staff(), 'tried something');

        $this->assertSame(1, Notice::where('user_id', $this->admin->id)->where('kind', 'staff_attempt')->count());
        $this->assertSame(1, Notice::where('user_id', $sub->id)->where('kind', 'staff_attempt')->count());
        $this->assertSame(0, Notice::where('user_id', $this->staff('Counter staff', 'Other')->id)->where('kind', 'staff_attempt')->count());
    }
}
