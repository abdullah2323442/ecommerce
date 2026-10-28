<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingSale;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13, personal recommendations: what each person likes, from their own orders and saved products and nobody else's. */
class RecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $seller;

    private Sector $wood;

    private Sector $tech;

    private Category $chairs;

    private Category $lamps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'seller_trusted_at' => now()]);
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->tech = Sector::create(['slug' => 'electronics', 'name' => 'Electronics', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->chairs = Category::create(['sector_id' => $this->wood->id, 'name' => 'Chairs', 'slug' => 'chairs']);
        $this->lamps = Category::create(['sector_id' => $this->tech->id, 'name' => 'Lamps', 'slug' => 'lamps']);
    }

    private function listing(string $title, Sector $sector, ?Category $category, array $more = []): Listing
    {
        return Listing::create($more + [
            'seller_id' => $this->seller->id, 'sector_id' => $sector->id, 'category_id' => $category?->id, 'title' => $title, 'public_price' => 1000, 'stock' => 10, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(),
        ]);
    }

    private function order(User $buyer, Listing $l, string $status = 'delivered'): MarketOrder
    {
        static $n = 0;
        $n++;

        return MarketOrder::create([
            'public_token' => str_pad((string) $n, 40, 'x'), 'seller_id' => $l->seller_id, 'buyer_id' => $buyer->id, 'listing_id' => $l->id, 'title' => $l->title, 'unit' => 'piece', 'quantity' => 1, 'unit_price' => 1020,
            'seller_price' => 1000, 'commission' => 20, 'audience' => 'public', 'ship_to' => [], 'delivery_charge' => 0, 'total' => 1020, 'status' => $status,
        ]);
    }

    private function picked(?User $who = null): array
    {
        $r = $this->actingAs($who ?? $this->buyer)->get('/market');

        return collect($r->viewData('page')['props']['picked'])->pluck('title')->all();
    }

    public function test_someone_with_no_history_is_shown_nothing(): void
    {
        $this->listing('Teak chair', $this->wood, $this->chairs);

        $this->assertSame([], $this->picked());
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('picked', []));
    }

    public function test_products_from_the_same_category_come_first_and_what_was_bought_is_left_out(): void
    {
        $bought = $this->listing('Teak chair', $this->wood, $this->chairs);
        $sameCategory = $this->listing('Oak chair', $this->wood, $this->chairs);
        $sameSector = $this->listing('Teak table', $this->wood, null);
        $this->listing('Desk lamp', $this->tech, $this->lamps, ['seller_id' => User::factory()->create(['account_type' => AccountType::Wholesaler])->id]);
        $this->order($this->buyer, $bought);

        $picked = $this->picked();

        $this->assertSame(['Oak chair', 'Teak table'], $picked);
        $this->assertNotContains('Teak chair', $picked);
        $this->assertNotContains('Desk lamp', $picked);
    }

    public function test_a_saved_product_counts_but_less_than_a_bought_one(): void
    {
        $saved = $this->listing('Desk lamp', $this->tech, $this->lamps);
        $bought = $this->listing('Teak chair', $this->wood, $this->chairs);
        $this->listing('Oak chair', $this->wood, $this->chairs);
        $this->listing('Floor lamp', $this->tech, $this->lamps);
        WishlistItem::create(['user_id' => $this->buyer->id, 'listing_id' => $saved->id, 'price_seen' => 1000]);
        $this->order($this->buyer, $bought);

        $picked = $this->picked();

        $this->assertSame('Oak chair', $picked[0]);
        $this->assertContains('Floor lamp', $picked);
    }

    public function test_a_cancelled_order_tells_nothing_about_taste(): void
    {
        $chair = $this->listing('Teak chair', $this->wood, $this->chairs);
        $this->listing('Oak chair', $this->wood, $this->chairs);
        $this->order($this->buyer, $chair, 'cancelled');

        $this->assertSame([], $this->picked());
    }

    public function test_it_uses_only_this_buyers_history_never_someone_elses(): void
    {
        $chair = $this->listing('Teak chair', $this->wood, $this->chairs);
        $this->listing('Oak chair', $this->wood, $this->chairs);
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $this->order($other, $chair);

        $this->assertSame([], $this->picked());
        $this->assertSame(['Oak chair'], $this->picked($other));
    }

    public function test_sold_out_hidden_and_own_products_are_never_picked(): void
    {
        $chair = $this->listing('Teak chair', $this->wood, $this->chairs);
        $this->listing('Sold out chair', $this->wood, $this->chairs, ['stock' => 0]);
        $this->listing('Paused chair', $this->wood, $this->chairs, ['status' => 'paused']);
        $mine = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);
        $this->listing('My own chair', $this->wood, $this->chairs, ['seller_id' => $mine->id]);
        $this->listing('Good chair', $this->wood, $this->chairs);
        $this->order($mine, $chair);

        $this->assertSame(['Good chair'], $this->picked($mine));
    }

    public function test_it_shows_only_on_the_plain_market_page_and_at_most_eight(): void
    {
        $chair = $this->listing('Teak chair', $this->wood, $this->chairs);
        foreach (range(1, 12) as $i) {
            $this->listing("Chair {$i}", $this->wood, $this->chairs);
        }
        $this->order($this->buyer, $chair);
        $this->actingAs($this->buyer);

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('picked', 8));
        $this->get('/market?sector=wood')->assertInertia(fn (Assert $p) => $p->where('picked', []));
        $this->get('/market?q=chair')->assertInertia(fn (Assert $p) => $p->where('picked', []));
    }

    public function test_a_card_carries_a_sale_price_like_any_other(): void
    {
        $chair = $this->listing('Teak chair', $this->wood, $this->chairs);
        $oak = $this->listing('Oak chair', $this->wood, $this->chairs);
        ListingSale::create(['listing_id' => $oak->id, 'percent' => 20, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay()]);
        $this->order($this->buyer, $chair);

        $card = $this->actingAs($this->buyer)->get('/market')->viewData('page')['props']['picked'][0];

        $this->assertSame(816, (int) $card['price']);
        $this->assertSame(1020, (int) $card['was']);
    }
}
