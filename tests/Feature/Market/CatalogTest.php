<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13: a central catalog, one product with many sellers, and the order that is not the lowest price alone. */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reviewer;

    private User $a;

    private User $b;

    private User $c;

    private User $buyer;

    private Sector $phones;

    private CatalogProduct $a15;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $mk = fn (string $n, bool $show = false) => User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['phones'], 'business_name' => $n, 'show_shop_name' => $show])->fresh();
        $this->a = $mk('Alpha Mobile', true);
        $this->b = $mk('Beta Mobile');
        $this->c = $mk('Gamma Mobile');
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->phones = Sector::create(['slug' => 'phones', 'name' => 'Phones', 'unit' => 'piece', 'allow_fraction' => false, 'fields' => ['warranty']]);
        $this->a15 = CatalogProduct::create(['sector_id' => $this->phones->id, 'name' => 'Samsung A15', 'name_bn' => 'স্যামসাং এ১৫', 'status' => 'approved']);
    }

    private function listing(User $seller, float $price, float $delivery = 0, ?CatalogProduct $catalog = null, array $more = []): Listing
    {
        return Listing::create($more + [
            'seller_id' => $seller->id, 'sector_id' => $this->phones->id, 'catalog_product_id' => ($catalog ?? $this->a15)->id, 'title' => 'Samsung A15 by '.$seller->business_name,
            'public_price' => $price, 'min_qty' => 1, 'delivery_charge' => $delivery, 'stock' => 5, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 0,
            'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ the catalog

    public function test_the_owner_adds_a_product_once_whatever_the_case(): void
    {
        $this->actingAs($this->reviewer)->post('/admin/catalog', ['sector_id' => $this->phones->id, 'name' => 'Redmi 13'])->assertSessionHasNoErrors();
        $this->actingAs($this->reviewer)->post('/admin/catalog', ['sector_id' => $this->phones->id, 'name' => ' redmi   13 '])->assertSessionHasErrors('name');

        $this->assertSame(1, CatalogProduct::where('name', 'Redmi 13')->count());
    }

    public function test_a_seller_picks_from_the_catalog_where_the_sector_asks_for_it(): void
    {
        $this->phones->update(['catalog_required' => true]);
        $base = ['sector_id' => $this->phones->id, 'title' => 'Samsung A15', 'public_price' => 4000, 'min_qty' => 1, 'delivery_charge' => 0, 'stock' => 3];

        $this->actingAs($this->b)->post('/wholesale/sell', $base)->assertSessionHasErrors('listing');
        $this->assertSame(0, Listing::count());

        $this->actingAs($this->b)->post('/wholesale/sell', $base + ['catalog_product_id' => $this->a15->id])->assertSessionHasNoErrors();
        $this->assertSame($this->a15->id, Listing::firstOrFail()->catalog_product_id);
    }

    public function test_a_catalog_product_of_another_sector_or_not_approved_is_refused(): void
    {
        $this->phones->update(['catalog_required' => true]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece']);
        $other = CatalogProduct::create(['sector_id' => $wood->id, 'name' => 'Chair', 'status' => 'approved']);
        $waiting = CatalogProduct::create(['sector_id' => $this->phones->id, 'name' => 'Waiting phone', 'status' => 'pending']);
        $base = ['sector_id' => $this->phones->id, 'title' => 'Some phone', 'public_price' => 4000, 'min_qty' => 1, 'delivery_charge' => 0, 'stock' => 3];

        $this->actingAs($this->b)->post('/wholesale/sell', $base + ['catalog_product_id' => $other->id])->assertSessionHasErrors('listing');
        $this->actingAs($this->b)->post('/wholesale/sell', $base + ['catalog_product_id' => $waiting->id])->assertSessionHasErrors('listing');
        $this->assertSame(0, Listing::count());
    }

    public function test_where_it_is_not_required_the_catalog_is_still_optional(): void
    {
        $this->actingAs($this->b)->post('/wholesale/sell', ['sector_id' => $this->phones->id, 'title' => 'Plain', 'public_price' => 500, 'min_qty' => 1, 'delivery_charge' => 0, 'stock' => 3])->assertSessionHasNoErrors();

        $this->assertNull(Listing::firstOrFail()->catalog_product_id);
    }

    public function test_a_product_that_is_not_there_is_asked_for_and_waits_for_us(): void
    {
        $this->actingAs($this->b)->post('/wholesale/sell/catalog-request', ['sector_id' => $this->phones->id, 'name' => 'Nokia G42'])->assertSessionHasNoErrors();

        $p = CatalogProduct::where('name', 'Nokia G42')->firstOrFail();
        $this->assertSame('pending', $p->status);
        $this->assertSame($this->b->id, $p->requested_by);
        $this->assertGreaterThan(0, Notice::where('kind', 'catalog_new')->count());
        $this->assertSame(0, app(Catalog::class)->choices($this->phones, 'nokia')->count(), 'not offered to anyone until we add it');

        $this->actingAs($this->b)->post('/wholesale/sell/catalog-request', ['sector_id' => $this->phones->id, 'name' => 'samsung a15'])->assertSessionHasErrors('catalog');
    }

    public function test_we_add_or_turn_down_a_request_and_the_seller_is_told(): void
    {
        $this->actingAs($this->b)->post('/wholesale/sell/catalog-request', ['sector_id' => $this->phones->id, 'name' => 'Nokia G42']);
        $yes = CatalogProduct::firstWhere('name', 'Nokia G42');
        $this->actingAs($this->b)->post('/wholesale/sell/catalog-request', ['sector_id' => $this->phones->id, 'name' => 'Fake phone']);
        $no = CatalogProduct::firstWhere('name', 'Fake phone');

        $this->actingAs($this->reviewer)->post("/admin/catalog/{$yes->id}/approve", ['name' => 'Nokia G42 5G'])->assertSessionHasNoErrors();
        $this->actingAs($this->reviewer)->post("/admin/catalog/{$no->id}/reject", ['note' => 'Not a real product'])->assertSessionHasNoErrors();

        $this->assertSame('approved', $yes->fresh()->status);
        $this->assertSame('Nokia G42 5G', $yes->fresh()->name);
        $this->assertSame('rejected', $no->fresh()->status);
        $this->assertSame(2, Notice::where('user_id', $this->b->id)->where('kind', 'catalog_decided')->count());
        $this->actingAs($this->reviewer)->post("/admin/catalog/{$yes->id}/approve")->assertSessionHasErrors('name');
    }

    public function test_the_search_finds_a_product_by_its_bangla_spelling(): void
    {
        $this->assertSame(1, app(Catalog::class)->choices($this->phones, 'স্যামসাং')->count());
        $this->assertSame(1, app(Catalog::class)->choices($this->phones, 'a15')->count());
        $this->assertSame(0, app(Catalog::class)->choices($this->phones, 'iphone')->count());
    }

    public function test_only_staff_who_manage_customers_use_the_catalog_pages(): void
    {
        $this->actingAs($this->buyer)->get('/admin/catalog')->assertRedirect('/');
        $this->actingAs($this->b)->post('/admin/catalog', ['sector_id' => $this->phones->id, 'name' => 'Sneaky'])->assertRedirect('/');
        $this->actingAs($this->reviewer)->get('/admin/catalog')->assertOk();
    }

    // ------------------------------------------------------------------ one product, many sellers

    public function test_the_page_shows_every_sellers_price_the_best_first(): void
    {
        $l1 = $this->listing($this->a, 20000, 0);
        $this->listing($this->b, 19000, 0);
        $this->listing($this->c, 21000, 0);

        $this->get("/market/{$l1->id}")->assertInertia(fn (Assert $p) => $p->has('offers', 3)
            ->where('offers.0.price', 19000)->where('offers.0.best', true)->where('offers.1.price', 20000)->where('offers.1.this', true)->where('offers.2.price', 21000));
    }

    public function test_a_low_price_with_a_high_delivery_charge_does_not_win(): void
    {
        $l = $this->listing($this->a, 19000, 3000);   // 22000 together
        $this->listing($this->b, 20000, 500);          // 20500 together

        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('offers.0.price', 20000)->where('offers.0.best', true)->where('offers.1.price', 19000));
    }

    public function test_a_poor_record_makes_a_seller_dearer(): void
    {
        $svc = app(Catalog::class);

        $this->assertSame(20000.0, $svc->cost(20000, 0, 100));
        $this->assertSame(21000.0, $svc->cost(20000, 0, 80), 'a fifth short of full marks adds a twentieth');
        $this->assertSame(25000.0, $svc->cost(20000, 0, 0), 'at the worst a quarter more');
        $this->assertSame(25000.0, $svc->cost(20000, 0, -50), 'the score never goes below zero');
    }

    public function test_names_show_only_for_shops_with_a_page_and_the_rest_are_numbered(): void
    {
        $l = $this->listing($this->a, 19000);
        $this->listing($this->b, 20000);

        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('offers.0.name', 'Alpha Mobile')->where('offers.1.name', fn ($n) => ! str_contains($n, 'Beta') && ! str_contains($n, 'Mobile')));
    }

    public function test_a_single_seller_shows_no_comparison(): void
    {
        $l = $this->listing($this->a, 19000);

        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('offers', []));
    }

    public function test_a_listing_not_in_the_catalog_shows_no_comparison(): void
    {
        $l = $this->listing($this->a, 19000, 0, null, ['catalog_product_id' => null]);

        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('offers', []));
    }

    public function test_sellers_a_viewer_cannot_see_are_not_compared(): void
    {
        $l = $this->listing($this->a, 19000);
        $this->listing($this->b, 20000, 0, null, ['status' => 'paused']);
        $this->listing($this->c, 21000);

        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->has('offers', 2));
    }

    public function test_the_list_shows_one_card_for_the_product_with_how_many_sellers(): void
    {
        $this->listing($this->a, 20000);
        $this->listing($this->b, 19000);
        $this->listing($this->c, 21000);
        $this->listing($this->a, 500, 0, null, ['catalog_product_id' => null, 'title' => 'Phone case']);

        $this->get('/market?sort=low')->assertInertia(fn (Assert $p) => $p->has('items', 2)->where('items.0.title', 'Phone case')->where('items.1.price', 19000)->where('items.1.sellers', 3));
    }

    public function test_the_seller_form_gets_the_catalog_and_what_they_asked_for(): void
    {
        $this->actingAs($this->b)->post('/wholesale/sell/catalog-request', ['sector_id' => $this->phones->id, 'name' => 'Nokia G42']);

        $this->actingAs($this->b)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('sectors.0.catalog.0.name', 'Samsung A15')->where('asked.0.name', 'Nokia G42')->where('asked.0.status', 'pending'));
    }

    public function test_changing_the_catalog_product_sends_the_listing_back_for_review(): void
    {
        $other = CatalogProduct::create(['sector_id' => $this->phones->id, 'name' => 'Samsung A16', 'status' => 'approved']);
        $l = $this->listing($this->b, 4000);

        $this->actingAs($this->b)->post("/wholesale/sell/{$l->id}", ['catalog_product_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame('pending', $l->fresh()->status);

        $l2 = $this->listing($this->c, 4000);
        $this->actingAs($this->c)->post("/wholesale/sell/{$l2->id}", ['catalog_product_id' => $this->a15->id, 'public_price' => 4500])->assertSessionHasNoErrors();
        $this->assertSame('published', $l2->fresh()->status, 'the same product again is not news');
    }
}
