<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Bundle;
use App\Models\Listing;
use App\Models\ListingSale;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\Marketplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Flash sales and bundle deals (spec ch 27 extras): a seller lowers a price for a while, or for products bought together; the buyer sees what it was. */
class DealsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Sector $wood;

    private Listing $chair;

    private Listing $table;

    private Listing $lamp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'show_shop_name' => true, 'seller_trusted_at' => now()]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->chair = $this->make('Teak chair', 1000);
        $this->table = $this->make('Teak table', 3000);
        $this->lamp = $this->make('Desk lamp', 500);
    }

    private function make(string $title, float $price): Listing
    {
        return Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => $title, 'public_price' => $price, 'delivery_charge' => 100, 'stock' => 20, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(),
        ]);
    }

    private function ship(): array
    {
        return ['name' => 'Hasan', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    private function price(Listing $l, ?User $viewer = null): array
    {
        return app(Marketplace::class)->priceFor($l->fresh()->load(['sector', 'seller']), $viewer ?? $this->buyer);
    }

    // ------------------------------------------------------------------ flash sale

    public function test_a_seller_puts_a_product_on_sale_and_the_buyer_sees_the_old_price_and_the_end(): void
    {
        $this->assertSame(1020.0, $this->price($this->chair)['price']);

        $end = now()->addDays(2)->startOfMinute();
        $this->actingAs($this->seller)->post("/wholesale/sell/deals/sale/{$this->chair->id}", ['percent' => 20, 'ends_at' => $end->toDateTimeString()])->assertSessionHasNoErrors();

        $p = $this->price($this->chair);
        $this->assertSame(816.0, $p['price']); // 800 seller price plus 2 percent
        $this->assertSame(800.0, $p['seller_price']);
        $this->assertSame(1020.0, $p['was']);
        $this->assertSame(20, $p['sale_percent']);
        $this->assertSame($end->toIso8601String(), $p['sale_ends_at']);

        $this->actingAs($this->buyer)->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $page) => $page->where('listing.price', 816)->where('listing.was', 1020)->where('listing.sale_percent', 20));
        $this->assertTrue(ActivityLog::where('action', 'market.sale_started')->exists());
    }

    public function test_an_ended_sale_goes_back_to_the_usual_price_by_itself(): void
    {
        ListingSale::create(['listing_id' => $this->chair->id, 'percent' => 50, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subMinute()]);

        $p = $this->price($this->chair);
        $this->assertSame(1020.0, $p['price']);
        $this->assertNull($p['was']);
    }

    public function test_the_seller_can_end_a_sale_early(): void
    {
        ListingSale::create(['listing_id' => $this->chair->id, 'percent' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $this->actingAs($this->seller)->delete("/wholesale/sell/deals/sale/{$this->chair->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, ListingSale::count());
        $this->assertSame(1020.0, $this->price($this->chair)['price']);
    }

    public function test_the_sale_percent_and_the_length_have_limits(): void
    {
        $this->actingAs($this->seller);
        $ends = now()->addDay()->toDateTimeString();

        $this->post("/wholesale/sell/deals/sale/{$this->chair->id}", ['percent' => 2, 'ends_at' => $ends])->assertSessionHasErrors('sale');
        $this->post("/wholesale/sell/deals/sale/{$this->chair->id}", ['percent' => 90, 'ends_at' => $ends])->assertSessionHasErrors('sale');
        $this->post("/wholesale/sell/deals/sale/{$this->chair->id}", ['percent' => 20, 'ends_at' => now()->addDays(40)->toDateTimeString()])->assertSessionHasErrors('sale');
        $this->post("/wholesale/sell/deals/sale/{$this->chair->id}", ['percent' => 20, 'ends_at' => now()->subHour()->toDateTimeString()])->assertSessionHasErrors('sale');
        $this->assertSame(0, ListingSale::count());
    }

    public function test_nobody_can_put_another_sellers_product_on_sale(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'seller_trusted_at' => now()]);

        $this->actingAs($other)->post("/wholesale/sell/deals/sale/{$this->chair->id}", ['percent' => 20, 'ends_at' => now()->addDay()->toDateTimeString()])->assertForbidden();
        $this->actingAs($this->buyer)->post("/wholesale/sell/deals/sale/{$this->chair->id}", ['percent' => 20, 'ends_at' => now()->addDay()->toDateTimeString()])->assertRedirect(); // a plain buyer is not let into the trade section at all
        $this->assertSame(0, ListingSale::count());
    }

    public function test_a_sale_price_is_what_the_order_is_placed_at_and_the_commission_follows_it(): void
    {
        ListingSale::create(['listing_id' => $this->chair->id, 'percent' => 20, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);

        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/order", $this->ship() + ['quantity' => 2])->assertSessionHasNoErrors();

        $o = MarketOrder::firstOrFail();
        $this->assertSame(816.0, (float) $o->unit_price);
        $this->assertSame(800.0, (float) $o->seller_price);
        $this->assertSame(32.0, (float) $o->commission);
    }

    public function test_a_sale_is_for_the_public_price_and_does_not_touch_the_wholesale_price(): void
    {
        $this->chair->update(['wholesale_price' => 700, 'min_qty' => 5]);
        ListingSale::create(['listing_id' => $this->chair->id, 'percent' => 50, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $trade = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $p = $this->price($this->chair, $trade);
        $this->assertSame('wholesale', $p['audience']);
        $this->assertSame(714.0, $p['price']);
        $this->assertNull($p['was']);
    }

    // ------------------------------------------------------------------ bundles

    private function bundle(int $percent = 10, ?array $items = null): Bundle
    {
        $this->actingAs($this->seller)->post('/wholesale/sell/deals/bundles', ['title' => 'Dining set', 'percent' => $percent, 'listing_ids' => $items ?? [$this->chair->id, $this->table->id]])->assertSessionHasNoErrors();

        return Bundle::latest('id')->firstOrFail();
    }

    public function test_a_seller_makes_a_bundle_of_their_own_products(): void
    {
        $b = $this->bundle(10);

        $this->assertSame(2, $b->listings()->count());
        $this->assertTrue(ActivityLog::where('action', 'market.bundle_created')->exists());
    }

    public function test_a_bundle_needs_two_to_five_products_a_sensible_percent_and_a_name(): void
    {
        $this->actingAs($this->seller);

        $this->post('/wholesale/sell/deals/bundles', ['title' => 'One', 'percent' => 10, 'listing_ids' => [$this->chair->id]])->assertSessionHasErrors('bundle');
        $this->post('/wholesale/sell/deals/bundles', ['title' => 'Cheap', 'percent' => 1, 'listing_ids' => [$this->chair->id, $this->table->id]])->assertSessionHasErrors('bundle');
        $this->post('/wholesale/sell/deals/bundles', ['title' => 'Ab', 'percent' => 10, 'listing_ids' => [$this->chair->id, $this->table->id]])->assertSessionHasErrors('bundle');
        $this->assertSame(0, Bundle::count());
    }

    public function test_a_bundle_cannot_hold_someone_elses_product_or_one_with_colours(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'seller_trusted_at' => now()]);
        $theirs = Listing::create(['seller_id' => $other->id, 'sector_id' => $this->wood->id, 'title' => 'Their stool', 'public_price' => 200, 'stock' => 5, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
        $this->actingAs($this->seller);

        $this->post('/wholesale/sell/deals/bundles', ['title' => 'Mixed', 'percent' => 10, 'listing_ids' => [$this->chair->id, $theirs->id]])->assertForbidden();

        $this->chair->variants()->create(['color' => 'Brown', 'size' => 'L', 'public_price' => 1000, 'stock' => 3]);
        $this->post('/wholesale/sell/deals/bundles', ['title' => 'Mixed', 'percent' => 10, 'listing_ids' => [$this->chair->id, $this->table->id]])->assertSessionHasErrors('bundle');
        $this->assertSame(0, Bundle::count());
    }

    public function test_the_product_page_offers_the_bundle_with_what_it_saves(): void
    {
        $b = $this->bundle(10);

        $this->actingAs($this->buyer)->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p
            ->where('bundles.0.id', $b->id)->where('bundles.0.was', 4080)->where('bundles.0.save', 408)->where('bundles.0.now', 3672)->has('bundles.0.items', 2));
    }

    public function test_the_whole_bundle_goes_into_the_cart_and_the_saving_shows(): void
    {
        $b = $this->bundle(10);

        $this->actingAs($this->buyer)->post("/market-cart/bundle/{$b->id}")->assertSessionHasNoErrors();

        $cart = $this->get('/market-cart')->viewData('page')['props']['cart'];
        $this->assertSame(918.0, $cart['packages'][0]['lines'][0]['price']); // 1,020 less 10 percent
        $this->assertSame(2754.0, $cart['packages'][0]['lines'][1]['price']); // 3,060 less 10 percent
        $this->assertSame(3672.0, $cart['packages'][0]['goods']);
    }

    public function test_buying_part_of_a_bundle_gets_no_discount(): void
    {
        $this->bundle(10);

        $this->actingAs($this->buyer)->post('/market-cart', ['listing_id' => $this->chair->id, 'quantity' => 1])->assertSessionHasNoErrors();

        $cart = $this->get('/market-cart')->viewData('page')['props']['cart'];
        $this->assertSame(1020.0, $cart['packages'][0]['lines'][0]['price']);
    }

    public function test_the_discount_is_for_each_full_set_only_and_the_checkout_charges_exactly_what_the_cart_showed(): void
    {
        $this->bundle(10);
        $this->actingAs($this->buyer);
        // Three chairs but only two tables: two full sets, so two chairs and two tables are discounted and one chair is not.
        $this->post('/market-cart', ['listing_id' => $this->chair->id, 'quantity' => 3]);
        $this->post('/market-cart', ['listing_id' => $this->table->id, 'quantity' => 2]);

        $cart = $this->get('/market-cart')->viewData('page')['props']['cart'];
        $chairLine = collect($cart['packages'][0]['lines'])->firstWhere('id', $this->chair->id);
        $this->assertSame(round(3 * 1020 - 2 * 102, 2), $chairLine['total']);
        $this->assertSame(round(3 * 1020 - 2 * 102 + 2 * 3060 - 2 * 306, 2), $cart['packages'][0]['goods']);

        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta($cart['packages'][0]['goods'], (float) MarketOrder::sum(\DB::raw('unit_price * quantity')), 0.05);
        $this->assertSame(2, MarketOrder::count());
    }

    public function test_the_seller_carries_the_bundle_discount_and_our_commission_stays_in_taka(): void
    {
        $b = $this->bundle(10);
        $this->actingAs($this->buyer)->post("/market-cart/bundle/{$b->id}");

        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasNoErrors();

        $chair = MarketOrder::where('listing_id', $this->chair->id)->firstOrFail();
        $this->assertSame(918.0, (float) $chair->unit_price);
        $this->assertSame(20.0, (float) $chair->commission); // 2 percent of 1,000, as before
        $this->assertSame(898.0, (float) $chair->seller_price);
    }

    public function test_a_bundle_with_a_sold_out_or_hidden_item_is_not_offered(): void
    {
        $this->bundle(10);
        $this->table->update(['status' => 'paused']);

        $this->actingAs($this->buyer)->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->where('bundles', []));
    }

    public function test_the_seller_can_remove_a_bundle_and_the_discount_goes(): void
    {
        $b = $this->bundle(10);

        $this->actingAs($this->seller)->delete("/wholesale/sell/deals/bundles/{$b->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Bundle::count());
    }

    public function test_the_deals_page_lists_what_the_seller_has_running(): void
    {
        ListingSale::create(['listing_id' => $this->lamp->id, 'percent' => 15, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->bundle(12);

        $this->actingAs($this->seller)->get('/wholesale/sell/deals')->assertInertia(fn (Assert $p) => $p
            ->has('sales', 1)->where('sales.0.percent', 15)->has('bundles', 1)->where('bundles.0.percent', 12)->has('products', 3)->where('limits.days', 14));
    }

    public function test_a_guest_cannot_open_the_deals_page(): void
    {
        $this->get('/wholesale/sell/deals')->assertRedirect('/login');
    }
}
