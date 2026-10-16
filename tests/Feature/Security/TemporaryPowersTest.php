<?php

namespace Tests\Feature\Security;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Notice;
use App\Models\Role;
use App\Models\TemporaryPower;
use App\Models\User;
use App\Services\Security\TemporaryPowers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 25: while the owner is away, a sub-admin gets extra powers for a set time; they end by themselves. */
class TemporaryPowersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $sub;

    private User $counter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->counter = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Counter staff')->value('id'), 'name' => 'Counter One']);
        $this->sub = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id'), 'name' => 'Sub One']);
    }

    private function confirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => Carbon::now()->timestamp]);
    }

    private function lend(User $who, array $permissions = ['reports'], int $hours = 24)
    {
        return $this->actingAs($this->admin)->confirmed()->post("/admin/staff/{$who->id}/powers", ['permissions' => $permissions, 'hours' => $hours, 'note' => 'Holiday']);
    }

    public function test_a_staff_member_gets_the_power_for_a_while_and_it_ends_by_itself(): void
    {
        $this->assertFalse($this->counter->hasPermission('reports'));

        $this->lend($this->counter)->assertSessionHasNoErrors();

        $this->assertTrue($this->counter->fresh()->hasPermission('reports'));
        $this->assertTrue($this->counter->fresh()->can('view-reports'));

        Carbon::setTestNow('2026-10-16 10:01:00');
        $this->assertFalse($this->counter->fresh()->hasPermission('reports'), 'a day later it is over');
    }

    public function test_it_works_in_the_real_pages(): void
    {
        $this->actingAs($this->counter)->get('/admin/reports')->assertRedirect('/');

        $this->lend($this->counter);

        // A new request builds the person again, as in real life.
        $this->actingAs($this->counter->fresh())->get('/admin/reports')->assertOk();
    }

    public function test_the_role_is_not_changed_and_nobody_else_gets_it(): void
    {
        $this->lend($this->counter);

        $this->assertNotContains('reports', Role::where('name', 'Counter staff')->firstOrFail()->permissions);
        $other = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Counter staff')->value('id')]);
        $this->assertFalse($other->hasPermission('reports'));
    }

    public function test_the_owner_takes_it_back_at_any_moment(): void
    {
        $this->lend($this->counter);
        $power = TemporaryPower::firstOrFail();

        $this->actingAs($this->admin)->confirmed()->delete("/admin/powers/{$power->id}")->assertSessionHasNoErrors();

        $this->assertFalse($this->counter->fresh()->hasPermission('reports'));
        $this->assertNotNull($power->fresh()->revoked_at);
        $this->assertSame(1, ActivityLog::where('action', 'staff.powers_taken_back')->count());
    }

    public function test_managing_staff_is_never_lent(): void
    {
        $this->lend($this->counter, ['accounts', 'reports'])->assertSessionHasNoErrors();

        $this->assertSame(['reports'], TemporaryPower::firstOrFail()->permissions);
        $this->assertFalse($this->counter->fresh()->hasPermission('accounts'));
        $this->lend($this->sub, ['accounts'])->assertSessionHasErrors('lend');
        $this->assertSame(1, TemporaryPower::count());
    }

    public function test_it_cannot_be_nothing_too_short_or_too_long(): void
    {
        $this->lend($this->counter, [])->assertSessionHasErrors('permissions');
        $this->lend($this->counter, ['nonsense'])->assertSessionHasErrors('lend');
        $this->lend($this->counter, ['reports'], 0)->assertSessionHasErrors('hours');
        $this->lend($this->counter, ['reports'], 24 * 31)->assertSessionHasErrors('hours');
        $this->assertSame(0, TemporaryPower::count());

        $this->lend($this->counter, ['reports'], 24 * 30)->assertSessionHasNoErrors();
    }

    public function test_only_a_staff_account_that_is_open_can_be_lent_to(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->lend($buyer)->assertSessionHasErrors('lend');

        $this->counter->forceFill(['suspended_at' => now()])->save();
        $this->lend($this->counter)->assertSessionHasErrors('lend');
        $this->assertSame(0, TemporaryPower::count());
    }

    public function test_a_closed_account_keeps_nothing(): void
    {
        $this->lend($this->counter);
        $this->counter->forceFill(['suspended_at' => now()])->save();

        $this->assertSame([], $this->counter->fresh()->permissions());
    }

    public function test_the_staff_member_is_told_and_it_is_in_the_activity_log(): void
    {
        $this->lend($this->counter, ['reports', 'stock']);

        $body = json_decode(Notice::where('user_id', $this->counter->id)->where('kind', 'powers_lent')->firstOrFail()->body, true);
        $this->assertStringContainsString('Sales, profit and reports', $body['name']);
        $this->assertSame('16 Oct 2026 16:00', $body['until'], 'Dhaka time, a day from now');
        $this->assertSame(1, ActivityLog::where('action', 'staff.powers_lent')->count());
    }

    public function test_only_the_owner_side_with_accounts_can_lend_and_a_sub_admin_cannot_lend_to_themselves(): void
    {
        $this->actingAs($this->sub)->confirmed()->post("/admin/staff/{$this->sub->id}/powers", ['permissions' => ['reports'], 'hours' => 5])->assertRedirect('/');
        $this->actingAs($this->counter)->confirmed()->post("/admin/staff/{$this->counter->id}/powers", ['permissions' => ['reports'], 'hours' => 5])->assertRedirect('/');
        $this->assertSame(0, TemporaryPower::count());
    }

    public function test_lending_asks_for_the_password_again(): void
    {
        $this->actingAs($this->admin)->post("/admin/staff/{$this->counter->id}/powers", ['permissions' => ['reports'], 'hours' => 5])->assertRedirect();
        $this->assertSame(0, TemporaryPower::count());
    }

    public function test_the_staff_page_lists_what_is_lent_and_what_ended(): void
    {
        $this->lend($this->counter);
        $this->lend($this->sub, ['stock']);
        TemporaryPower::where('user_id', $this->sub->id)->firstOrFail()->update(['revoked_at' => now()]);

        $this->actingAs($this->admin)->get('/admin/staff')->assertInertia(fn (Assert $p) => $p
            ->has('lent', 1)->where('lent.0.who', 'Counter One')->where('lent.0.by', $this->admin->name)
            ->has('ended', 1)->where('ended.0.taken_back', true)->where('lendable', fn ($l) => ! in_array('accounts', collect($l)->all(), true) && in_array('reports', collect($l)->all(), true)));
    }

    public function test_two_loans_add_up_and_one_ending_leaves_the_other(): void
    {
        $this->lend($this->counter, ['reports'], 2);
        $this->lend($this->counter, ['stock'], 48);

        $this->assertEqualsCanonicalizing(['reports', 'stock'], app(TemporaryPowers::class)->activeFor($this->counter));

        Carbon::setTestNow('2026-10-15 13:00:00');
        $this->assertSame(['stock'], app(TemporaryPowers::class)->activeFor($this->counter));
    }
}
