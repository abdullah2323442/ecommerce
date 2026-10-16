<?php

namespace Tests\Feature\Visibility;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * End-to-end checks for the server-side gate (spec VIS-1, VIS-3, VIS-4).
 * A denied section must look exactly like a missing page: a silent redirect home.
 */
class SectionAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'section:paikari'])->get('/_test/paikari', fn () => 'paikari-ok');
        Route::middleware(['web', 'section:wholesale'])->get('/_test/wholesale', fn () => 'wholesale-ok');
    }

    public function test_allowed_user_reaches_the_section(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($user)->get('/_test/paikari')->assertOk()->assertSee('paikari-ok');
    }

    public function test_paikari_user_is_silently_redirected_from_wholesale(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Paikari]);

        $response = $this->actingAs($user)->get('/_test/wholesale');

        // Same response a nonexistent page gives: a redirect to the homepage, no 403, no message.
        $response->assertRedirect('/');
        $response->assertSessionMissing('errors');
        $response->assertSessionMissing('status');
    }

    public function test_denied_response_is_a_plain_redirect_with_no_hint(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Paikari]);

        $response = $this->actingAs($user)->get('/_test/wholesale');

        $response->assertStatus(302);
        $response->assertRedirect('/');
        $response->assertDontSee('wholesale', false);
    }

    public function test_logged_in_user_gets_the_same_redirect_for_unknown_urls(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($user)->get('/_test/does-not-exist')->assertRedirect('/');
    }

    public function test_admin_gets_an_honest_404_for_unknown_urls(): void
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);

        $this->actingAs($admin)->get('/_test/does-not-exist')->assertNotFound();
    }

    public function test_guest_gets_the_same_redirect_for_unknown_urls(): void
    {
        $this->get('/_test/does-not-exist')->assertRedirect('/');
    }

    public function test_guest_is_treated_as_public_and_redirected_from_paikari(): void
    {
        $this->get('/_test/paikari')->assertRedirect('/');
    }

    public function test_suspended_user_loses_access_even_with_the_right_type(): void
    {
        $user = User::factory()->create([
            'account_type' => AccountType::Paikari,
            'suspended_at' => now(),
        ]);

        $this->actingAs($user)->get('/_test/paikari')->assertRedirect('/');
    }

    public function test_phone_is_unique_per_account(): void
    {
        User::factory()->create(['phone' => '01711111111']);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['phone' => '01711111111']);
    }
}
