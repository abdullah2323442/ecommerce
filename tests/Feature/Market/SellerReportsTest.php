<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\SellerReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14: reports for sellers (viewed but not bought, what earns, which month rose) and the supplier rank. */
class SellerReportsTest extends TestCase
{
    use RefreshDatabase;

    private Sector $wood;

    private SellerReports $reports;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);
        $this->reports = app(SellerReports::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seller(string $name = 'Rahim Timber'): User
    {
        return User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => $name])->fresh();
    }

    private function listing(User $seller, string $title = 'Teak chair', bool $photo = true): Listing
    {
        return Listing::create(['seller_id' => $seller->id, 'sector_id' => $this->wood->id, 'title' => $title, 'public_price' => 1000, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(), 'photo_path' => $photo ? 'market/x.jpg' : null]);
    }

    private function order(Listing $l, string $status = 'delivered', string $on = '2026-10-10 12:00:00', int $shipHours = 10): MarketOrder
    {
        $at = Carbon::parse($on);

        return MarketOrder::unguarded(fn () => MarketOrder::create([
            'public_token' => str()->random(40), 'listing_id' => $l->id, 'seller_id' => $l->seller_id, 'title' => $l->title, 'unit' => 'foot', 'quantity' => 2, 'unit_price' => 1020, 'seller_price' => 1000,
            'commission' => 40, 'delivery_charge' => 0, 'total' => 2040, 'audience' => 'public', 'ship_to' => ['name' => 'X', 'phone' => '01711000000', 'address' => 'Y'], 'status' => $status,
            'created_at' => $at, 'shipped_at' => $at->copy()->addHours($shipHours), 'delivered_at' => $status === 'delivered' ? $at->copy()->addDays(2) : null,
        ]));
    }

    private function look(Listing $l, int $people): void
    {
        for ($i = 0; $i < $people; $i++) {
            DB::table('behavior_events')->insert(['type' => 'listing_view', 'visitor' => 'v'.$i, 'subject_id' => $l->id, 'created_at' => now()->subDay()]);
        }
    }

    // ------------------------------------------------------------------ views

    public function test_opening_a_listing_counts_one_look_per_person_but_not_the_seller(): void
    {
        $seller = $this->seller();
        $l = $this->listing($seller);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($buyer)->get("/market/{$l->id}");
        $this->actingAs($buyer)->get("/market/{$l->id}");   // a reload is not interest
        $this->actingAs($seller)->get("/market/{$l->id}");

        $this->assertSame(1, DB::table('behavior_events')->where('type', 'listing_view')->where('subject_id', $l->id)->count());
    }

    public function test_the_medicine_report_is_not_polluted_by_listing_looks(): void
    {
        $l = $this->listing($this->seller());
        $this->look($l, 3);

        $this->assertSame(0, DB::table('behavior_events')->where('type', 'view')->count());
    }

    // ------------------------------------------------------------------ products

    public function test_looked_at_but_never_ordered_gets_a_hint_and_the_picture_comes_first(): void
    {
        $seller = $this->seller();
        $noPhoto = $this->listing($seller, 'Bare', photo: false);
        $priced = $this->listing($seller, 'Pricey');
        $few = $this->listing($seller, 'Quiet');
        $this->look($noPhoto, 12);
        $this->look($priced, 12);
        $this->look($few, 3);

        $by = collect($this->reports->products($seller))->keyBy('title');

        $this->assertSame(['photo', 'price', null], [$by['Bare']['hint'], $by['Pricey']['hint'], $by['Quiet']['hint']]);
        $this->assertSame(12, $by['Bare']['views']);
    }

    public function test_a_listing_that_sells_has_no_hint_and_the_best_earner_is_first(): void
    {
        $seller = $this->seller();
        $chair = $this->listing($seller, 'Chair');
        $stool = $this->listing($seller, 'Stool');
        $this->look($chair, 20);
        $this->order($chair);
        $this->order($chair);
        $this->order($stool);
        $this->order($stool, 'cancelled');

        $rows = $this->reports->products($seller);

        $this->assertSame(['Chair', 4000.0, null, 20], [$rows[0]['title'], $rows[0]['earned'], $rows[0]['hint'], $rows[0]['views']]);
        $this->assertSame(1, $rows[1]['orders']);   // a cancelled order is no order
    }

    public function test_only_the_sellers_own_numbers_are_in_it(): void
    {
        $mine = $this->seller();
        $other = $this->seller('Other');
        $this->order($this->listing($other, 'Theirs'));

        $this->assertSame([], $this->reports->products($mine));
    }

    // ------------------------------------------------------------------ months

    public function test_months_show_which_one_rose(): void
    {
        $seller = $this->seller();
        $l = $this->listing($seller);
        $this->order($l, 'delivered', '2026-08-10 12:00:00');                       // delivered Aug 12: 2000
        $this->order($l, 'delivered', '2026-09-10 12:00:00');
        $this->order($l, 'delivered', '2026-09-11 12:00:00');                       // Sep: 4000
        $months = collect($this->reports->months($seller))->keyBy('month');

        $this->assertCount(12, $this->reports->months($seller));
        $this->assertSame([2000.0, 4000.0, 100.0], [$months['2026-08']['sales'], $months['2026-09']['sales'], $months['2026-09']['change']]);
    }

    // ------------------------------------------------------------------ rank

    public function test_a_new_seller_is_in_the_middle_not_at_the_bottom(): void
    {
        $seller = $this->seller();
        $l = $this->listing($seller);
        $this->order($l);

        $rank = $this->reports->rankOf($seller);

        $this->assertSame([SellerReports::UNPROVEN_SCORE, false], [$rank['score'], $rank['proven']]);
        $this->assertSame('New', SellerReports::label($rank['score'], $rank['proven']));
        $this->assertSame(SellerReports::UNPROVEN_SCORE, $this->reports->rankOf($this->seller('Nobody'))['score']);
    }

    public function test_fast_with_no_returns_is_top(): void
    {
        $seller = $this->seller();
        $l = $this->listing($seller);
        foreach (range(1, 4) as $i) {
            $this->order($l, 'delivered', '2026-10-01 12:00:00', 8);
        }

        $rank = $this->reports->rankOf($seller);

        $this->assertSame([100, true, 8.0], [$rank['score'], $rank['proven'], $rank['ship_hours']]);
        $this->assertSame('Excellent', SellerReports::label(100, true));
    }

    public function test_returns_slowness_and_missing_goods_pull_the_rank_down(): void
    {
        $good = $this->seller('Good');
        $bad = $this->seller('Bad');
        $gl = $this->listing($good, 'G');
        $bl = $this->listing($bad, 'B');
        foreach (range(1, 4) as $i) {
            $this->order($gl, 'delivered', '2026-10-01 12:00:00', 10);
        }
        $this->order($bl, 'delivered', '2026-10-01 12:00:00', 200);
        $this->order($bl, 'returned', '2026-10-01 12:00:00', 200);
        $this->order($bl, 'not_received', '2026-10-01 12:00:00', 200);
        $this->order($bl, 'delivered', '2026-10-01 12:00:00', 200);

        $ranks = $this->reports->ranks();

        $this->assertGreaterThan($ranks[$bad->id]['score'], $ranks[$good->id]['score']);
        $this->assertLessThan(65, $ranks[$bad->id]['score']);
        $this->assertSame([25.0, 25.0], [$ranks[$bad->id]['return_rate'], $ranks[$bad->id]['missing_rate']]);
        $this->assertSame('Needs care', SellerReports::label($ranks[$bad->id]['score'], true));
    }

    public function test_orders_older_than_ninety_days_do_not_count(): void
    {
        $seller = $this->seller();
        $l = $this->listing($seller);
        foreach (range(1, 4) as $i) {
            $this->order($l, 'returned', '2026-05-01 12:00:00');
        }

        $this->assertFalse($this->reports->rankOf($seller)['proven']);
    }

    public function test_the_better_ranked_seller_is_shown_higher_on_the_market(): void
    {
        $good = $this->seller('Good');
        $slow = $this->seller('Slow');
        $gl = $this->listing($good, 'Good chair');
        $sl = $this->listing($slow, 'Slow chair');
        $sl->update(['approved_at' => now()->addDay()]);   // newer, so only the rank can put the other first
        foreach (range(1, 4) as $i) {
            $this->order($gl, 'delivered', '2026-10-01 12:00:00', 5);
            $this->order($sl, 'returned', '2026-10-01 12:00:00', 150);
        }

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('items.0.title', 'Good chair')->where('items.1.title', 'Slow chair'));
    }

    // ------------------------------------------------------------------ pages

    public function test_the_seller_opens_the_report_and_others_cannot(): void
    {
        $seller = $this->seller();
        $l = $this->listing($seller);
        $this->order($l);

        $this->actingAs($seller)->get('/wholesale/sell/reports')->assertInertia(fn (Assert $p) => $p->component('market/reports')
            ->has('products', 1)->has('months', 12)->where('rank.label', 'New')->where('proven', SellerReports::PROVEN));

        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/wholesale/sell/reports')->assertRedirect('/');
        $this->get('/wholesale/sell/reports')->assertRedirect();
    }

    public function test_two_businesses_of_one_person_are_shown_apart_and_never_mixed(): void
    {
        $seller = $this->seller();
        $clothing = Sector::create(['slug' => 'clothing', 'name' => 'Clothing', 'unit' => 'piece']);
        $chair = $this->listing($seller, 'Teak chair');
        $shirt = Listing::create(['seller_id' => $seller->id, 'sector_id' => $clothing->id, 'title' => 'Cotton shirt', 'public_price' => 500, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
        $this->order($chair);                       // 2 × 1000 = 2000
        $this->order($chair);                       // 4000 in all for wood
        $shirtOrder = $this->order($shirt);         // 2000 for clothing
        $shirtOrder->update(['seller_price' => 500]);


        $this->actingAs($seller)->get('/wholesale/sell/reports')->assertInertia(fn ($p) => $p
            ->has('businesses', 2)->where('business', null)->has('products', 2)->where('months.11.sales', 5000));

        $this->get('/wholesale/sell/reports?business=wood')->assertInertia(fn ($p) => $p
            ->where('business', 'wood')->has('products', 1)->where('products.0.title', 'Teak chair')->where('months.11.sales', 4000)->where('months.11.commission', 80));

        $this->get('/wholesale/sell/reports?business=clothing')->assertInertia(fn ($p) => $p
            ->has('products', 1)->where('products.0.title', 'Cotton shirt')->where('months.11.sales', 1000));

        $this->get('/wholesale/sell/reports?business=nonsense')->assertInertia(fn ($p) => $p->where('business', null)->has('products', 2));
    }

    public function test_a_seller_sees_their_own_business_name_on_their_screens_and_nobody_else_gets_it(): void
    {
        $seller = $this->seller('Rahim Timber');

        $this->actingAs($seller)->get('/wholesale/sell/reports')->assertInertia(fn ($p) => $p->where('sellerBrand', 'Rahim Timber'));

        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($buyer)->get('/market')->assertInertia(fn ($p) => $p->where('sellerBrand', null));
    }
}
