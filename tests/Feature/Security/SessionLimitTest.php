<?php

namespace Tests\Feature\Security;

use App\Enums\AccountType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Security\SessionLimiter;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Spec ch 26: a limit on how many devices one staff account can be signed in on at once. */
class SessionLimitTest extends TestCase
{
    use RefreshDatabase;

    private function sessions(User $user, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table('sessions')->insert(['id' => "u{$user->id}-s{$i}", 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->subMinutes(100 - $i)->timestamp]);
        }
    }

    private function alive(User $user): array
    {
        return DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
    }

    public function test_a_new_sign_in_over_the_limit_signs_the_oldest_devices_out(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->sessions($staff, 3); // s3 is the most recently used

        $dropped = app(SessionLimiter::class)->enforce($staff);

        // The limit is 3 and the new sign-in is the third, so only the two most recent old ones stay.
        $this->assertSame(1, $dropped);
        $this->assertSame(["u{$staff->id}-s2", "u{$staff->id}-s3"], $this->alive($staff));
        $this->assertDatabaseHas('activity_logs', ['action' => 'security.signed_out_old_devices', 'subject_id' => $staff->id]);
    }

    public function test_under_the_limit_nothing_is_signed_out(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->sessions($staff, 2);

        $this->assertSame(0, app(SessionLimiter::class)->enforce($staff));
        $this->assertCount(2, $this->alive($staff));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'security.signed_out_old_devices']);
    }

    public function test_the_limit_is_a_setting(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        Setting::write('security.session_limit', 1);
        $this->sessions($staff, 2);

        app(SessionLimiter::class)->enforce($staff);

        $this->assertSame([], $this->alive($staff));
    }

    public function test_it_only_touches_that_staff_members_sessions(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $other = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->sessions($staff, 4);
        $this->sessions($other, 3);

        app(SessionLimiter::class)->enforce($staff);

        $this->assertCount(2, $this->alive($staff));
        $this->assertCount(3, $this->alive($other));
    }

    public function test_customers_and_the_owner_are_not_limited(): void
    {
        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Wholesaler, AccountType::Admin] as $type) {
            $user = User::factory()->create(['account_type' => $type]);
            $this->sessions($user, 6);

            $this->assertSame(0, app(SessionLimiter::class)->enforce($user));
            $this->assertCount(6, $this->alive($user));
        }
    }

    public function test_signing_in_triggers_it(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->sessions($staff, 3);

        event(new Login('web', $staff, false));

        $this->assertCount(2, $this->alive($staff));
    }
}
