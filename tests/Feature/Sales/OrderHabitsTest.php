<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\OrderHabits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 7: each shop is reminded at the time of day it usually orders, and can turn that off. */
class OrderHabitsTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private OrderHabits $habits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Karim Pharmacy'])->fresh();
        $this->habits = app(OrderHabits::class);
        // A Monday, so no weekly closed day gets in the way.
        Carbon::setTestNow('2026-10-12 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One online order for the shop at this time on a day some days ago. */
    private function order(string $time, int $daysAgo, ?User $shop = null, string $channel = 'online'): Invoice
    {
        $at = Carbon::parse('2026-10-12 '.$time)->subDays($daysAgo);

        return Invoice::create(['kind' => 'paikari', 'channel' => $channel, 'status' => 'placed', 'customer_id' => ($shop ?? $this->shop)->id, 'opened_at' => $at, 'placed_at' => $at, 'last_activity_at' => $at]);
    }

    private function usuallyAt(string $time, array $days = [3, 10, 17, 24]): void
    {
        foreach ($days as $d) {
            $this->order($time, $d);
        }
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse('2026-10-12 '.$time);
    }

    // ------------------------------------------------------------------ learning

    public function test_the_usual_time_is_worked_out_from_the_shops_own_orders(): void
    {
        foreach (['21:20', '21:30', '21:40'] as $i => $time) {
            $this->order($time, $i + 2);
        }

        $this->assertSame('21:30', $this->habits->usualTime($this->shop));
    }

    public function test_each_shop_has_its_own_time(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->usuallyAt('21:30');
        foreach ([2, 9, 16] as $d) {
            $this->order('08:15', $d, $other);
        }

        $this->assertSame('21:30', $this->habits->usualTime($this->shop));
        $this->assertSame('08:15', $this->habits->usualTime($other));
    }

    public function test_times_either_side_of_midnight_are_close_together(): void
    {
        $this->order('23:50', 2);
        $this->order('00:10', 3);
        $this->order('23:55', 4);

        $this->assertContains($this->habits->usualTime($this->shop), ['23:58', '23:59', '00:00', '23:57', '23:56']);
    }

    public function test_too_few_orders_or_no_regular_time_means_no_habit(): void
    {
        $this->order('21:30', 2);
        $this->order('21:30', 3);
        $this->assertNull($this->habits->usualTime($this->shop));   // two orders only

        $this->order('06:00', 4);
        $this->order('12:00', 5);
        $this->order('18:00', 6);
        $this->order('00:00', 7);
        $this->assertNull($this->habits->usualTime($this->shop));   // all over the clock
    }

    public function test_only_the_shops_own_online_orders_in_the_last_three_months_count(): void
    {
        $this->order('21:30', 2);
        $this->order('21:30', 3);
        $this->order('21:30', 4, null, 'counter');   // made by staff on the phone: not the shop's own habit
        $this->order('21:30', 120);                    // too old

        $this->assertNull($this->habits->usualTime($this->shop));
    }

    // ------------------------------------------------------------------ sending

    public function test_the_reminder_goes_at_the_usual_time_once_a_day(): void
    {
        $this->usuallyAt('21:30');

        $this->assertSame(0, $this->habits->remind($this->at('20:00')));
        $this->assertSame(0, Notice::where('kind', 'order_habit')->count());

        $this->assertSame(1, $this->habits->remind($this->at('21:35')));
        $notice = Notice::where('user_id', $this->shop->id)->where('kind', 'order_habit')->firstOrFail();
        $this->assertStringContainsString('/paikari', $notice->body);

        // The next ten-minute run and the one after it do not repeat it.
        $this->assertSame(0, $this->habits->remind($this->at('21:40')));
        $this->assertSame(1, Notice::where('kind', 'order_habit')->count());
    }

    public function test_a_shop_that_already_ordered_today_is_not_nudged(): void
    {
        $this->usuallyAt('21:30');
        $this->order('09:00', 0);

        $this->assertSame(0, $this->habits->remind($this->at('21:32')));
    }

    public function test_a_shop_that_turned_it_off_gets_nothing(): void
    {
        $this->usuallyAt('21:30');
        $this->shop->forceFill(['habit_reminders' => false])->save();

        $this->assertSame(0, $this->habits->remind($this->at('21:32')));
    }

    public function test_nothing_is_sent_on_a_closed_day_or_when_the_owner_turns_the_feature_off(): void
    {
        $this->usuallyAt('21:30');

        Setting::write('orders.holidays', ['2026-10-12']);
        $this->assertSame(0, $this->habits->remind($this->at('21:32')));

        Setting::write('orders.holidays', []);
        Setting::write('habits.enabled', false);
        $this->assertSame(0, $this->habits->remind($this->at('21:32')));

        Setting::write('habits.enabled', true);
        $this->assertSame(1, $this->habits->remind($this->at('21:32')));
    }

    public function test_the_weekly_closed_day_is_respected(): void
    {
        $this->usuallyAt('21:30');
        $friday = Carbon::parse('2026-10-16 21:32');   // Friday is the default closed day

        $this->assertSame(0, $this->habits->remind($friday));
    }

    public function test_a_suspended_shop_and_other_accounts_are_left_alone(): void
    {
        $this->usuallyAt('21:30');
        $this->shop->forceFill(['suspended_at' => now()])->save();

        $visitor = User::factory()->create(['account_type' => AccountType::Public]);
        foreach ([3, 10, 17] as $d) {
            $this->order('21:30', $d, $visitor);
        }

        $this->assertSame(0, $this->habits->remind($this->at('21:32')));
    }

    public function test_a_bulk_buyer_is_pointed_at_its_own_page(): void
    {
        $bulk = User::factory()->create(['account_type' => AccountType::Wholesaler]);
        foreach ([3, 10, 17] as $d) {
            $this->order('10:00', $d, $bulk);
        }

        $this->assertSame(1, $this->habits->remind($this->at('10:05')));
        $this->assertStringContainsString('/wholesale', Notice::where('user_id', $bulk->id)->firstOrFail()->body);
    }

    // ------------------------------------------------------------------ the shop's switch

    public function test_the_shop_sees_when_it_is_reminded_and_can_switch_it_off_and_on(): void
    {
        $this->usuallyAt('21:30');

        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('reminder.on', true)->where('reminder.at', '21:30'));

        $this->put('/paikari/reminders', ['on' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->shop->fresh()->habit_reminders);
        $this->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('reminder.on', false));

        $this->put('/paikari/reminders', ['on' => true]);
        $this->assertTrue($this->shop->fresh()->habit_reminders);
    }

    public function test_the_time_is_not_shown_when_the_owner_has_the_feature_off_and_others_cannot_use_the_switch(): void
    {
        $this->usuallyAt('21:30');
        Setting::write('habits.enabled', false);
        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('reminder.at', null));

        $visitor = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($visitor)->put('/paikari/reminders', ['on' => false])->assertRedirect('/');
        $this->actingAs($this->shop)->put('/paikari/reminders', [])->assertSessionHasErrors('on');
    }
}
