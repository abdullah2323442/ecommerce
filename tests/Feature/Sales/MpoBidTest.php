<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\MpoBid;
use App\Models\MpoListing;
use App\Models\Notice;
use App\Models\Preorder;
use App\Models\Setting;
use App\Models\Tier;
use App\Models\User;
use App\Services\Mpo\MpoBidding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 10: blind bidding on MPO products, the MPO takes one, a prompt to raise the percentage, and orders not delivered. */
class MpoBidTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $mpo;

    private Medicine $napa;

    private MpoListing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::write('shop.name', 'Siam Store');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Beacon']);
        $this->napa = Medicine::create([
            'company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->mpo = User::factory()->create(['account_type' => AccountType::Mpo, 'name' => 'Rafiq Hasan', 'mpo_number' => 1]);
        $this->mpo->mpoCompanies()->attach($company->id);
        $this->mpo->mpoProducts()->attach($this->napa->id);
        $this->listing = MpoListing::create([
            'user_id' => $this->mpo->id, 'medicine_id' => $this->napa->id, 'status' => 'approved', 'stock_status' => 'in_stock', 'markets' => ['wholesale', 'public'],
            'price_wholesale' => 240, 'price_public' => 270, 'bonus_buy' => 10, 'bonus_free' => 2, 'show_bonus' => true, 'approved_at' => now()->subDays(10),
        ]);
    }

    private function wholesaler(string $name): User
    {
        return User::factory()->create(['account_type' => AccountType::Wholesaler, 'name' => $name, 'phone' => '01'.random_int(300000000, 999999999), 'tier_id' => Tier::firstOrCreate(['name' => 'A'], ['discount_percent' => 10])->id, 'categories' => ['medicine']]);
    }

    private function bid(User $by, float $percent = 15, int $boxes = 20)
    {
        return $this->actingAs($by)->post("/mpo-offers/{$this->listing->id}/bid", ['percent' => $percent, 'boxes' => $boxes]);
    }

    public function test_a_bulk_buyer_bids_a_percentage_and_the_price_comes_off_the_printed_price(): void
    {
        $this->bid($this->wholesaler('Rahim'), 15, 20)->assertSessionHasNoErrors();

        $bid = MpoBid::firstOrFail();
        $this->assertSame('15.00', (string) $bid->percent);
        $this->assertSame('255.00', (string) $bid->box_price);   // 300 less 15%
        $this->assertSame('open', $bid->status);
    }

    public function test_only_a_bulk_buyer_can_bid_and_only_where_the_product_is_in_the_bulk_market(): void
    {
        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Staff] as $type) {
            $this->bid(User::factory()->create(['account_type' => $type]));
        }
        $this->assertSame(0, MpoBid::count());

        $this->listing->update(['markets' => ['public']]);
        $this->bid($this->wholesaler('Rahim'))->assertSessionHasErrors('offer');

        $this->listing->update(['markets' => ['wholesale'], 'status' => 'closed']);
        $this->bid($this->wholesaler('Karim'))->assertSessionHasErrors('offer');
        $this->assertSame(0, MpoBid::count());
    }

    public function test_the_offer_is_checked(): void
    {
        $w = $this->wholesaler('Rahim');

        $this->bid($w, 0)->assertSessionHasErrors('percent');
        $this->bid($w, 95)->assertSessionHasErrors('percent');
        $this->bid($w, 10, 0)->assertSessionHasErrors('boxes');
        $this->assertSame(0, MpoBid::count());
    }

    public function test_bidding_again_changes_the_open_offer_instead_of_adding_one(): void
    {
        $w = $this->wholesaler('Rahim');
        $this->bid($w, 10, 5);
        $this->bid($w, 18, 8);

        $this->assertSame(1, MpoBid::count());
        $this->assertSame('18.00', (string) MpoBid::firstOrFail()->percent);
        $this->assertSame(1, Notice::where('user_id', $this->mpo->id)->where('kind', 'mpo_bid_new')->count());   // told once
    }

    public function test_each_bulk_buyer_sees_only_their_own_offer(): void
    {
        $a = $this->wholesaler('Rahim');
        $b = $this->wholesaler('Karim');
        $this->bid($a, 12, 10);
        $this->bid($b, 20, 30);

        $this->actingAs($a)->getJson('/wholesale/search?q=Na')->assertJsonPath('results.0.mpo_offers.0.my_bid.percent', 12)->assertJsonPath('results.0.mpo_offers.0.my_bid.boxes', 10);
        $json = $this->getJson('/wholesale/search?q=Na')->getContent();
        $this->assertStringNotContainsString('Karim', $json);
        $this->assertStringNotContainsString('"percent":20', $json);

        $this->actingAs($b)->getJson('/wholesale/search?q=Na')->assertJsonPath('results.0.mpo_offers.0.my_bid.percent', 20);
    }

    public function test_a_shop_or_a_visitor_never_sees_the_bidding(): void
    {
        $this->bid($this->wholesaler('Rahim'));
        auth()->logout();

        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->missing('mpoOffers.0.can_bid')->missing('mpoOffers.0.my_bid'));
    }

    public function test_the_mpo_sees_the_bids_without_names_and_the_owner_sees_who_bid(): void
    {
        $this->bid($this->wholesaler('Rahim Traders'), 10, 20);
        $this->bid($this->wholesaler('Karim Pharma'), 15, 50);

        $props = $this->actingAs($this->mpo)->get('/mpo')->assertInertia(fn (Assert $p) => $p
            ->where('listings.0.bids.0.alias', 'Anonymous 1')->where('listings.0.bids.0.percent', 10)->where('listings.0.bids.1.alias', 'Anonymous 2')->where('listings.0.bids.1.percent', 15))->viewData('page')['props'];
        $json = json_encode($props['listings']);
        $this->assertStringNotContainsString('Rahim', $json);
        $this->assertStringNotContainsString('Karim', $json);
        $this->assertStringNotContainsString('bidder', $json);

        $this->actingAs($this->admin)->get('/admin/mpo/listings')->assertInertia(fn (Assert $p) => $p->where('groups.0.listings.0.bids.0.who', 'Rahim Traders')->where('groups.0.listings.0.bids.1.who', 'Karim Pharma'));
    }

    public function test_the_mpo_accepts_one_bid_the_order_is_made_the_others_are_declined_and_everyone_is_told(): void
    {
        $a = $this->wholesaler('Rahim');
        $b = $this->wholesaler('Karim');
        $this->bid($a, 10, 20);
        $this->bid($b, 15, 25);
        $winning = MpoBid::where('bidder_id', $b->id)->firstOrFail();

        $this->actingAs($this->mpo)->post("/mpo/bids/{$winning->id}/accept")->assertSessionHasNoErrors();

        $pre = Preorder::firstOrFail();
        $this->assertSame($b->id, $pre->customer_id);
        $this->assertSame('255.00', (string) $pre->box_price);
        $this->assertSame(4, $pre->bonus_free);   // 25 boxes at 10+2
        $this->assertSame('accepted', $pre->status);
        $this->assertSame('rejected', MpoBid::where('bidder_id', $a->id)->firstOrFail()->status);
        $this->assertSame('accepted', $winning->fresh()->status);
        $this->assertContains('mpo_bid_accepted', Notice::where('user_id', $b->id)->pluck('kind')->all());
        $this->assertContains('mpo_bid_declined', Notice::where('user_id', $a->id)->pluck('kind')->all());
    }

    public function test_a_bid_that_is_not_open_or_not_theirs_cannot_be_accepted(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Mpo, 'mpo_number' => 2]);
        $this->bid($this->wholesaler('Rahim'));
        $bid = MpoBid::firstOrFail();

        $this->actingAs($other)->post("/mpo/bids/{$bid->id}/accept")->assertRedirect('/');
        $this->assertSame('open', $bid->fresh()->status);

        $this->actingAs($this->mpo)->post("/mpo/bids/{$bid->id}/accept")->assertSessionHasNoErrors();
        $this->post("/mpo/bids/{$bid->id}/accept")->assertSessionHasErrors('listing');
        $this->assertSame(1, Preorder::count());
    }

    public function test_the_bulk_buyer_can_withdraw_but_only_their_own(): void
    {
        $a = $this->wholesaler('Rahim');
        $b = $this->wholesaler('Karim');
        $this->bid($a);
        $bid = MpoBid::firstOrFail();

        $this->actingAs($b)->delete("/mpo-bids/{$bid->id}")->assertRedirect('/');
        $this->assertSame('open', $bid->fresh()->status);
        $this->actingAs($a)->delete("/mpo-bids/{$bid->id}")->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $bid->fresh()->status);
        $this->actingAs($this->mpo)->get('/mpo')->assertInertia(fn (Assert $p) => $p->has('listings.0.bids', 0));
    }

    public function test_a_product_that_is_not_selling_asks_the_mpo_to_raise_the_percentage_once_per_period(): void
    {
        $this->assertSame(1, app(MpoBidding::class)->tellStale());
        $notice = Notice::where('user_id', $this->mpo->id)->where('kind', 'mpo_stale')->firstOrFail();
        $this->assertSame('urgent', $notice->importance);

        $this->assertSame(0, app(MpoBidding::class)->tellStale());   // not again the same day
        $this->listing->update(['stale_told_at' => now()->subDays(8)]);
        $this->assertSame(1, app(MpoBidding::class)->tellStale());
    }

    public function test_a_product_that_sold_lately_or_is_new_or_not_in_the_bulk_market_is_left_alone(): void
    {
        $this->listing->update(['approved_at' => now()->subDays(2)]);
        $this->assertSame(0, app(MpoBidding::class)->tellStale());

        $this->listing->update(['approved_at' => now()->subDays(20)]);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        Preorder::create(['customer_id' => $buyer->id, 'status' => 'accepted', 'promised_days' => 3, 'mpo_listing_id' => $this->listing->id, 'box_price' => 270]);
        $this->assertSame(0, app(MpoBidding::class)->tellStale());

        Preorder::query()->delete();
        $this->listing->update(['markets' => ['public']]);
        $this->assertSame(0, app(MpoBidding::class)->tellStale());
    }

    public function test_the_mpo_can_only_raise_the_percentage_never_lower_it_and_only_for_bulk(): void
    {
        $this->actingAs($this->mpo);   // the listed 240 is 20% off 300

        $this->post("/mpo/listings/{$this->listing->id}/raise", ['percent' => 15])->assertSessionHasErrors('listing');
        $this->post("/mpo/listings/{$this->listing->id}/raise", ['percent' => 20])->assertSessionHasErrors('listing');
        $this->post("/mpo/listings/{$this->listing->id}/raise", ['percent' => 95])->assertSessionHasErrors('percent');

        $this->post("/mpo/listings/{$this->listing->id}/raise", ['percent' => 25])->assertSessionHasNoErrors();
        $this->assertSame('225.00', (string) $this->listing->fresh()->price_wholesale);
        $this->assertSame('270.00', (string) $this->listing->fresh()->price_public);   // the other markets stay the owner's to set
    }

    public function test_another_mpo_cannot_raise_someone_elses_product(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Mpo, 'mpo_number' => 2]);

        $this->actingAs($other)->post("/mpo/listings/{$this->listing->id}/raise", ['percent' => 30])->assertSessionHasErrors('listing');
        $this->assertSame('240.00', (string) $this->listing->fresh()->price_wholesale);
    }

    public function test_an_order_that_did_not_arrive_in_time_is_cancelled_the_buyer_told_and_the_failure_counted(): void
    {
        $buyer = $this->wholesaler('Rahim');
        $this->bid($buyer, 10, 20);
        $this->actingAs($this->mpo)->post('/mpo/bids/'.MpoBid::firstOrFail()->id.'/accept');
        $pre = Preorder::firstOrFail();

        $pre->update(['due_on' => today()->subDay()]);   // late, but inside the grace days
        $this->assertSame(0, app(MpoBidding::class)->expireOrders());

        $pre->update(['due_on' => today()->subDays(3)]);
        $this->assertSame(1, app(MpoBidding::class)->expireOrders());

        $this->assertSame('cancelled', $pre->fresh()->status);
        $this->assertSame(1, $this->mpo->fresh()->mpo_failed);
        $this->assertContains('preorder_cancelled', Notice::where('user_id', $buyer->id)->pluck('kind')->all());
        $this->actingAs($this->admin)->get("/admin/mpo/{$this->mpo->id}")->assertInertia(fn (Assert $p) => $p->where('mpo.failed', 1));
        $this->actingAs($this->mpo->fresh())->get('/mpo')->assertInertia(fn (Assert $p) => $p->where('failed', 1));
    }

    public function test_the_grace_days_are_a_setting_and_a_received_order_is_never_cancelled(): void
    {
        $buyer = $this->wholesaler('Rahim');
        $this->bid($buyer);
        $this->actingAs($this->mpo)->post('/mpo/bids/'.MpoBid::firstOrFail()->id.'/accept');
        $pre = Preorder::firstOrFail();
        $pre->update(['due_on' => today()->subDays(3)]);

        Setting::write('mpo.grace_days', 10);
        $this->assertSame(0, app(MpoBidding::class)->expireOrders());

        Setting::write('mpo.grace_days', 0);
        $pre->update(['status' => 'fulfilled']);
        $this->assertSame(0, app(MpoBidding::class)->expireOrders());
        $this->assertSame(0, $this->mpo->fresh()->mpo_failed);
    }

    public function test_an_ordinary_pre_order_that_is_late_is_not_touched_by_the_mpo_rule(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        Preorder::create(['customer_id' => $buyer->id, 'status' => 'accepted', 'promised_days' => 3, 'due_on' => today()->subDays(30)]);

        $this->assertSame(0, app(MpoBidding::class)->expireOrders());
    }
}
