<?php

namespace Tests\Feature\Security;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Two-step sign-in with an authenticator app: set up with a proven code, ask for it at sign-in, recovery codes, and no way round it. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-9!';

    private TwoFactor $tf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tf = app(TwoFactor::class);
    }

    private function user(AccountType $type = AccountType::Public): User
    {
        return User::factory()->create(['account_type' => $type, 'password' => self::PASSWORD]);
    }

    /** A user with it on, and the secret to make codes with. @return array{0: User, 1: string, 2: array<int, string>} */
    private function protected(AccountType $type = AccountType::Public): array
    {
        $user = $this->user($type);
        $this->tf->begin($user, self::PASSWORD);
        $secret = $user->fresh()->two_factor_secret;
        $codes = $this->tf->confirm($user->fresh(), $this->tf->currentCode($secret));

        return [$user->fresh(), $secret, $codes];
    }

    private function login(User $user)
    {
        return $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    // ------------------------------------------------------------------ the maths

    public function test_it_gives_the_codes_in_the_standard_test_vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';   // "12345678901234567890", the secret in RFC 6238

        $this->assertSame('287082', $this->tf->code($secret, intdiv(59, 30)));
        $this->assertSame('081804', $this->tf->code($secret, intdiv(1111111109, 30)));
        $this->assertSame('050471', $this->tf->code($secret, intdiv(1111111111, 30)));
        $this->assertSame('005924', $this->tf->code($secret, intdiv(1234567890, 30)));
    }

    // ------------------------------------------------------------------ setting it up

    public function test_setting_it_up_needs_the_password_and_does_not_count_until_a_code_is_proven(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/settings/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->two_factor_secret);

        $this->actingAs($user)->post('/settings/two-factor', ['password' => self::PASSWORD])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($this->tf->enabled($user), 'a secret that was never proven is not two-step sign-in');

        $this->actingAs($user)->get('/settings/two-factor')->assertInertia(fn (Assert $p) => $p->component('settings/two-factor')->where('enabled', false)
            ->where('setup.secret', $user->two_factor_secret)->where('setup.uri', fn ($u) => str_starts_with($u, 'otpauth://totp/') && str_contains($u, 'secret='.$user->two_factor_secret)));
    }

    public function test_a_wrong_code_does_not_turn_it_on_and_the_right_one_does_and_shows_recovery_codes_once(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/settings/two-factor', ['password' => self::PASSWORD]);
        $secret = $user->fresh()->two_factor_secret;

        $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($this->tf->enabled($user->fresh()));

        $this->actingAs($user)->post('/settings/two-factor/confirm', ['code' => $this->tf->currentCode($secret)])->assertSessionHasNoErrors();
        $this->assertTrue($this->tf->enabled($user->fresh()));

        $first = $this->actingAs($user)->get('/settings/two-factor')->viewData('page')['props'];
        $second = $this->actingAs($user)->get('/settings/two-factor')->viewData('page')['props'];
        $this->assertSame(8, $this->tf->recoveryCodesLeft($user->fresh()));
        $this->assertCount(8, $first['recoveryCodes']);
        $this->assertNull($second['recoveryCodes'], 'shown once, on the page right after turning it on, and never again');
        $this->assertTrue($second['enabled']);
    }

    public function test_the_recovery_codes_come_back_on_the_page_right_after_confirming(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/settings/two-factor', ['password' => self::PASSWORD]);
        $secret = $user->fresh()->two_factor_secret;

        $res = $this->actingAs($user)->from('/settings/two-factor')->followingRedirects()->post('/settings/two-factor/confirm', ['code' => $this->tf->currentCode($secret)]);

        $res->assertInertia(fn (Assert $p) => $p->has('recoveryCodes', 8)->where('recoveryCodes.0', fn ($c) => (bool) preg_match('/^[0-9a-f]{6}-[0-9a-f]{6}$/', $c)));
    }

    public function test_the_secret_is_encrypted_in_the_database_and_never_sent_to_other_pages(): void
    {
        [$user, $secret] = $this->protected();

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertStringNotContainsString($secret, (string) $raw);

        $props = $this->actingAs($user)->get('/settings/profile')->viewData('page')['props'];
        $this->assertStringNotContainsString($secret, json_encode($props));
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }

    public function test_it_cannot_be_set_up_again_while_on(): void
    {
        [$user] = $this->protected();

        $this->actingAs($user)->post('/settings/two-factor', ['password' => self::PASSWORD])->assertSessionHasErrors('password');
    }

    // ------------------------------------------------------------------ the codes

    public function test_a_code_is_good_for_one_step_either_side_and_not_further(): void
    {
        [$user, $secret] = $this->protected();
        $now = intdiv(time(), 30);

        $user->forceFill(['two_factor_last_step' => null])->save();
        $this->assertTrue($this->tf->accept($user->fresh(), $this->tf->code($secret, $now - 1)));
        $user->forceFill(['two_factor_last_step' => null])->save();
        $this->assertTrue($this->tf->accept($user->fresh(), $this->tf->code($secret, $now + 1)));
        $user->forceFill(['two_factor_last_step' => null])->save();
        $this->assertFalse($this->tf->accept($user->fresh(), $this->tf->code($secret, $now - 3)));
        $this->assertFalse($this->tf->accept($user->fresh(), $this->tf->code($secret, $now + 3)));
    }

    public function test_the_same_code_cannot_be_used_twice(): void
    {
        [$user, $secret] = $this->protected();
        $user->forceFill(['two_factor_last_step' => null])->save();
        $code = $this->tf->currentCode($secret);

        $this->assertTrue($this->tf->accept($user->fresh(), $code));
        $this->assertFalse($this->tf->accept($user->fresh(), $code), 'someone who saw it over a shoulder cannot reuse it');
        $this->assertFalse($this->tf->accept($user->fresh(), $this->tf->code($secret, intdiv(time(), 30) - 1)), 'nor an older one');
    }

    public function test_codes_that_are_not_six_digits_are_refused(): void
    {
        [$user] = $this->protected();
        $user->forceFill(['two_factor_last_step' => null])->save();

        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $bad) {
            $this->assertFalse($this->tf->accept($user->fresh(), $bad));
        }
    }

    // ------------------------------------------------------------------ signing in

    public function test_a_person_without_it_signs_in_as_before(): void
    {
        $user = $this->user();

        $this->login($user)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_password_alone_does_not_sign_in_someone_with_it_on(): void
    {
        [$user] = $this->protected();

        $this->login($user)->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect();
        $this->get(route('two-factor.challenge'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('auth/two-factor-challenge'));
    }

    public function test_a_wrong_password_still_says_so_and_does_not_reveal_whether_it_is_on(): void
    {
        [$user] = $this->protected();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull(session('two_factor'));
    }

    public function test_the_right_code_finishes_signing_in(): void
    {
        [$user, $secret] = $this->protected();
        $user->forceFill(['two_factor_last_step' => null])->save();
        $this->login($user);

        $this->post(route('two-factor.challenge.store'), ['code' => $this->tf->currentCode($secret)])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('two_factor'), 'the half-signed-in state is gone');
    }

    public function test_a_wrong_code_does_not(): void
    {
        [$user] = $this->protected();
        $this->login($user);

        $this->post(route('two-factor.challenge.store'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_the_challenge_is_only_there_right_after_the_password(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_half_signed_in_state_runs_out(): void
    {
        [$user, $secret] = $this->protected();
        $user->forceFill(['two_factor_last_step' => null])->save();
        $this->login($user);
        $this->session(['two_factor' => array_merge(session('two_factor'), ['until' => time() - 5])]);

        $this->post(route('two-factor.challenge.store'), ['code' => $this->tf->currentCode($secret)])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_five_wrong_tries_lock_it_for_a_minute_even_for_the_right_code(): void
    {
        [$user, $secret] = $this->protected();
        $user->forceFill(['two_factor_last_step' => null])->save();
        $this->login($user);

        foreach (range(1, 5) as $i) {
            $this->post(route('two-factor.challenge.store'), ['code' => '00000'.$i])->assertSessionHasErrors('code');
        }
        $this->post(route('two-factor.challenge.store'), ['code' => $this->tf->currentCode($secret)])->assertSessionHasErrors('code');

        $this->assertGuest();
        RateLimiter::clear('two-factor|'.$user->id.'|127.0.0.1');
    }

    public function test_a_recovery_code_signs_in_once_and_then_is_gone(): void
    {
        [$user, , $codes] = $this->protected();
        $this->login($user);

        $this->post(route('two-factor.challenge.store'), ['recovery_code' => strtoupper($codes[0])])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame(7, $this->tf->recoveryCodesLeft($user->fresh()));

        auth()->logout();
        $this->login($user);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[1]])->assertRedirect('/dashboard');
    }

    public function test_two_people_cannot_use_each_others_state(): void
    {
        [$a] = $this->protected();
        [$b, $secretB] = $this->protected();
        $b->forceFill(['two_factor_last_step' => null])->save();
        $this->login($a);

        $this->post(route('two-factor.challenge.store'), ['code' => $this->tf->currentCode($secretB)])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    // ------------------------------------------------------------------ turning it off, new codes

    public function test_turning_it_off_and_new_recovery_codes_need_the_password(): void
    {
        [$user, , $old] = $this->protected();

        $this->actingAs($user)->delete('/settings/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->actingAs($user)->post('/settings/two-factor/codes', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertTrue($this->tf->enabled($user->fresh()));

        $this->actingAs($user)->post('/settings/two-factor/codes', ['password' => self::PASSWORD])->assertSessionHasNoErrors();
        $this->assertFalse($this->tf->useRecoveryCode($user->fresh(), $old[0]), 'the old codes stop working');

        $this->actingAs($user)->delete('/settings/two-factor', ['password' => self::PASSWORD])->assertSessionHasNoErrors();
        $fresh = $user->fresh();
        $this->assertFalse($this->tf->enabled($fresh));
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);

        auth()->logout();
        $this->login($fresh)->assertRedirect('/dashboard');
    }

    public function test_guests_cannot_reach_the_settings(): void
    {
        $this->get('/settings/two-factor')->assertRedirect();
        $this->post('/settings/two-factor', ['password' => 'x'])->assertRedirect();
    }

    // ------------------------------------------------------------------ the owner's side

    public function test_the_owner_can_switch_it_off_for_someone_who_lost_their_phone(): void
    {
        $admin = $this->user(AccountType::Admin);
        [$staff] = $this->protected(AccountType::Staff);

        $this->actingAs($admin)->post("/admin/users/{$staff->id}/two-factor/reset")->assertSessionHasNoErrors();

        $this->assertFalse($this->tf->enabled($staff->fresh()));
        $this->assertSame($admin->id, ActivityLog::where('action', 'security.two_factor_reset')->firstOrFail()->actor_id);
    }

    public function test_others_cannot_reset_it(): void
    {
        [$victim] = $this->protected();
        $staff = $this->user(AccountType::Staff);
        $buyer = $this->user();

        $this->actingAs($staff)->post("/admin/users/{$victim->id}/two-factor/reset")->assertRedirect('/');
        $this->actingAs($buyer)->post("/admin/users/{$victim->id}/two-factor/reset")->assertRedirect('/');

        $this->assertTrue($this->tf->enabled($victim->fresh()));
    }

    public function test_the_log_never_holds_a_secret_or_a_code(): void
    {
        [$user, $secret, $codes] = $this->protected();
        $this->tf->useRecoveryCode($user->fresh(), $codes[0]);

        $all = json_encode(ActivityLog::all()->toArray());

        $this->assertStringNotContainsString($secret, $all);
        $this->assertStringNotContainsString($codes[0], $all);
        $this->assertGreaterThan(0, ActivityLog::where('action', 'security.two_factor_on')->count());
    }

    // ------------------------------------------------------------------ required for staff

    public function test_when_the_owner_asks_staff_without_it_can_only_reach_the_setup_page(): void
    {
        Setting::write('security.require_2fa_staff', true);
        $staff = $this->user(AccountType::Staff);

        $this->actingAs($staff)->get('/admin/orders')->assertRedirect(route('two-factor.show'));
        $this->actingAs($staff)->get('/settings/two-factor')->assertOk();
    }

    public function test_staff_with_it_on_use_the_panel_as_usual(): void
    {
        Setting::write('security.require_2fa_staff', true);
        [$staff] = $this->protected(AccountType::Staff);

        $this->actingAs($staff)->get('/admin/orders')->assertOk();
    }

    public function test_nothing_changes_while_the_owner_has_not_asked(): void
    {
        $staff = $this->user(AccountType::Staff);

        $this->actingAs($staff)->get('/admin/orders')->assertOk();
    }

    public function test_buyers_are_never_forced(): void
    {
        Setting::write('security.require_2fa_staff', true);

        $this->actingAs($this->user())->get('/settings/profile')->assertOk();
    }
}
