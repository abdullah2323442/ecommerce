<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_each_kind_of_person_starts_on_their_own_screen_after_signing_in()
    {
        $start = [
            'paikari' => '/paikari', 'wholesaler' => '/wholesale', 'mpo' => '/mpo', 'food_vendor' => '/food/vendor', 'craftsman' => '/craftsman',
            'admin' => route('dashboard', absolute: false), 'staff' => route('dashboard', absolute: false), 'public' => route('dashboard', absolute: false),
        ];

        foreach ($start as $type => $path) {
            $user = User::factory()->create(['account_type' => $type]);

            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect($path);
            $this->post('/logout');
        }
    }

    public function test_a_page_the_person_asked_for_still_wins_over_their_start_screen()
    {
        $user = User::factory()->create(['account_type' => 'paikari']);

        $this->get('/inbox');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/inbox');
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
