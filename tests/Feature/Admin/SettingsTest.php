<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\OrderNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 25: the holiday / closed-days setting and the owner's other rules, in one screen. */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function orders(array $over = []): array
    {
        return array_merge([
            'orders_weekly_off' => [5], 'orders_holidays' => [], 'orders_night_from' => 22, 'orders_night_to' => 8, 'delivery_free_over' => 0,
            'delivery_shop_district' => 'Dhaka', 'delivery_advance_over' => 0, 'delivery_shop_area' => '', 'delivery_round_mode' => 'up_kg', 'delivery_outside_enabled' => true, 'delivery_charge_inside' => 60, 'delivery_charge_outside' => 120, 'orders_edit_minutes' => 20, 'orders_stale_hours' => 72,
        ], $over);
    }

    public function test_the_screen_shows_every_rule_with_its_current_value(): void
    {
        Setting::write('returns.window_days', 7);

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/settings')->has('groups', 17)
            ->where('groups.0.key', 'orders')->where('groups.0.items.0.value', [5])
            ->where('groups.2.items.0.key', 'returns.window_days')->where('groups.2.items.0.value', 7));
    }

    public function test_only_the_owner_side_reaches_it(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->get('/admin/settings')->assertRedirect('/');
        $this->put('/admin/settings/orders', $this->orders())->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->get('/admin/settings')->assertRedirect('/');
        $this->assertNull(Setting::find('orders.holidays'));
    }

    public function test_a_holiday_makes_the_order_message_say_so(): void
    {
        $day = Carbon::parse('2026-12-16 11:00');
        $this->assertNull(app(OrderNotice::class)->at($day));

        $this->actingAs($this->admin)->put('/admin/settings/orders', $this->orders(['orders_holidays' => ['2026-12-16']]))->assertSessionHasNoErrors();

        $this->assertSame('holiday', app(OrderNotice::class)->at($day));
        $this->assertNull(app(OrderNotice::class)->at($day->copy()->addDay()));
    }

    public function test_the_weekly_closed_days_and_night_hours_change_what_buyers_are_told(): void
    {
        $tuesdayNoon = Carbon::parse('2026-12-15 12:00');
        $this->actingAs($this->admin)->put('/admin/settings/orders', $this->orders(['orders_weekly_off' => [2, 5], 'orders_night_from' => 12, 'orders_night_to' => 14]))->assertSessionHasNoErrors();

        $this->assertSame('holiday', app(OrderNotice::class)->at($tuesdayNoon));
        $this->assertSame('night', app(OrderNotice::class)->at(Carbon::parse('2026-12-16 13:00')));
    }

    public function test_other_rules_are_stored_where_the_code_reads_them(): void
    {
        $this->actingAs($this->admin)->put('/admin/settings/returns', ['returns_window_days' => 5, 'returns_cash_percent' => 40, 'returns_credit_warn_days' => 30])->assertSessionHasNoErrors();

        $this->assertSame(5, Setting::read('returns.window_days'));
        $this->assertSame(40, Setting::read('returns.cash_percent'));
    }

    public function test_numbers_stay_inside_their_limits_and_dates_must_be_real(): void
    {
        $this->actingAs($this->admin);

        $this->put('/admin/settings/orders', $this->orders(['orders_night_from' => 30]))->assertSessionHasErrors('orders_night_from');
        $this->put('/admin/settings/orders', $this->orders(['orders_holidays' => ['not a date']]))->assertSessionHasErrors('orders_holidays.0');
        $this->put('/admin/settings/orders', $this->orders(['orders_weekly_off' => [9]]))->assertSessionHasErrors('orders_weekly_off.0');
        $this->put('/admin/settings/returns', ['returns_window_days' => 5, 'returns_cash_percent' => 150, 'returns_credit_warn_days' => 30])->assertSessionHasErrors('returns_cash_percent');
        $this->put('/admin/settings/nothing', [])->assertNotFound();
        $this->assertNull(Setting::find('orders.night_from'));
    }

    public function test_a_change_is_logged_with_what_it_was_before_and_a_save_with_no_change_is_not(): void
    {
        $this->actingAs($this->admin)->put('/admin/settings/returns', ['returns_window_days' => 3, 'returns_cash_percent' => 50, 'returns_credit_warn_days' => 45])->assertSessionHasNoErrors();
        $this->assertSame(0, ActivityLog::where('action', 'settings.changed')->count());

        $this->put('/admin/settings/returns', ['returns_window_days' => 7, 'returns_cash_percent' => 50, 'returns_credit_warn_days' => 45]);

        $log = ActivityLog::where('action', 'settings.changed')->firstOrFail();
        $this->assertSame(3, $log->changes['returns.window_days']['before']);
        $this->assertSame(7, $log->changes['returns.window_days']['after']);
        $this->assertCount(1, $log->changes);
    }

    public function test_the_shop_name_is_text_and_cannot_be_empty(): void
    {
        $this->actingAs($this->admin);

        $this->put('/admin/settings/counter', ['shop_name' => '', 'counter_staff_discount_limit' => 5, 'approvals_expire_minutes' => 10, 'flags_cancel_limit' => 3, 'prescriptions_alert_minutes' => 30])->assertSessionHasErrors('shop_name');
        $this->put('/admin/settings/counter', ['shop_name' => 'Siam Pharma', 'counter_staff_discount_limit' => 5, 'approvals_expire_minutes' => 10, 'flags_cancel_limit' => 3, 'prescriptions_alert_minutes' => 30])->assertSessionHasNoErrors();
        $this->assertSame('Siam Pharma', Setting::read('shop.name'));
    }
}
