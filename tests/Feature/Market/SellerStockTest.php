<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\MarketOrders;
use App\Services\Market\SellerReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 15: one product in several categories with one stock; basic stock for sellers (best and slowest, own cost, low stock). */
class SellerStockTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Sector $wood;

    private Sector $home;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood', 'home'], 'business_name' => 'Rahim Timber'])->fresh();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->home = Sector::create(['slug' => 'home', 'name' => 'Home', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->chair = $this->listing('Teak chair', 1000, 10);
    }

    private function listing(string $title, float $price, float $stock, array $more = []): Listing
    {
        return Listing::create($more + [
            'seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => $title, 'public_price' => $price, 'min_qty' => 1, 'delivery_charge' => 0, 'stock' => $stock,
            'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    private function ship(): array
    {
        return ['name' => 'Buyer', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    // ------------------------------------------------------------------ many categories, one stock

    public function test_a_product_shows_in_each_of_its_categories_once_and_has_one_stock(): void
    {
        $this->chair->extraSectors()->sync([$this->home->id]);

        $this->get('/market?sector=wood')->assertInertia(fn (Assert $p) => $p->has('items', 1));
        $this->get('/market?sector=home')->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.title', 'Teak chair'));
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 1)->has('sectors', 2), 'one product, never twice');

        app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 4);
        $this->assertSame('6.00', (string) $this->chair->fresh()->stock);
    }

    public function test_a_product_not_in_a_category_is_not_listed_there(): void
    {
        $this->get('/market?sector=home')->assertInertia(fn (Assert $p) => $p->has('items', 0));
    }

    public function test_the_seller_chooses_other_categories_and_it_is_read_again(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['extra_sector_ids' => (string) $this->home->id])->assertSessionHasNoErrors();

        $this->assertSame([$this->home->id], $this->chair->extraSectors()->pluck('sectors.id')->all());
        $this->assertSame('pending', $this->chair->fresh()->status, 'a new category is checked like a new listing');

        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('listings.0.extra_sector_ids', [$this->home->id]));
    }

    public function test_the_same_categories_again_do_not_send_it_back_for_review(): void
    {
        $this->chair->extraSectors()->sync([$this->home->id]);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['extra_sector_ids' => (string) $this->home->id])->assertSessionHasNoErrors();

        $this->assertSame('published', $this->chair->fresh()->status);
    }

    public function test_none_clears_the_other_categories(): void
    {
        $this->chair->extraSectors()->sync([$this->home->id]);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['extra_sector_ids' => ''])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->chair->extraSectors()->count());
    }

    public function test_a_seller_cannot_add_a_category_they_do_not_have(): void
    {
        $other = Sector::create(['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece']);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['extra_sector_ids' => (string) $other->id])->assertSessionHasErrors('listing');
        $this->assertSame(0, $this->chair->extraSectors()->count());
    }

    public function test_the_main_category_is_never_kept_as_an_extra(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['extra_sector_ids' => $this->wood->id.','.$this->home->id])->assertSessionHasNoErrors();

        $this->assertSame([$this->home->id], $this->chair->extraSectors()->pluck('sectors.id')->all());
    }

    // ------------------------------------------------------------------ the seller's own stock view

    public function test_best_and_slowest_sellers_cost_and_profit(): void
    {
        $this->chair->update(['cost_price' => 600]);
        $lamp = $this->listing('Lamp', 200, 50);
        foreach ([[$this->chair, 3], [$lamp, 1]] as [$listing, $qty]) {
            $order = app(MarketOrders::class)->place($listing, $this->buyer, $this->ship(), $qty);
            $order->update(['status' => 'delivered', 'delivered_at' => now()]);
        }

        $rows = collect(app(SellerReports::class)->products($this->seller));
        $chair = $rows->firstWhere('title', 'Teak chair');

        $this->assertSame(3.0, $chair['sold']);
        $this->assertSame(3000.0, $chair['earned']);
        $this->assertSame(600.0, $chair['cost']);
        $this->assertSame(1200.0, $chair['profit'], '3000 less 3 at the 600 it cost');
        $this->assertNull($rows->firstWhere('title', 'Lamp')['profit'], 'no cost entered, no profit shown');
        $this->assertSame(7.0, $chair['left']);
    }

    public function test_the_own_cost_is_private(): void
    {
        $this->chair->update(['cost_price' => 600, 'low_stock_at' => 2]);

        $page = $this->get("/market/{$this->chair->id}")->viewData('page')['props'];

        $this->assertArrayNotHasKey('cost_price', $page['listing']);
        $this->assertArrayNotHasKey('low_stock_at', $page['listing']);
        $this->assertArrayNotHasKey('cost_price', $this->get('/market')->viewData('page')['props']['items'][0]);
    }

    public function test_the_seller_saves_their_cost_and_mark_and_sees_them(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}", ['cost_price' => 650, 'low_stock_at' => 3])->assertSessionHasNoErrors();

        $this->assertSame('published', $this->chair->fresh()->status, 'cost and marks are the seller\'s own business');
        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('listings.0.cost_price', 650)->where('listings.0.low_stock_at', 3));
    }

    public function test_the_seller_is_told_once_when_stock_falls_to_their_mark(): void
    {
        $this->chair->update(['low_stock_at' => 5]);

        app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 4); // 6 left
        $this->assertSame(0, Notice::where('kind', 'listing_low_stock')->count());

        app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 2); // 4 left: crossed
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'listing_low_stock')->count());

        app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1); // 3 left: already told
        $this->assertSame(1, Notice::where('kind', 'listing_low_stock')->count());
    }

    public function test_running_low_shows_in_the_report(): void
    {
        $this->chair->update(['low_stock_at' => 10]);

        $this->actingAs($this->seller)->get('/wholesale/sell/reports')->assertInertia(fn (Assert $p) => $p->where('products.0.low', true)->where('products.0.left', 10));
    }
}
