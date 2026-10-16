<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\ListingVariants;
use App\Services\Market\MarketOrders;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 15: one product with colours and sizes in one grid; sold out is per colour; a price and a photo per cell. */
class ListingVariantsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Listing $shirt;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        Storage::fake();
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['clothing'], 'business_name' => 'Rahim Wear'])->fresh();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $clothing = Sector::create(['slug' => 'clothing', 'name' => 'Clothing', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->shirt = Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $clothing->id, 'title' => 'Cotton shirt', 'public_price' => 500, 'min_qty' => 1, 'delivery_charge' => 60,
            'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    private function grid(array $cells): void
    {
        $this->actingAs($this->seller)->put("/wholesale/sell/{$this->shirt->id}/variants", ['cells' => $cells])->assertSessionHasNoErrors();
    }

    private function ship(): array
    {
        return ['name' => 'Buyer', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    private function cell(string $color, string $size): ListingVariant
    {
        return ListingVariant::where('listing_id', $this->shirt->id)->where('color', $color)->where('size', $size)->firstOrFail();
    }

    public function test_the_seller_fills_a_grid_and_changes_it_without_a_new_review(): void
    {
        $this->grid([
            ['color' => 'Red', 'size' => 'M', 'stock' => 5], ['color' => 'Red', 'size' => 'L', 'stock' => 0],
            ['color' => 'Blue', 'size' => 'M', 'stock' => 3, 'price' => 550], ['color' => 'Blue', 'size' => 'L', 'stock' => ''],
        ]);

        $this->assertSame(4, $this->shirt->variants()->count());
        $this->assertSame('published', $this->shirt->fresh()->status, 'stock and price are the seller\'s own business');
        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->has('listings.0.variants', 4)->where('listings.0.variants.2.price', 550));

        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 9]]);
        $this->assertSame(1, $this->shirt->variants()->count());
        $this->assertSame('9.00', (string) $this->cell('Red', 'M')->stock);
    }

    public function test_a_bad_grid_is_refused(): void
    {
        $put = fn (array $cells) => $this->actingAs($this->seller)->put("/wholesale/sell/{$this->shirt->id}/variants", ['cells' => $cells]);

        $put([['color' => '', 'size' => '', 'stock' => 1]])->assertSessionHasErrors('variants');
        $put([['color' => 'Red', 'size' => 'M'], ['color' => 'red', 'size' => 'm']])->assertSessionHasErrors('variants');
        $put([['color' => 'Red', 'size' => 'M', 'stock' => 1.5]])->assertSessionHasErrors('variants');
        $put([['color' => 'Red', 'size' => 'M', 'stock' => -1]])->assertSessionHasErrors('variants');
        $put([['color' => 'Red', 'size' => 'M', 'price' => 0]])->assertSessionHasErrors('variants');
        $this->assertSame(0, ListingVariant::count());
    }

    public function test_only_the_owner_of_the_product_changes_its_grid(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['clothing']])->fresh();

        $this->actingAs($other)->put("/wholesale/sell/{$this->shirt->id}/variants", ['cells' => [['color' => 'Red', 'size' => 'M', 'stock' => 1]]])->assertRedirect('/');
        $this->actingAs($this->buyer)->put("/wholesale/sell/{$this->shirt->id}/variants", ['cells' => []])->assertRedirect('/');
        $this->assertSame(0, ListingVariant::count());
    }

    public function test_only_the_colour_with_nothing_left_turns_grey_and_the_product_stays_on_sale(): void
    {
        $this->grid([
            ['color' => 'Red', 'size' => 'M', 'stock' => 0], ['color' => 'Red', 'size' => 'L', 'stock' => 0],
            ['color' => 'Blue', 'size' => 'M', 'stock' => 4], ['color' => 'Blue', 'size' => 'L', 'stock' => 0],
        ]);

        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p
            ->where('listing.in_stock', true)
            ->where('listing.grid.colors.0', ['name' => 'Red', 'sold_out' => true, 'photo' => null])
            ->where('listing.grid.colors.1.sold_out', false)
            ->where('listing.grid.cells.3.available', false));
    }

    public function test_when_every_cell_is_gone_the_product_is_sold_out(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 0]]);

        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p->where('listing.in_stock', false));
    }

    public function test_the_buyer_never_sees_stock_numbers(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 7]]);

        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p->where('listing.grid.cells.0', fn ($c) => ! isset($c['stock']) && ! isset($c['seller_price'])));
    }

    public function test_a_cell_has_its_own_price_or_the_products(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 5], ['color' => 'Red', 'size' => 'XL', 'stock' => 5, 'price' => 600]]);

        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p->where('listing.grid.cells.0.price', 550)->where('listing.grid.cells.1.price', 660));
    }

    public function test_an_order_takes_one_cell_at_its_price_and_stock(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'XL', 'stock' => 5, 'price' => 600], ['color' => 'Red', 'size' => 'M', 'stock' => 5]]);
        $xl = $this->cell('Red', 'XL');

        $this->actingAs($this->buyer)->post("/market/{$this->shirt->id}/order", $this->ship() + ['quantity' => 2, 'variant_id' => $xl->id])->assertSessionHasNoErrors();

        $order = MarketOrder::firstOrFail();
        $this->assertSame('660.00', (string) $order->unit_price);
        $this->assertSame('600.00', (string) $order->seller_price);
        $this->assertSame('Red XL', $order->variant_label);
        $this->assertSame('3.00', (string) $xl->fresh()->stock);
        $this->assertSame('5.00', (string) $this->cell('Red', 'M')->stock, 'the other cells are untouched');
    }

    public function test_a_product_with_a_grid_cannot_be_bought_without_a_cell_or_with_a_sold_out_one(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 1], ['color' => 'Blue', 'size' => 'M', 'stock' => 0]]);

        $this->actingAs($this->buyer)->post("/market/{$this->shirt->id}/order", $this->ship() + ['quantity' => 1])->assertSessionHasErrors('order');
        $this->actingAs($this->buyer)->post("/market/{$this->shirt->id}/order", $this->ship() + ['quantity' => 1, 'variant_id' => $this->cell('Blue', 'M')->id])->assertSessionHasErrors('order');
        $this->actingAs($this->buyer)->post("/market/{$this->shirt->id}/order", $this->ship() + ['quantity' => 2, 'variant_id' => $this->cell('Red', 'M')->id])->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_a_cell_of_another_product_cannot_be_used_on_a_plain_product(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 1]]);
        $other = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $this->shirt->sector_id, 'title' => 'Other', 'public_price' => 100, 'min_qty' => 1, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now()]);

        $this->actingAs($this->buyer)->post("/market/{$other->id}/order", $this->ship() + ['quantity' => 1, 'variant_id' => $this->cell('Red', 'M')->id])->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_a_cancelled_order_puts_the_cell_back(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 5]]);
        $order = app(MarketOrders::class)->place($this->shirt, $this->buyer, $this->ship(), 2, null, false, null, $this->cell('Red', 'M')->id);
        $this->assertSame('3.00', (string) $this->cell('Red', 'M')->stock);

        app(MarketOrders::class)->cancel($order, 'buyer');

        $this->assertSame('5.00', (string) $this->cell('Red', 'M')->stock);
    }

    public function test_the_seller_sees_which_cell_was_ordered_and_the_buyer_too(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 5]]);
        $order = app(MarketOrders::class)->place($this->shirt, $this->buyer, $this->ship(), 1, null, false, null, $this->cell('Red', 'M')->id);

        $this->assertSame('Red M', app(MarketOrders::class)->forSeller($order)['variant']);
        $this->actingAs($this->buyer)->get("/market/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.variant', 'Red M'));
    }

    public function test_choosing_a_colour_changes_the_photo(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 5], ['color' => 'Red', 'size' => 'L', 'stock' => 5], ['color' => 'Blue', 'size' => 'M', 'stock' => 5]]);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->shirt->id}/color-photo", ['color' => 'Red', 'photo' => UploadedFile::fake()->image('red.jpg', 400, 400)])->assertSessionHasNoErrors();

        $this->assertNotNull($this->cell('Red', 'M')->photo_path);
        $this->assertSame($this->cell('Red', 'M')->photo_path, $this->cell('Red', 'L')->photo_path, 'one photo for the whole colour');
        $this->assertNull($this->cell('Blue', 'M')->photo_path);

        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p->where('listing.grid.colors.0.photo', fn ($u) => str_contains($u, '/market/variant-photo/'))->where('listing.grid.colors.1.photo', null));

        $this->get(route('market.variant-photo', $this->cell('Red', 'M')))->assertOk();
        $this->get(route('market.variant-photo', $this->cell('Blue', 'M')))->assertRedirect('/');
    }

    public function test_the_photo_of_a_colour_not_in_the_grid_is_refused(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 5]]);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->shirt->id}/color-photo", ['color' => 'Green', 'photo' => UploadedFile::fake()->image('g.jpg')])->assertSessionHasErrors('variants');
    }

    public function test_a_hidden_product_does_not_leak_its_colour_photo(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 5]]);
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->shirt->id}/color-photo", ['color' => 'Red', 'photo' => UploadedFile::fake()->image('red.jpg')]);
        $this->shirt->update(['status' => 'paused']);
        auth()->logout();

        $this->get(route('market.variant-photo', $this->cell('Red', 'M')))->assertRedirect('/');
    }

    public function test_a_new_seller_cannot_put_a_dear_price_in_the_grid(): void
    {
        $new = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['clothing']])->fresh();
        $listing = Listing::create(['seller_id' => $new->id, 'sector_id' => $this->shirt->sector_id, 'title' => 'New', 'public_price' => 100, 'min_qty' => 1, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now()]);

        $this->expectException(StockException::class);
        app(ListingVariants::class)->sync($listing, [['color' => 'Red', 'size' => 'M', 'price' => 900000]]);
    }

    public function test_wood_sizes_are_kept_and_shown(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->shirt->id}", ['item_length' => 72, 'item_width' => 30, 'item_height' => 18, 'item_size_unit' => 'inch'])->assertSessionHasNoErrors();

        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p->where('listing.size', ['height' => 18, 'length' => 72, 'width' => 30, 'unit' => 'inch']));
    }

    public function test_a_product_without_a_size_shows_none(): void
    {
        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p->where('listing.size', null)->where('listing.grid', null));
    }

    public function test_a_price_offer_is_not_open_on_a_product_with_a_grid_yet(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 5]]);

        $this->actingAs($this->buyer)->post("/market/{$this->shirt->id}/why", ['reason' => 'price', 'price' => 300])->assertSessionHasErrors('why');
        $this->actingAs($this->buyer)->post("/market/{$this->shirt->id}/why", ['reason' => 'delivery'])->assertSessionHasNoErrors();
    }

    public function test_a_sold_out_colour_can_be_pre_ordered_only_where_the_sector_allows_it(): void
    {
        $this->grid([['color' => 'Red', 'size' => 'M', 'stock' => 0], ['color' => 'Blue', 'size' => 'M', 'stock' => 3]]);
        $red = $this->cell('Red', 'M');

        try {
            app(MarketOrders::class)->place($this->shirt, $this->buyer, $this->ship(), 1, null, false, null, $red->id);
            $this->fail('the sector does not take pre-orders');
        } catch (StockException $e) {
            $this->assertSame('There is not enough of this to fill that order.', $e->getMessage());
        }

        $this->shirt->sector->update(['preorder' => true]);
        $order = app(MarketOrders::class)->place($this->shirt->fresh(), $this->buyer, $this->ship(), 2, null, false, null, $red->id);

        $this->assertTrue($order->preorder);
        $this->assertSame('0.00', (string) $red->fresh()->stock, 'nothing was taken off the shelf');
        $this->assertFalse(app(MarketOrders::class)->place($this->shirt->fresh(), $this->buyer, $this->ship(), 1, null, false, null, $this->cell('Blue', 'M')->id)->preorder, 'what is in stock is an ordinary order');

        app(MarketOrders::class)->cancel($order, 'buyer');
        $this->assertSame('0.00', (string) $red->fresh()->stock, 'a cancelled pre-order puts nothing back');
        $this->assertSame('2.00', (string) $this->cell('Blue', 'M')->stock);
    }

    public function test_the_seller_names_the_pre_order_time_and_the_order_carries_it(): void
    {
        $this->shirt->sector->update(['preorder' => true]);
        $this->shirt->update(['preorder_days' => 3, 'stock' => 0]);

        $order = app(MarketOrders::class)->place($this->shirt->fresh(), $this->buyer, $this->ship(), 1);

        $this->assertTrue($order->preorder);
        $this->assertSame(3, $order->preorder_days);
        $this->actingAs($this->buyer)->get("/market/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.preorder_days', 3));
        $this->get("/market/{$this->shirt->id}")->assertInertia(fn (Assert $p) => $p->where('listing.preorder_days', 3));

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->shirt->id}", ['sector_id' => $this->shirt->sector_id, 'title' => 'Cotton shirt', 'public_price' => 500, 'preorder_days' => 7])->assertSessionHasErrors('preorder_days');
    }
}
