<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TierAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    public function test_only_the_admin_can_manage_tiers(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $paikari = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($staff)->post('/admin/tiers', ['name' => 'A', 'discount_percent' => 10])->assertRedirect('/');
        $this->actingAs($paikari)->post('/admin/tiers', ['name' => 'A', 'discount_percent' => 10])->assertRedirect('/');
        $this->assertDatabaseCount('tiers', 0);
    }

    public function test_index_lists_tiers_with_customer_counts(): void
    {
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        User::factory()->count(2)->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]);

        $this->actingAs($this->admin)->get('/admin/tiers')->assertInertia(fn (Assert $page) => $page
            ->component('admin/tiers/index')->where('tiers.0.users_count', 2));
    }

    public function test_create_validates_and_logs(): void
    {
        $this->actingAs($this->admin)->post('/admin/tiers', ['name' => '', 'discount_percent' => 150])
            ->assertSessionHasErrors(['name', 'discount_percent']);

        $this->actingAs($this->admin)->post('/admin/tiers', ['name' => 'A', 'discount_percent' => 10])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tiers', ['name' => 'A']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'tier.created']);
    }

    public function test_tier_names_are_unique(): void
    {
        Tier::create(['name' => 'A', 'discount_percent' => 10]);

        $this->actingAs($this->admin)->post('/admin/tiers', ['name' => 'A', 'discount_percent' => 5])->assertSessionHasErrors('name');
    }

    public function test_changing_a_rate_reprices_members_and_records_before_and_after(): void
    {
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);

        $this->actingAs($this->admin)->put("/admin/tiers/{$tier->id}", ['name' => 'A', 'discount_percent' => 12.5]);

        $this->assertSame('12.50', $tier->refresh()->discount_percent);
        $log = ActivityLog::where('action', 'tier.updated')->firstOrFail();
        $this->assertSame('10.00', $log->changes['before']['discount_percent']);
    }

    public function test_a_tier_with_customers_cannot_be_deleted(): void
    {
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]);

        $this->actingAs($this->admin)->delete("/admin/tiers/{$tier->id}")->assertSessionHasErrors('tier');

        $this->assertDatabaseHas('tiers', ['id' => $tier->id]);
    }

    public function test_an_empty_tier_can_be_deleted(): void
    {
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);

        $this->actingAs($this->admin)->delete("/admin/tiers/{$tier->id}");

        $this->assertDatabaseMissing('tiers', ['id' => $tier->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'tier.deleted']);
    }
}
