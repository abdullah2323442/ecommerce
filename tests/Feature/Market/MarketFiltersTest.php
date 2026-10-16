<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\ListingReview;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 13: filter by rating, sort by popularity (besides price, category and newest). */
class MarketFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Sector $wood;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'seller_trusted_at' => now()]);
        $this->buyer = User::factory()->create();
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);
    }

    private function listing(string $title): Listing
    {
        return Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => $title, 'public_price' => 1000, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
    }

    private function order(Listing $l, string $status = 'delivered'): MarketOrder
    {
        return MarketOrder::create([
            'public_token' => str()->random(40), 'listing_id' => $l->id, 'seller_id' => $this->seller->id, 'buyer_id' => $this->buyer->id, 'title' => $l->title, 'unit' => 'foot',
            'quantity' => 1, 'unit_price' => 1020, 'seller_price' => 1000, 'commission' => 20, 'delivery_charge' => 0, 'total' => 1020, 'audience' => 'public',
            'ship_to' => ['name' => 'X', 'phone' => '01711000000', 'address' => 'Y'], 'status' => $status,
        ]);
    }

    private function titles(string $query): array
    {
        $titles = [];
        $this->get('/market?'.$query)->assertInertia(function ($p) use (&$titles) {
            $titles = collect($p->toArray()['props']['items'])->pluck('title')->all();
        });

        return $titles;
    }

    public function test_the_rating_filter_keeps_only_well_reviewed_products(): void
    {
        $good = $this->listing('Good chair');
        $poor = $this->listing('Poor chair');
        $this->listing('Unreviewed chair');
        ListingReview::create(['listing_id' => $good->id, 'market_order_id' => $this->order($good)->id, 'buyer_id' => $this->buyer->id, 'rating' => 5]);
        ListingReview::create(['listing_id' => $poor->id, 'market_order_id' => $this->order($poor)->id, 'buyer_id' => $this->buyer->id, 'rating' => 2]);

        $this->assertSame(['Good chair'], $this->titles('stars=4'));
        $this->assertCount(1, $this->titles('stars=3'));
        $this->assertCount(3, $this->titles('stars=7'), 'a made-up value is ignored');
    }

    public function test_a_hidden_review_does_not_count(): void
    {
        $l = $this->listing('Hidden');
        ListingReview::create(['listing_id' => $l->id, 'market_order_id' => $this->order($l)->id, 'buyer_id' => $this->buyer->id, 'rating' => 5, 'hidden' => true]);

        $this->assertSame([], $this->titles('stars=4'));
    }

    public function test_most_ordered_goes_first_and_a_cancelled_order_does_not_count(): void
    {
        $a = $this->listing('A');
        $b = $this->listing('B');
        $c = $this->listing('C');
        $this->order($b);
        $this->order($b);
        $this->order($c);
        $this->order($a, 'cancelled');
        $this->order($a, 'cancelled');
        $this->order($a, 'cancelled');

        $this->assertSame(['B', 'C', 'A'], $this->titles('sort=popular'));
    }

    public function test_a_trusted_seller_wears_a_badge_and_a_new_one_does_not(): void
    {
        $this->listing('Chair');
        $this->get('/market')->assertInertia(fn ($p) => $p->where('items.0.trusted', true));   // marked by us in setUp

        $this->seller->forceFill(['seller_trusted_at' => null])->save();
        $this->get('/market')->assertInertia(fn ($p) => $p->where('items.0.trusted', false));

        $l = Listing::firstOrFail();
        $this->get("/market/{$l->id}")->assertInertia(fn ($p) => $p->where('listing.trusted', false)->missing('listing.seller_id'));
    }

    public function test_other_names_find_the_same_product(): void
    {
        $l = $this->listing('Tablet');
        $l->update(['alt_names' => 'bori, pill']);
        $this->listing('Chair');

        $this->assertSame(['Tablet'], $this->titles('q=bori'));
        $this->assertSame(['Tablet'], $this->titles('q=PILL'));
    }
}
