<?php

namespace Tests\Feature\Security;

use App\Models\BlockedIp;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Security\IpBlocklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 26: IP block, device limit and suspicious-login records, plus login lockout. */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_ip_gets_403_on_every_page(): void
    {
        BlockedIp::create(['ip' => '127.0.0.1', 'reason' => 'test']);
        app(IpBlocklist::class)->forget();

        $this->get('/login')->assertForbidden();
    }

    public function test_expired_ip_block_no_longer_blocks(): void
    {
        BlockedIp::create(['ip' => '127.0.0.1', 'reason' => 'test', 'expires_at' => now()->subMinute()]);
        app(IpBlocklist::class)->forget();

        $this->get('/login')->assertOk();
    }

    public function test_successful_login_is_recorded(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);

        $this->withHeader('User-Agent', 'DeviceA')->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
        ]);

        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'outcome' => 'success', 'is_suspicious' => false]);
    }

    public function test_failed_login_is_recorded_with_the_attempted_email(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'outcome' => 'failed', 'email' => $user->email]);
    }

    public function test_login_from_a_new_device_over_the_limit_is_flagged(): void
    {
        config(['security.device_limit' => 2]);
        $user = User::factory()->create(['password' => bcrypt('secret-pass')]);

        foreach (['DeviceA', 'DeviceB'] as $agent) {
            $this->flushSession();
            $this->withHeader('User-Agent', $agent)->post('/login', ['email' => $user->email, 'password' => 'secret-pass']);
            auth()->logout();
        }
        $this->flushSession();
        $this->withHeader('User-Agent', 'DeviceC')->post('/login', ['email' => $user->email, 'password' => 'secret-pass']);

        $this->assertTrue(LoginEvent::query()->where('user_id', $user->id)->where('is_suspicious', true)->exists());
    }

    public function test_repeated_wrong_passwords_lock_the_account_temporarily(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
