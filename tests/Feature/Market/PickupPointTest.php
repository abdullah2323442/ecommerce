<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\MarketOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 20: collect from the seller's own shop, to save the delivery charge; only where the seller's name and place are shown. */
class PickupPointTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $hidden;

    private User $buyer;

    private Listing $chair;

    private Listing $table;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'show_shop_name' => true])->fresh();
        $this->seller->forceFill(['shop_address' => 'Shop 4, Mirpur 10, beside the mosque'])->save();
        $this->hidden = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Karim Wood', 'show_shop_name' => false])->fresh();
        $this->hidden->forceFill(['shop_address' => 'Somewhere'])->save();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $make = fn (User $s, string $t, bool $pickup) => Listing::create([
            'seller_id' => $s->id, 'sector_id' => $wood->id, 'title' => $t, 'public_price' => 1000, 'min_qty' => 1, 'delivery_charge' => 120, 'stock' => 10, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(), 'pickup' => $pickup,
        ]);
        $this->chair = $make($this->seller, 'Teak chair', true);
        $this->table = $make($this->hidden, 'Teak table', true);
    }

    private function place(Listing $l, bool $pickup = true, array $ship = ['name' => 'Buyer', 'phone' => '01712345678']): MarketOrder
    {
        return app(MarketOrders::class)->place($l, $this->buyer, $ship, 2, null, false, null, null, $pickup);
    }

    public function test_the_page_offers_collecting_only_where_the_seller_shows_their_name_and_place(): void
    {
        $this->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->where('pickup.where', 'Shop 4, Mirpur 10, beside the mosque'));
        $this->get("/market/{$this->table->id}")->assertInertia(fn (Assert $p) => $p->where('pickup', null));

        $this->chair->update(['pickup' => false]);
        $this->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->where('pickup', null));

        $this->chair->update(['pickup' => true]);
        $this->seller->forceFill(['shop_address' => null])->save();
        $this->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->where('pickup', null), 'no place, no collecting');
    }

    public function test_a_collected_order_has_no_delivery_charge_and_needs_no_address(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/order", ['quantity' => 2, 'name' => 'Buyer', 'phone' => '01712345678', 'pickup' => 1])->assertSessionHasNoErrors();

        $o = MarketOrder::firstOrFail();
        $this->assertTrue($o->pickup);
        $this->assertSame('0.00', (string) $o->delivery_charge);
        $this->assertSame('2200.00', (string) $o->total, '2 at 1100 and nothing for delivery');
        $this->assertSame('8.00', (string) $this->chair->fresh()->stock);
    }

    public function test_sending_still_needs_an_address(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/order", ['quantity' => 1, 'name' => 'Buyer', 'phone' => '01712345678'])->assertSessionHasErrors(['district', 'area', 'address']);
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_collecting_is_refused_where_it_is_not_offered(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->table->id}/order", ['quantity' => 1, 'name' => 'Buyer', 'phone' => '01712345678', 'pickup' => 1])->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_the_buyer_is_told_where_to_come_when_the_seller_accepts(): void
    {
        $o = $this->place($this->chair);

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/accept")->assertSessionHasNoErrors();

        $body = json_decode(Notice::where('user_id', $this->buyer->id)->where('kind', 'market_pickup_ready')->firstOrFail()->body, true);
        $this->assertSame('Shop 4, Mirpur 10, beside the mosque', $body['where']);
        $this->actingAs($this->buyer)->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.pickup', true)->where('order.pickup_where', 'Shop 4, Mirpur 10, beside the mosque'));
    }

    public function test_the_place_is_not_given_before_the_seller_accepts(): void
    {
        $o = $this->place($this->chair);

        $this->actingAs($this->buyer)->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.pickup', true)->where('order.pickup_where', null));
    }

    public function test_the_seller_hands_it_over_and_the_sale_is_complete(): void
    {
        $o = $this->place($this->chair);
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/collected")->assertSessionHasErrors('order');
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/accept");

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/collected")->assertSessionHasNoErrors();

        $o->refresh();
        $this->assertSame('delivered', $o->status);
        $this->assertNotNull($o->delivered_at);
    }

    public function test_a_collected_order_cannot_be_marked_as_sent(): void
    {
        $o = $this->place($this->chair);

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/ship")->assertSessionHasErrors('order');
        $this->assertSame('placed', $o->fresh()->status);
    }

    public function test_a_sent_order_cannot_be_handed_over(): void
    {
        $o = $this->place($this->chair, false, ['name' => 'Buyer', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4']);
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/accept");

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/collected")->assertSessionHasErrors('order');
    }

    public function test_only_the_seller_of_the_order_hands_it_over(): void
    {
        $o = $this->place($this->chair);
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/accept");
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']])->fresh();

        $this->actingAs($other)->post("/wholesale/sell/orders/{$o->id}/collected")->assertSessionHasErrors('order');
        $this->assertSame('accepted', $o->fresh()->status);
    }

    public function test_the_seller_sees_it_is_collected_and_no_address(): void
    {
        $o = $this->place($this->chair);

        $row = app(MarketOrders::class)->forSeller($o);

        $this->assertTrue($row['pickup']);
        $this->assertStringContainsString('collect', mb_strtolower($row['address']));
        $this->assertSame('01712345678', $row['phone']);
    }

    // ------------------------------------------------------------------ nobody comes

    public function test_a_reminder_goes_after_three_days_and_only_once(): void
    {
        $o = $this->place($this->chair);
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/accept");

        Carbon::setTestNow('2026-10-16 10:00:00');
        $this->assertSame(['reminded' => 0, 'cancelled' => 0], app(MarketOrders::class)->sweepPickups());

        Carbon::setTestNow('2026-10-17 10:30:00');
        $this->assertSame(['reminded' => 1, 'cancelled' => 0], app(MarketOrders::class)->sweepPickups());
        $this->assertSame(['reminded' => 0, 'cancelled' => 0], app(MarketOrders::class)->sweepPickups());
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'market_pickup_reminder')->count());
        $this->assertSame('accepted', $o->fresh()->status);
    }

    public function test_still_not_collected_it_is_cancelled_and_the_goods_go_back(): void
    {
        $o = $this->place($this->chair);
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/accept");
        Carbon::setTestNow('2026-10-17 10:30:00');
        app(MarketOrders::class)->sweepPickups();

        Carbon::setTestNow('2026-10-20 11:00:00');
        $this->assertSame(['reminded' => 0, 'cancelled' => 1], app(MarketOrders::class)->sweepPickups());

        $o->refresh();
        $this->assertSame('cancelled', $o->status);
        $this->assertSame('system', $o->cancelled_by);
        $this->assertSame('10.00', (string) $this->chair->fresh()->stock);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'market_order_cancelled')->count());
    }

    public function test_an_order_that_was_collected_is_left_alone(): void
    {
        $o = $this->place($this->chair);
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/accept");
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$o->id}/collected");

        Carbon::setTestNow('2026-11-30 10:00:00');
        $this->assertSame(['reminded' => 0, 'cancelled' => 0], app(MarketOrders::class)->sweepPickups());
        $this->assertSame('delivered', $o->fresh()->status);
    }

    public function test_an_order_the_seller_has_not_accepted_is_not_swept(): void
    {
        $o = $this->place($this->chair);

        Carbon::setTestNow('2026-11-30 10:00:00');
        $this->assertSame(['reminded' => 0, 'cancelled' => 0], app(MarketOrders::class)->sweepPickups());
        $this->assertSame('placed', $o->fresh()->status, 'that is for the stale-order check, not this one');
    }

    // ------------------------------------------------------------------ the seller sets it up

    public function test_the_seller_writes_where_and_allows_it_on_a_product(): void
    {
        $this->actingAs($this->hidden)->put('/wholesale/shop-address', ['address' => 'New place 9'])->assertSessionHasNoErrors();
        $this->assertSame('New place 9', $this->hidden->fresh()->shop_address);

        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('shop.address', 'Shop 4, Mirpur 10, beside the mosque')->where('shop.name_shown', true)->where('listings.0.pickup', true));

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['pickup' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($this->chair->fresh()->pickup);
        $this->assertSame('published', $this->chair->fresh()->status, 'no new review for this');
    }

    public function test_a_buyer_cannot_set_a_shop_address(): void
    {
        $this->actingAs($this->buyer)->put('/wholesale/shop-address', ['address' => 'Mine'])->assertRedirect('/');
        $this->assertNull($this->buyer->fresh()->shop_address);
    }

    public function test_a_collected_order_keeps_the_seller_and_our_commission(): void
    {
        $o = $this->place($this->chair);

        $this->assertSame('200.00', (string) $o->commission, '2 at 100 each, as for any order');
        $this->assertEqualsWithDelta(2000.0, $o->sellerGets(), 0.001, 'the seller is paid their price, and no delivery charge');
    }

    public function test_the_order_screen_sends_pickup_as_a_json_true_and_that_needs_no_address(): void
    {
        // The product page posts JSON, so the choice arrives as true, not as the form value 1.
        $this->actingAs($this->buyer)->postJson("/market/{$this->chair->id}/order", ['quantity' => 2, 'name' => 'Buyer', 'phone' => '01712345678', 'pickup' => true])
            ->assertRedirect();

        $this->assertTrue(MarketOrder::latest('id')->firstOrFail()->pickup);

        // Not collecting: the address is still needed.
        $this->actingAs($this->buyer)->postJson("/market/{$this->chair->id}/order", ['quantity' => 2, 'name' => 'Buyer', 'phone' => '01712345678', 'pickup' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['district', 'area', 'address']);
    }
}
