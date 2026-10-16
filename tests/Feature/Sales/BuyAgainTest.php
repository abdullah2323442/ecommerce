<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\BuyAgain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13, "Buy again": someone who buys the same thing about every month is reminded when it is time. */
class BuyAgainTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private Medicine $napa;

    private BuyAgain $again;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Hasan Ali'])->fresh();
        $company = Company::create(['name' => 'Square']);
        $this->napa = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true]);
        $this->again = app(BuyAgain::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** One finished order of this medicine, this many days ago. */
    private function bought(int $daysAgo, ?Medicine $m = null, ?User $by = null, array $over = []): void
    {
        $invoice = Invoice::create(array_merge(['kind' => 'retail', 'channel' => 'online', 'status' => 'final', 'customer_id' => ($by ?? $this->buyer)->id, 'finalised_at' => now()->subDays($daysAgo), 'opened_at' => now()->subDays($daysAgo)], $over));
        InvoiceLine::create(['invoice_id' => $invoice->id, 'medicine_id' => ($m ?? $this->napa)->id, 'unit' => 'strip', 'quantity' => 1, 'stock_units' => 0.3, 'unit_mrp' => 100, 'unit_price' => 100]);
    }

    private function monthly(int $last = 27): void
    {
        // Bought 87, 57 and 27 days ago: every 30 days.
        foreach ([$last + 60, $last + 30, $last] as $d) {
            $this->bought($d);
        }
    }

    // ------------------------------------------------------------------ the rhythm

    public function test_a_medicine_bought_every_thirty_days_has_a_rhythm(): void
    {
        $this->monthly();

        $r = $this->again->rhythms($this->buyer);

        $this->assertSame(30, $r[$this->napa->id]['every']);
        $this->assertSame('2026-09-17', $r[$this->napa->id]['last']->toDateString());
    }

    public function test_too_few_buys_or_no_regularity_means_no_rhythm(): void
    {
        $this->bought(60);
        $this->bought(30);
        $this->assertSame([], $this->again->rhythms($this->buyer));   // twice is not a habit

        $other = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Seclo', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 300, 'is_active' => true]);
        foreach ([100, 90, 30] as $d) {   // gaps of 10 and 60: all over the place
            $this->bought($d, $other);
        }
        $this->assertArrayNotHasKey($other->id, $this->again->rhythms($this->buyer));
    }

    public function test_every_few_days_or_once_a_year_is_not_a_monthly_habit(): void
    {
        $weekly = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Weekly', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 300, 'is_active' => true]);
        foreach ([21, 14, 7] as $d) {
            $this->bought($d, $weekly);
        }
        $this->assertSame([], $this->again->rhythms($this->buyer));
    }

    public function test_two_orders_on_one_day_count_as_one_buy(): void
    {
        $this->bought(60);
        $this->bought(30);
        $this->bought(30);

        $this->assertSame([], $this->again->rhythms($this->buyer));
    }

    // ------------------------------------------------------------------ the reminder

    public function test_it_is_not_sent_too_early_but_is_sent_from_two_days_before(): void
    {
        $this->monthly(25);

        $this->assertSame(0, $this->again->remind());                      // 25 days: not yet
        $this->assertSame(1, $this->again->remind(now()->addDays(3)));     // 28 days: two days before the 30th
        $this->assertSame(0, $this->again->remind(now()->addDays(4)));     // not twice for the same purchase

        $notice = Notice::where('user_id', $this->buyer->id)->where('kind', 'buy_again')->firstOrFail();
        $body = json_decode($notice->body, true);
        $this->assertSame(['Napa', 30, "/medicines/{$this->napa->id}"], [$body['name'], $body['days'], $body['link']]);
    }

    public function test_after_a_new_purchase_the_next_cycle_reminds_again(): void
    {
        $this->monthly(28);
        $this->assertSame(1, $this->again->remind());

        $this->bought(0);   // bought again today
        // ...and a month on it is due once more.
        $this->assertSame(1, $this->again->remind(now()->addDays(29)));
        $this->assertSame(2, Notice::where('kind', 'buy_again')->count());
    }

    public function test_a_buyer_who_never_came_back_is_not_chased_for_ever(): void
    {
        $this->monthly(70);   // due 40 days ago

        $this->assertSame(0, $this->again->remind());
    }

    public function test_a_buyer_who_turned_it_off_gets_nothing_and_so_does_the_owner_when_off(): void
    {
        $this->monthly(28);
        $this->buyer->forceFill(['buy_again' => false])->save();
        $this->assertSame(0, $this->again->remind());

        $this->buyer->forceFill(['buy_again' => true])->save();
        Setting::write('buyagain.enabled', false);
        $this->assertSame(0, $this->again->remind());

        Setting::write('buyagain.enabled', true);
        $this->assertSame(1, $this->again->remind());
    }

    public function test_how_many_buys_make_a_habit_is_a_setting(): void
    {
        foreach ([88, 59, 30, 0] as $d) {
            $this->bought($d);
        }
        Setting::write('buyagain.min_buys', 5);
        $this->assertSame([], $this->again->rhythms($this->buyer));

        Setting::write('buyagain.min_buys', 4);
        $this->assertCount(1, $this->again->rhythms($this->buyer));
    }

    public function test_controlled_inactive_cancelled_and_outside_buys_do_not_count_and_only_plain_accounts_are_nudged(): void
    {
        $controlled = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Morphine', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 300, 'is_active' => true, 'is_controlled' => true]);
        foreach ([88, 58, 28] as $d) {
            $this->bought($d, $controlled);
        }
        foreach ([88, 58, 28] as $d) {
            $this->bought($d, null, null, ['status' => 'cancelled']);
        }
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        foreach ([88, 58, 28] as $d) {
            $this->bought($d, null, $shop);
        }

        $this->assertSame(0, $this->again->remind());
        $this->assertSame(0, Notice::where('kind', 'buy_again')->count());
    }

    public function test_two_buyers_are_each_worked_out_on_their_own(): void
    {
        $this->monthly(28);
        $second = User::factory()->create(['account_type' => AccountType::Public])->fresh();
        foreach ([40, 20, 0] as $d) {
            $this->bought($d, null, $second);   // every 20 days, last today: nothing due
        }

        $this->assertSame(1, $this->again->remind());
        $this->assertSame([$this->buyer->id], Notice::where('kind', 'buy_again')->pluck('user_id')->all());
    }

    // ------------------------------------------------------------------ the switch

    public function test_the_buyer_sees_and_changes_the_switch_on_the_orders_page(): void
    {
        $this->actingAs($this->buyer)->get('/my-orders')->assertInertia(fn (Assert $p) => $p->where('buyAgain', true));

        $this->put('/my-orders/buy-again', ['on' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->buyer->fresh()->buy_again);
        $this->get('/my-orders')->assertInertia(fn (Assert $p) => $p->where('buyAgain', false));

        $this->put('/my-orders/buy-again', ['on' => true]);
        $this->assertTrue($this->buyer->fresh()->buy_again);
    }

    public function test_a_visitor_cannot_use_the_switch_and_it_must_say_on_or_off(): void
    {
        $this->put('/my-orders/buy-again', ['on' => false])->assertRedirect();
        $this->actingAs($this->buyer)->put('/my-orders/buy-again', [])->assertSessionHasErrors('on');
    }
}
