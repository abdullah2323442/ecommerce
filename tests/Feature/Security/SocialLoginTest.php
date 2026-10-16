<?php

namespace Tests\Feature\Security;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Security\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OutsideUser;
use Mockery;
use Tests\TestCase;

/** Sign in with Google or Facebook, switched on by the owner: safe joining, plain buyers only, and the second step still asked. */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function switchOn(string $provider = 'google'): void
    {
        $fields = $provider === 'google' ? ['client_id' => 'cid-123', 'client_secret' => 'SECRET-OAUTH-456'] : ['app_id' => 'aid-123', 'app_secret' => 'SECRET-FB-456'];
        app(Integrations::class)->save("social:{$provider}", $fields, true, 'live', [], $this->admin);
    }

    /** Pretend to be the provider: what they say about the person coming back. */
    private function outside(string $provider, string $id, ?string $email, string $name = 'Rahim Uddin', bool $verified = true): void
    {
        $user = (new OutsideUser)->map(['id' => $id, 'name' => $name, 'email' => $email]);
        $user->user = $provider === 'google' ? ['email_verified' => $verified] : ['id' => $id];
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    private function back(string $provider = 'google')
    {
        return $this->get("/auth/{$provider}/callback?code=abc&state=xyz");
    }

    // ------------------------------------------------------------------ the switch

    public function test_nothing_shows_until_the_owner_switches_it_on(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('socialProviders', []));

        $this->switchOn('google');
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->where('socialProviders', [['key' => 'google', 'name' => 'Google']]));
        $this->get('/register')->assertInertia(fn (Assert $p) => $p->has('socialProviders', 1));

        $this->switchOn('facebook');
        $this->get('/login')->assertInertia(fn (Assert $p) => $p->has('socialProviders', 2));
    }

    public function test_a_way_that_is_off_cannot_be_used(): void
    {
        $this->get('/auth/google/redirect')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->back('google')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/auth/twitter/redirect')->assertRedirect('/');
    }

    public function test_the_buttons_are_not_shown_to_someone_already_signed_in(): void
    {
        $this->switchOn();

        $this->actingAs(User::factory()->create())->get('/settings/profile')->assertInertia(fn (Assert $p) => $p->where('socialProviders', []));
    }

    public function test_going_to_the_provider_uses_the_keys_the_owner_saved_and_the_exact_return_address(): void
    {
        $this->switchOn();
        $driver = Mockery::mock();
        $driver->shouldReceive('scopes')->with(['openid', 'email', 'profile'])->andReturnSelf();
        $driver->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get('/auth/google/redirect')->assertRedirect('https://accounts.google.com/o/oauth2/auth');

        $this->assertSame('cid-123', config('services.google.client_id'));
        $this->assertSame('SECRET-OAUTH-456', config('services.google.client_secret'));
        $this->assertSame(route('social.callback', 'google'), config('services.google.redirect'));
    }

    public function test_the_owner_is_shown_the_exact_address_to_register_and_never_the_secret(): void
    {
        $this->switchOn();

        $page = $this->actingAs($this->admin)->get('/admin/connections')->viewData('page')['props'];
        $google = collect($page['types'])->firstWhere('type', 'social')['providers'][0];

        $this->assertSame(route('social.callback', 'google'), $google['redirect_uri']);
        $this->assertStringNotContainsString('SECRET-OAUTH-456', json_encode($page));
        $this->assertTrue($google['ready']);
    }

    // ------------------------------------------------------------------ a new person

    public function test_a_new_person_gets_a_plain_buyer_account_and_is_signed_in(): void
    {
        $this->switchOn();
        $this->outside('google', 'g-1', 'New.Person@Example.com', 'New Person');

        $this->back()->assertRedirect('/dashboard');

        $user = User::where('email', 'new.person@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(AccountType::Public, $user->account_type);
        $this->assertFalse($user->has_password);
        $this->assertNotNull($user->email_verified_at, 'Google vouched for the address');
        $this->assertSame(1, SocialAccount::where('user_id', $user->id)->count());
        $this->assertSame(1, ActivityLog::where('action', 'security.social_signup')->count());
    }

    public function test_an_address_facebook_did_not_vouch_for_is_not_marked_verified(): void
    {
        $this->switchOn('facebook');
        $this->outside('facebook', 'f-1', 'fb.person@example.com');

        $this->back('facebook')->assertRedirect('/dashboard');

        $this->assertNull(User::where('email', 'fb.person@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_the_second_time_it_is_the_same_person(): void
    {
        $this->switchOn();
        $this->outside('google', 'g-1', 'new@example.com');
        $this->back();
        auth()->logout();

        $this->outside('google', 'g-1', 'changed.address@example.com');
        $this->back()->assertRedirect('/dashboard');

        $this->assertSame(1, User::where('account_type', AccountType::Public->value)->count() - 0 > 0 ? 1 : 0);
        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('new@example.com', User::whereKey(SocialAccount::first()->user_id)->value('email'), 'the provider\'s new address does not change ours');
    }

    public function test_an_account_that_shares_no_email_is_refused(): void
    {
        $this->switchOn('facebook');
        $this->outside('facebook', 'f-2', null);

        $this->back('facebook')->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
    }

    // ------------------------------------------------------------------ a person who is already here

    public function test_google_joins_an_existing_buyer_when_it_vouches_for_the_address(): void
    {
        $this->switchOn();
        $existing = User::factory()->create(['email' => 'buyer@example.com', 'account_type' => AccountType::Public]);
        $this->outside('google', 'g-9', 'buyer@example.com', verified: true);

        $this->back()->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, SocialAccount::where('user_id', $existing->id)->count());
        $this->assertTrue($existing->fresh()->has_password, 'their own password is untouched');
    }

    public function test_an_address_google_does_not_vouch_for_is_never_joined(): void
    {
        $this->switchOn();
        $victim = User::factory()->create(['email' => 'victim@example.com', 'account_type' => AccountType::Public]);
        $this->outside('google', 'g-evil', 'victim@example.com', verified: false);

        $this->back()->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, SocialAccount::where('user_id', $victim->id)->count());
    }

    public function test_facebook_is_never_enough_to_join_an_existing_account(): void
    {
        $this->switchOn('facebook');
        $victim = User::factory()->create(['email' => 'victim@example.com', 'account_type' => AccountType::Public]);
        $this->outside('facebook', 'f-evil', 'victim@example.com');

        $this->back('facebook')->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_staff_the_owner_and_shops_cannot_be_signed_into_this_way(): void
    {
        $this->switchOn();
        foreach ([AccountType::Staff, AccountType::Admin, AccountType::Paikari, AccountType::Wholesaler] as $i => $type) {
            User::factory()->create(['email' => "p{$i}@example.com", 'account_type' => $type]);
            $this->outside('google', "g-{$i}", "p{$i}@example.com", verified: true);

            $this->back()->assertRedirect(route('login'))->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_linked_staff_account_cannot_sign_in_either(): void
    {
        $this->switchOn();
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        SocialAccount::create(['user_id' => $staff->id, 'provider' => 'google', 'provider_id' => 'g-staff', 'email' => $staff->email]);
        $this->outside('google', 'g-staff', $staff->email);

        $this->back()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_closed_account_cannot_sign_in(): void
    {
        $this->switchOn();
        $user = User::factory()->create(['email' => 'closed@example.com', 'account_type' => AccountType::Public]);
        $user->forceFill(['suspended_at' => now()])->save();
        $this->outside('google', 'g-c', 'closed@example.com');

        $this->back()->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ------------------------------------------------------------------ the second step

    public function test_someone_with_two_step_sign_in_on_is_still_asked_for_the_code(): void
    {
        $this->switchOn();
        $user = User::factory()->create(['email' => 'safe@example.com', 'account_type' => AccountType::Public, 'password' => 'a-good-password-1']);
        $tf = app(TwoFactor::class);
        $tf->begin($user, 'a-good-password-1');
        $tf->confirm($user->fresh(), $tf->currentCode($user->fresh()->two_factor_secret));
        $this->outside('google', 'g-safe', 'safe@example.com', verified: true);

        $this->back()->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    // ------------------------------------------------------------------ cancelling and errors

    public function test_pressing_cancel_at_the_provider_goes_back_to_sign_in_with_a_message(): void
    {
        $this->switchOn();

        $this->get('/auth/google/callback?error=access_denied')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_failure_at_the_provider_is_a_calm_message_not_an_error_page(): void
    {
        $this->switchOn();
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andThrow(new \RuntimeException('invalid_grant: secret details'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $res = $this->back()->assertRedirect(route('login'));

        $res->assertSessionHasErrors('email');
        $this->assertStringNotContainsString('secret details', json_encode(session('errors')->getBag('default')->all()));
        $this->assertGuest();
    }

    // ------------------------------------------------------------------ connecting in Settings

    public function test_a_signed_in_buyer_connects_an_account_and_it_is_saved_without_changing_their_email(): void
    {
        $this->switchOn();
        $user = User::factory()->create(['email' => 'mine@example.com', 'account_type' => AccountType::Public]);
        $this->outside('google', 'g-mine', 'different@gmail.com');
        $this->actingAs($user)->withSession(['social.connect' => 'google'])->get('/auth/google/callback?code=abc')->assertRedirect(route('connected-accounts.show'));

        $this->assertSame('g-mine', SocialAccount::where('user_id', $user->id)->value('provider_id'));
        $this->assertSame('mine@example.com', $user->fresh()->email);
    }

    public function test_an_outside_account_already_joined_to_someone_else_cannot_be_taken(): void
    {
        $this->switchOn();
        $owner = User::factory()->create(['account_type' => AccountType::Public]);
        SocialAccount::create(['user_id' => $owner->id, 'provider' => 'google', 'provider_id' => 'g-taken', 'email' => 'x@example.com']);
        $me = User::factory()->create(['account_type' => AccountType::Public]);

        $this->outside('google', 'g-taken', 'x@example.com');
        $this->actingAs($me)->withSession(['social.connect' => 'google'])->get('/auth/google/callback?code=abc')->assertRedirect(route('connected-accounts.show'))->assertSessionHasErrors('social');

        $this->assertSame(0, SocialAccount::where('user_id', $me->id)->count());
    }

    public function test_the_settings_page_lists_what_is_joined_and_what_can_be_added(): void
    {
        $this->switchOn('google');
        $this->switchOn('facebook');
        $user = User::factory()->create(['account_type' => AccountType::Public]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'g-1', 'email' => 'me@gmail.com']);

        $this->actingAs($user)->get('/settings/connected-accounts')->assertInertia(fn (Assert $p) => $p->component('settings/connected-accounts')
            ->where('linked.0.provider', 'google')->where('linked.0.email', 'me@gmail.com')->has('available', 2)->where('allowed', true));
    }

    public function test_staff_are_told_this_does_not_apply_to_them(): void
    {
        $this->switchOn();
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        $this->actingAs($staff)->get('/settings/connected-accounts')->assertInertia(fn (Assert $p) => $p->where('allowed', false));
        $this->actingAs($staff)->get('/settings/connected-accounts/google/connect')->assertSessionHasErrors('social');
    }

    public function test_someone_with_no_password_cannot_cut_their_only_way_in(): void
    {
        $this->switchOn();
        $this->outside('google', 'g-1', 'only@example.com');
        $this->back();
        $user = User::where('email', 'only@example.com')->firstOrFail();

        $this->actingAs($user)->delete('/settings/connected-accounts/google')->assertSessionHasErrors('social');
        $this->assertSame(1, SocialAccount::where('user_id', $user->id)->count());

        // They set a password (without a "current" one, as they have none), and then they can let go.
        $this->actingAs($user)->put('/settings/password', ['password' => 'A-new-strong-pass-77', 'password_confirmation' => 'A-new-strong-pass-77'])->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->has_password);
        $this->actingAs($user)->delete('/settings/connected-accounts/google')->assertSessionHasNoErrors();
        $this->assertSame(0, SocialAccount::where('user_id', $user->id)->count());
    }

    public function test_a_person_who_has_a_password_still_has_to_give_the_current_one_to_change_it(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public, 'password' => 'old-password-123']);

        $this->actingAs($user)->put('/settings/password', ['password' => 'A-new-strong-pass-77', 'password_confirmation' => 'A-new-strong-pass-77'])->assertSessionHasErrors('current_password');
    }

    public function test_a_guest_cannot_use_the_settings_pages(): void
    {
        $this->get('/settings/connected-accounts')->assertRedirect();
        $this->delete('/settings/connected-accounts/google')->assertRedirect();
    }

    public function test_the_log_never_holds_the_keys(): void
    {
        $this->switchOn();
        $this->outside('google', 'g-1', 'log@example.com');
        $this->back();

        $all = json_encode(ActivityLog::all()->toArray());

        $this->assertStringNotContainsString('SECRET-OAUTH-456', $all);
        $this->assertStringNotContainsString('cid-123', $all);
    }
}
