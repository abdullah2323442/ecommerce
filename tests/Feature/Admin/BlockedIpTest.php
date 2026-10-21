<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\BlockedIp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The owner blocks and unblocks addresses from a screen (spec ch 26). The blocking itself is the BlockIpAddress middleware. */
class BlockedIpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    public function test_the_owner_blocks_an_address_and_that_address_is_turned_away(): void
    {
        $this->actingAs($this->admin)->post('/admin/blocked-ips', ['ip' => '203.0.113.7', 'reason' => 'Password guessing'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('blocked_ips', ['ip' => '203.0.113.7', 'blocked_by' => $this->admin->id]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/')->assertStatus(403);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->get('/')->assertOk();
    }

    public function test_a_block_for_some_days_ends_by_itself(): void
    {
        $this->actingAs($this->admin)->post('/admin/blocked-ips', ['ip' => '203.0.113.9', 'days' => 2]);
        $block = BlockedIp::where('ip', '203.0.113.9')->firstOrFail();

        $this->assertTrue($block->isActive());
        $this->assertTrue($block->expires_at->between(now()->addDay(), now()->addDays(3)));

        $block->update(['expires_at' => now()->subMinute()]);
        app(\App\Services\Security\IpBlocklist::class)->forget();
        $this->assertFalse($block->fresh()->isActive());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/')->assertOk();
    }

    public function test_unblocking_lets_the_address_in_again(): void
    {
        $block = BlockedIp::create(['ip' => '203.0.113.10']);

        $this->actingAs($this->admin)->delete("/admin/blocked-ips/{$block->id}")->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('blocked_ips', ['ip' => '203.0.113.10']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->get('/')->assertOk();
    }

    public function test_the_owner_cannot_block_the_address_in_use_and_bad_input_is_refused(): void
    {
        $this->actingAs($this->admin)->post('/admin/blocked-ips', ['ip' => '127.0.0.1'])->assertSessionHasErrors('ip');
        $this->actingAs($this->admin)->post('/admin/blocked-ips', ['ip' => 'not an address'])->assertSessionHasErrors('ip');
        $this->actingAs($this->admin)->post('/admin/blocked-ips', ['ip' => '203.0.113.11', 'days' => 0])->assertSessionHasErrors('days');

        $this->assertDatabaseCount('blocked_ips', 0);
    }

    public function test_the_list_shows_blocks_and_only_the_owner_sees_it(): void
    {
        BlockedIp::create(['ip' => '203.0.113.12', 'reason' => 'Scraping']);

        $this->actingAs($this->admin)->get('/admin/blocked-ips')->assertInertia(fn (Assert $p) => $p->component('admin/blocked-ips')->has('blocks', 1)->where('blocks.0.ip', '203.0.113.12'));

        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->get('/admin/blocked-ips')->assertRedirect('/');
        $this->actingAs($shop)->post('/admin/blocked-ips', ['ip' => '203.0.113.13'])->assertRedirect('/');
    }

    public function test_a_suspension_for_some_days_lifts_itself(): void
    {
        $customer = User::factory()->create(['account_type' => AccountType::Paikari, 'phone' => '01711111111']);

        $this->actingAs($this->admin)->post("/admin/customers/{$customer->id}/suspend", ['reason' => 'Rude to staff', 'days' => 3])->assertSessionHasNoErrors();

        $this->assertTrue($customer->fresh()->isSuspended());
        $this->assertTrue($customer->fresh()->suspended_until->between(now()->addDays(2), now()->addDays(4)));

        $this->travel(4)->days();
        $this->assertFalse($customer->fresh()->isSuspended(), 'the time is up, so the account works again');
    }
}
