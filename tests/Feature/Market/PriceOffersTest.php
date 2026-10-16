<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\PriceOffer;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\PriceOffers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13: "Why didn't you buy", a price the buyer names, the seller's answer, and a day to take it. */
class PriceOffersTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber'])->fresh();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => true]);
        // The seller wants 1000; we add 10 percent, so the buyer sees 1100.
        $this->chair = Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'wholesale_price' => 800, 'min_qty' => 1, 'delivery_charge' => 100,
            'stock' => 50, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    private function why(string $reason, ?float $price = null): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/why", ['reason' => $reason, 'price' => $price])->assertSessionHasNoErrors();
    }

    private function ship(): array
    {
        return ['name' => 'Buyer', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    public function test_a_reason_alone_is_kept_for_the_seller_and_asks_for_no_answer(): void
    {
        $this->why('delivery');

        $offer = PriceOffer::firstOrFail();
        $this->assertSame('noted', $offer->status);
        $this->assertSame(0, Notice::where('kind', 'price_offer_new')->count());

        $this->actingAs($this->seller)->get('/wholesale/sell/offers')->assertInertia(fn (Assert $p) => $p->where('reasons.delivery', 1)->where('reasons.price', 0)->has('offers', 0));
    }

    public function test_a_price_offer_reaches_the_seller_with_what_they_would_be_paid(): void
    {
        $this->why('price', 1000);

        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'price_offer_new')->count());
        $this->actingAs($this->seller)->get('/wholesale/sell/offers')->assertInertia(fn (Assert $p) => $p->has('offers', 1)->where('offers.0.offered', 1000)->where('offers.0.commission', 100)->where('offers.0.you_get', 900)->where('offers.0.now', 1100));
    }

    public function test_the_buyer_cannot_name_a_price_that_is_no_lower_or_too_low(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/why", ['reason' => 'price', 'price' => 1100])->assertSessionHasErrors('why');
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/why", ['reason' => 'price', 'price' => 90])->assertSessionHasErrors('why');
        $this->assertSame(0, PriceOffer::count());
    }

    public function test_one_answer_a_day_from_one_buyer_for_one_product(): void
    {
        $this->why('not_now');

        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/why", ['reason' => 'price', 'price' => 1000])->assertSessionHasErrors('why');

        Carbon::setTestNow('2026-10-15 11:00:00');
        $this->why('price', 1000);
        $this->assertSame(2, PriceOffer::count());
    }

    public function test_the_seller_agrees_and_the_buyer_orders_at_the_price_with_our_part_unchanged(): void
    {
        $this->why('price', 1000);
        $offer = PriceOffer::firstOrFail();

        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'accept'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $offer->fresh()->status);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'price_offer_accepted')->count());

        $this->actingAs($this->buyer)->post("/market-offers/{$offer->id}/order", $this->ship())->assertSessionHasNoErrors();

        $order = MarketOrder::firstOrFail();
        $this->assertSame('1000.00', (string) $order->unit_price);
        $this->assertSame('900.00', (string) $order->seller_price);
        $this->assertSame('100.00', (string) $order->commission, 'our commission stays the same in taka');
        $this->assertSame('ordered', $offer->fresh()->status);
        $this->assertSame($order->id, $offer->fresh()->market_order_id);
    }

    public function test_a_counter_price_must_be_between_the_offer_and_today_and_the_buyer_can_order_at_it(): void
    {
        $this->why('price', 1000);
        $offer = PriceOffer::firstOrFail();

        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'counter', 'price' => 1200])->assertSessionHasErrors('offer');
        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'counter', 'price' => 900])->assertSessionHasErrors('offer');
        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'counter', 'price' => 1050])->assertSessionHasNoErrors();

        $this->actingAs($this->buyer)->get('/market-offers')->assertInertia(fn (Assert $p) => $p->where('offers.0.status', 'countered')->where('offers.0.agreed', 1050));
        $this->actingAs($this->buyer)->post("/market-offers/{$offer->id}/order", $this->ship())->assertSessionHasNoErrors();
        $this->assertSame('1050.00', (string) MarketOrder::firstOrFail()->unit_price);
    }

    public function test_a_declined_offer_cannot_be_ordered(): void
    {
        $this->why('price', 1000);
        $offer = PriceOffer::firstOrFail();
        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'decline'])->assertSessionHasNoErrors();

        $this->actingAs($this->buyer)->post("/market-offers/{$offer->id}/order", $this->ship())->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());
        $this->assertSame(1, Notice::where('kind', 'price_offer_declined')->count());
    }

    public function test_an_offer_nobody_answers_is_cancelled_after_a_day_and_the_buyer_is_told(): void
    {
        $this->why('price', 1000);
        $offer = PriceOffer::firstOrFail();

        Carbon::setTestNow('2026-10-14 20:00:00');
        $this->assertSame(0, app(PriceOffers::class)->expire());

        Carbon::setTestNow('2026-10-15 10:30:00');
        $this->assertSame(1, app(PriceOffers::class)->expire());
        $this->assertSame('expired', $offer->fresh()->status);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'price_offer_expired')->count());
        $this->assertSame(0, app(PriceOffers::class)->expire(), 'told once');

        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'accept'])->assertSessionHasErrors('offer');
    }

    public function test_an_agreed_price_runs_out_too(): void
    {
        $this->why('price', 1000);
        $offer = PriceOffer::firstOrFail();
        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'accept']);

        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->actingAs($this->buyer)->post("/market-offers/{$offer->id}/order", $this->ship())->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_only_the_seller_of_the_product_can_answer_and_only_the_buyer_can_order(): void
    {
        $this->why('price', 1000);
        $offer = PriceOffer::firstOrFail();
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']])->fresh();

        $this->actingAs($other)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'accept'])->assertRedirect('/');
        $this->assertSame('waiting', $offer->fresh()->status);

        $this->actingAs($this->seller)->post("/wholesale/sell/offers/{$offer->id}", ['answer' => 'accept']);
        $stranger = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($stranger)->post("/market-offers/{$offer->id}/order", $this->ship())->assertRedirect('/');
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_the_buyer_can_withdraw_and_the_seller_cannot_see_who_they_are(): void
    {
        $this->why('price', 1000);
        $offer = PriceOffer::firstOrFail();

        $row = app(PriceOffers::class)->forSeller($this->seller)->first();
        $this->assertSame(['id', 'listing', 'quantity', 'offered', 'you_get', 'commission', 'now', 'expires_at', 'at'], array_keys($row));

        $this->actingAs($this->buyer)->post("/market-offers/{$offer->id}/withdraw")->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $offer->fresh()->status);
        $this->assertSame(0, app(PriceOffers::class)->forSeller($this->seller)->count());
    }

    public function test_a_guest_and_the_seller_cannot_make_an_offer(): void
    {
        $this->post("/market/{$this->chair->id}/why", ['reason' => 'price', 'price' => 1000])->assertRedirect();
        $this->actingAs($this->seller)->post("/market/{$this->chair->id}/why", ['reason' => 'price', 'price' => 1000])->assertSessionHasErrors('why');
        $this->assertSame(0, PriceOffer::count());
    }
}
