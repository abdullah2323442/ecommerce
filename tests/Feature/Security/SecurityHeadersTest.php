<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_carries_the_baseline_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_account_type_cannot_be_mass_assigned(): void
    {
        // Registration or any future form that passes request data to fill() must never be able to pick a role.
        $user = new User;
        $user->fill(['name' => 'X', 'account_type' => 'admin']);

        $this->assertNull($user->account_type);
    }

    public function test_the_search_page_is_rate_limited(): void
    {
        for ($i = 0; $i < 90; $i++) {
            $this->get('/medicines?q=napa')->assertOk();
        }

        $this->get('/medicines?q=napa')->assertStatus(429);
    }
}
