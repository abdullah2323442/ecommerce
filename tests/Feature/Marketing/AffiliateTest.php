<?php

namespace Tests\Feature\Marketing;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Affiliate;
use App\Models\AffiliateEarning;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketing\Affiliates;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27, affiliate: a link carries a code, orders that come by it earn the affiliate a share once they are complete, and the owner pays it out. */
class AffiliateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'seller_trusted_at' => now()]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->chair = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'delivery_charge' => 100, 'stock' => 20, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
    }

    private function affiliates(): Affiliates
    {
        return app(Affiliates::class);
    }

    private function open(array $more = []): Affiliate
    {
        return $this->affiliates()->create($more + ['name' => 'Rima Promoter', 'percent' => 5, 'code' => 'RIMA5'], $this->admin);
    }

    private function buy(?User $buyer = null, string $code = 'RIMA5'): MarketOrder
    {
        $r = $this->withCookie(Affiliates::COOKIE, $code);
        if ($buyer) {
            $r = $r->actingAs($buyer);
        }
        $r->post("/market/{$this->chair->id}/order", ['quantity' => 2, 'name' => 'Hasan', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'])->assertSessionHasNoErrors();

        return MarketOrder::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ opening one

    public function test_an_affiliate_has_a_code_a_share_and_a_link(): void
    {
        $a = $this->affiliates()->create(['name' => 'Rima Promoter', 'percent' => 5], $this->admin);

        $this->assertMatchesRegularExpression('/^RIMAPR[A-Z0-9]{3}$/', $a->code);
        $this->assertEquals(5, $a->percent);
        $this->assertSame(url('/a/'.$a->code), $this->affiliates()->link($a));
        $this->assertDatabaseHas('activity_logs', ['action' => 'affiliate.created']);
    }

    public function test_a_share_a_phone_and_a_code_must_be_sensible_and_a_code_is_never_given_twice(): void
    {
        $this->open();

        foreach ([['percent' => 0], ['percent' => 60], ['name' => ''], ['phone' => '12345'], ['code' => 'a b'], ['code' => 'ab'], ['code' => 'rima5']] as $bad) {
            try {
                $this->open($bad + ['code' => 'RIMA6']);
                $this->fail('A bad affiliate was opened.');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, Affiliate::count());
    }

    public function test_an_affiliate_with_an_account_is_linked_to_it_and_cannot_be_opened_twice(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public, 'phone' => '01712340000']);

        $a = $this->open(['phone' => '01712340000']);

        $this->assertSame($user->id, $a->user_id);
        $this->expectException(StockException::class);
        $this->open(['phone' => '01712340000', 'code' => 'OTHER1']);
    }

    // ------------------------------------------------------------------ the link

    public function test_opening_the_link_counts_the_visit_keeps_the_code_and_sends_the_visitor_on(): void
    {
        $a = $this->open();

        $this->get('/a/rima5')->assertRedirect('/')->assertCookie(Affiliates::COOKIE, 'RIMA5');
        $this->get('/a/RIMA5?to=/market')->assertRedirect('/market');

        $this->assertSame(2, $a->fresh()->clicks);
    }

    public function test_the_link_cannot_be_used_to_send_people_to_another_site(): void
    {
        $this->open();

        foreach (['//evil.example', 'https://evil.example', '/\\evil.example', 'javascript:alert(1)'] as $to) {
            $this->get('/a/RIMA5?to='.urlencode($to))->assertRedirect('/');
        }
    }

    public function test_an_unknown_or_switched_off_code_leaves_no_trace(): void
    {
        $a = $this->open();
        $this->affiliates()->update($a, ['active' => false], $this->admin);

        $this->get('/a/RIMA5')->assertRedirect('/')->assertCookieMissing(Affiliates::COOKIE);
        $this->get('/a/NOSUCH')->assertRedirect('/')->assertCookieMissing(Affiliates::COOKIE);
        $this->assertSame(0, $a->fresh()->clicks);
    }

    public function test_the_code_stays_on_the_visitor_for_the_number_of_days_the_owner_set(): void
    {
        $this->open();
        Setting::write('affiliate.days', 10);

        $cookie = $this->get('/a/RIMA5')->getCookie(Affiliates::COOKIE, false);

        $this->assertEqualsWithDelta(now()->addDays(10)->timestamp, $cookie->getExpiresTime(), 120);
    }

    // ------------------------------------------------------------------ earning

    public function test_an_order_that_came_by_the_link_is_the_affiliates_and_earns_the_share_once_delivered(): void
    {
        $a = $this->open();

        $order = $this->buy();
        $this->assertSame($a->id, $order->affiliate_id);
        $this->assertSame(0, AffiliateEarning::count()); // not yet: the goods have not arrived

        $order->update(['status' => 'delivered']);
        $order->update(['note' => 'touched again']);

        $e = AffiliateEarning::firstOrFail();
        $this->assertSame('earn', $e->kind);
        $this->assertEquals(2040, (float) $e->base); // two chairs at 1,020: the delivery is not counted
        $this->assertEquals(5, $e->percent);
        $this->assertEquals(102.0, (float) $e->amount);
        $this->assertSame('owed', $e->status);
        $this->assertSame(1, AffiliateEarning::count());
    }

    public function test_an_order_without_the_code_or_with_a_wrong_one_belongs_to_nobody(): void
    {
        $this->open();

        $this->post("/market/{$this->chair->id}/order", ['quantity' => 1, 'name' => 'Hasan', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4']);
        $this->buy(null, 'NOSUCH1');

        $this->assertSame([null, null], MarketOrder::orderBy('id')->pluck('affiliate_id')->all());
    }

    public function test_an_affiliate_is_never_paid_for_their_own_orders(): void
    {
        $me = User::factory()->create(['account_type' => AccountType::Public, 'phone' => '01712340000']);
        $this->open(['phone' => '01712340000']);

        $order = $this->buy($me);
        $order->update(['status' => 'delivered']);

        $this->assertNull($order->affiliate_id);
        $this->assertSame(0, AffiliateEarning::count());
    }

    public function test_a_shop_memo_from_the_website_earns_when_it_is_finished(): void
    {
        $a = $this->open();
        request()->cookies->set(Affiliates::COOKIE, 'RIMA5');
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $memo = Invoice::create(['kind' => 'paikari', 'channel' => 'online', 'status' => 'final', 'customer_id' => $shop->id, 'number' => 'M2026-000001', 'total' => 1050, 'delivery_charge' => 50, 'finalised_at' => now(), 'opened_at' => now()]);
        $this->assertSame($a->id, $memo->affiliate_id);
        $this->assertSame(0, AffiliateEarning::count());

        $memo->update(['completed_at' => now()]);

        $this->assertEquals(50.0, (float) AffiliateEarning::firstOrFail()->amount); // 5 percent of 1,000
    }

    public function test_a_counter_sale_is_never_an_affiliates(): void
    {
        $this->open();
        request()->cookies->set(Affiliates::COOKIE, 'RIMA5');
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $memo = Invoice::create(['kind' => 'paikari', 'channel' => 'counter', 'status' => 'final', 'customer_id' => $shop->id, 'number' => 'M2026-000002', 'total' => 1050, 'finalised_at' => now(), 'completed_at' => now(), 'opened_at' => now()]);

        $this->assertNull($memo->affiliate_id);
        $this->assertSame(0, AffiliateEarning::count());
    }

    // ------------------------------------------------------------------ taking back

    public function test_a_cancelled_order_that_was_not_paid_yet_loses_its_earning(): void
    {
        $this->open();
        $order = $this->buy();
        $order->update(['status' => 'delivered']);

        $order->update(['status' => 'returned']);

        $this->assertSame('void', AffiliateEarning::firstOrFail()->status);
        $this->assertSame(0.0, (float) $this->affiliates()->overview()->first()['owed']);
    }

    public function test_after_a_payout_a_returned_order_is_taken_off_the_next_one(): void
    {
        $a = $this->open();
        $order = $this->buy();
        $order->update(['status' => 'delivered']);
        $this->affiliates()->pay($a, 'bkash', null, $this->admin);

        $order->update(['status' => 'returned']);

        $back = AffiliateEarning::where('kind', 'takeback')->firstOrFail();
        $this->assertEquals(-102.0, (float) $back->amount);
        $this->assertSame('owed', $back->status);
        $this->assertEquals(-102.0, $this->affiliates()->overview()->first()['owed']);

        $this->expectException(StockException::class); // nothing is owed to them: it is the other way round
        $this->affiliates()->pay($a, 'cash', null, $this->admin);
    }

    // ------------------------------------------------------------------ paying

    public function test_paying_settles_everything_owed_in_one_row_with_who_paid_and_how(): void
    {
        $a = $this->open();
        $this->buy()->update(['status' => 'delivered']);
        $this->buy()->update(['status' => 'delivered']);

        $p = $this->affiliates()->pay($a, 'bank', 'TRX 7781', $this->admin);

        $this->assertEquals(204.0, (float) $p->amount);
        $this->assertSame($this->admin->id, $p->paid_by);
        $this->assertSame(['paid', 'paid'], AffiliateEarning::orderBy('id')->pluck('status')->all());
        $this->assertSame(0.0, (float) $this->affiliates()->overview()->first()['owed']);
        $this->assertEquals(204.0, $this->affiliates()->overview()->first()['paid']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'affiliate.paid']);
    }

    public function test_nothing_owed_means_nothing_to_pay_and_the_method_must_be_a_known_one(): void
    {
        $a = $this->open();

        foreach (['cash', 'crypto'] as $method) {
            try {
                $this->affiliates()->pay($a, $method, null, $this->admin);
                $this->fail('Paid with nothing owed or an unknown method.');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_new_share_counts_for_new_orders_only(): void
    {
        $a = $this->open();
        $this->buy()->update(['status' => 'delivered']);

        $this->affiliates()->update($a, ['percent' => 10], $this->admin);
        $this->buy()->update(['status' => 'delivered']);

        $this->assertEquals([5.0, 10.0], AffiliateEarning::orderBy('id')->pluck('percent')->map(fn ($p) => (float) $p)->all());
    }

    // ------------------------------------------------------------------ screens

    public function test_the_owners_page_shows_each_affiliate_the_link_and_what_is_owed(): void
    {
        $a = $this->open();
        $this->buy()->update(['status' => 'delivered']);

        $this->actingAs($this->admin)->get("/admin/affiliates?affiliate={$a->id}")->assertInertia(fn (Assert $p) => $p
            ->component('admin/affiliates')->where('affiliates.0.code', 'RIMA5')->where('affiliates.0.owed', 102)->where('affiliates.0.orders', 1)->where('chosen.link', url('/a/RIMA5'))->has('chosen.earnings', 1)->where('totals.owed', 102));
    }

    public function test_an_affiliate_with_an_account_sees_their_own_page_and_nobody_elses(): void
    {
        $me = User::factory()->create(['account_type' => AccountType::Public, 'phone' => '01712340000']);
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $this->open(['phone' => '01712340000']);

        $this->actingAs($me)->get('/affiliate')->assertInertia(fn (Assert $p) => $p->component('affiliate')->where('affiliate.code', 'RIMA5')->where('affiliate.days', 30));
        $this->actingAs($other)->get('/affiliate')->assertRedirect('/');
        $this->actingAs($me)->get('/wallet')->assertInertia(fn (Assert $p) => $p->where('isAffiliate', true));
    }

    public function test_only_those_who_manage_customers_can_run_the_programme(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $a = $this->open();

        $this->get('/admin/affiliates')->assertRedirect('/login');
        foreach ([$staff, $buyer] as $who) {
            $this->actingAs($who)->get('/admin/affiliates')->assertRedirect('/');
            $this->actingAs($who)->post('/admin/affiliates', ['name' => 'X Y', 'percent' => 5])->assertRedirect('/');
            $this->actingAs($who)->post("/admin/affiliates/{$a->id}/pay", ['method' => 'cash'])->assertRedirect('/');
        }
        $this->assertSame(1, Affiliate::count());
    }

    public function test_the_owner_adds_and_changes_an_affiliate_from_the_page(): void
    {
        $this->actingAs($this->admin)->post('/admin/affiliates', ['name' => 'Karim Promoter', 'percent' => 3, 'code' => 'KARIM3'])->assertSessionHasNoErrors();
        $a = Affiliate::where('code', 'KARIM3')->firstOrFail();

        $this->put("/admin/affiliates/{$a->id}", ['percent' => 4, 'active' => false])->assertSessionHasNoErrors();
        $this->post('/admin/affiliates', ['name' => 'Dup', 'percent' => 3, 'code' => 'KARIM3'])->assertSessionHasErrors('affiliate');

        $this->assertEquals(4, $a->fresh()->percent);
        $this->assertFalse($a->fresh()->active);
    }
}
