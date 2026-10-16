<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\ListingVariants;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 15: the owner opens a sector and chooses its fields; a seller sees and fills only those. */
class SectorFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['cosmetics', 'phones', 'wood'], 'business_name' => 'Rahim'])->fresh();
    }

    private function open(array $more = []): void
    {
        $this->actingAs($this->admin)->post('/admin/market/sectors', $more + [
            'slug' => 'cosmetics', 'name' => 'Cosmetics', 'unit' => 'piece', 'allow_fraction' => 0, 'preorder' => 0, 'chat' => 0, 'is_active' => 1, 'fields' => ['expiry', 'weight'],
        ])->assertSessionHasNoErrors();
    }

    private function sell(string $slug, array $more = [])
    {
        return $this->actingAs($this->seller)->post('/wholesale/sell', $more + ['sector_id' => Sector::where('slug', $slug)->value('id'), 'title' => 'A product', 'public_price' => 300, 'min_qty' => 1, 'delivery_charge' => 0, 'stock' => 5]);
    }

    public function test_the_owner_opens_a_sector_with_the_fields_it_needs(): void
    {
        $this->open(['custom_orders' => 1]);

        $s = Sector::where('slug', 'cosmetics')->firstOrFail();
        $this->assertSame(['weight', 'expiry'], $s->fieldList());
        $this->assertTrue($s->custom_orders);
    }

    public function test_only_known_fields_are_accepted(): void
    {
        $this->actingAs($this->admin)->post('/admin/market/sectors', ['slug' => 'toys', 'name' => 'Toys', 'unit' => 'piece', 'allow_fraction' => 0, 'preorder' => 0, 'chat' => 0, 'is_active' => 1, 'fields' => ['colour', 'x']])->assertSessionHasErrors('fields.0');
        $this->assertSame(0, Sector::count());
    }

    public function test_a_sector_from_before_keeps_colour_size_and_measurements(): void
    {
        $old = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);

        $this->assertSame(['color', 'size', 'measurements'], $old->fieldList());
        $this->assertFalse($old->uses('expiry'));
    }

    public function test_the_seller_form_gets_only_the_sectors_fields(): void
    {
        $this->open();

        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('sectors.0.fields', ['weight', 'expiry']));
    }

    public function test_where_expiry_is_asked_it_is_a_must_and_cannot_be_in_the_past(): void
    {
        $this->open();

        $this->sell('cosmetics')->assertSessionHasErrors('listing');
        $this->sell('cosmetics', ['expires_on' => '2026-10-01'])->assertSessionHasErrors('listing');
        $this->assertSame(0, Listing::count());

        $this->sell('cosmetics', ['expires_on' => '2027-06-30', 'item_weight' => 0.25])->assertSessionHasNoErrors();
        $this->assertSame('2027-06-30', Listing::firstOrFail()->expires_on->toDateString());
    }

    public function test_fields_the_sector_does_not_use_are_dropped(): void
    {
        $this->open();

        $this->sell('cosmetics', ['expires_on' => '2027-06-30', 'warranty_months' => 12, 'item_height' => 40, 'item_length' => 30])->assertSessionHasNoErrors();

        $l = Listing::firstOrFail();
        $this->assertNull($l->warranty_months);
        $this->assertNull($l->item_height);
        $this->assertNull($l->item_length);
    }

    public function test_a_sector_without_expiry_does_not_ask_for_it(): void
    {
        $this->open(['slug' => 'phones', 'name' => 'Phones', 'fields' => ['warranty']]);

        $this->sell('phones', ['warranty_months' => 12])->assertSessionHasNoErrors();

        $l = Listing::firstOrFail();
        $this->assertSame(12, $l->warranty_months);
        $this->assertNull($l->expires_on);
    }

    public function test_the_page_shows_weight_expiry_and_warranty(): void
    {
        $this->open(['fields' => ['weight', 'expiry', 'warranty']]);
        $this->sell('cosmetics', ['expires_on' => '2027-06-30', 'item_weight' => 0.25, 'warranty_months' => 6])->assertSessionHasNoErrors();
        $l = Listing::firstOrFail();
        $l->update(['status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now()]);

        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('listing.details', ['weight' => 0.25, 'expires_on' => '2027-06-30', 'warranty_months' => 6]));
    }

    public function test_a_product_past_its_date_is_not_sold_or_shown(): void
    {
        $this->open();
        $this->sell('cosmetics', ['expires_on' => '2026-10-20'])->assertSessionHasNoErrors();
        $l = Listing::firstOrFail();
        $l->update(['status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now()]);

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 1));

        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 0));
        $this->get("/market/{$l->id}")->assertRedirect('/');
    }

    public function test_a_sector_without_colours_or_sizes_takes_no_grid(): void
    {
        $this->open();
        $this->sell('cosmetics', ['expires_on' => '2027-06-30'])->assertSessionHasNoErrors();
        $l = Listing::firstOrFail();

        $this->expectException(StockException::class);
        app(ListingVariants::class)->sync($l, [['color' => 'Red', 'size' => 'M', 'stock' => 3]]);
    }

    public function test_a_sector_with_colours_takes_a_grid_and_an_empty_one_is_always_fine(): void
    {
        $this->open(['slug' => 'wood', 'name' => 'Wood', 'fields' => ['color', 'size']]);
        $this->sell('wood')->assertSessionHasNoErrors();
        $l = Listing::firstOrFail();

        app(ListingVariants::class)->sync($l, [['color' => 'Red', 'size' => 'M', 'stock' => 3]]);
        $this->assertSame(1, $l->variants()->count());

        app(ListingVariants::class)->sync($l, []);
        $this->assertSame(0, $l->variants()->count());
    }

    public function test_changing_a_listing_keeps_its_expiry_rule(): void
    {
        $this->open();
        $this->sell('cosmetics', ['expires_on' => '2027-06-30'])->assertSessionHasNoErrors();
        $l = Listing::firstOrFail();

        $this->actingAs($this->seller)->post("/wholesale/sell/{$l->id}", ['expires_on' => ''])->assertSessionHasErrors('listing');
        $this->actingAs($this->seller)->post("/wholesale/sell/{$l->id}", ['public_price' => 350])->assertSessionHasNoErrors();
        $this->assertSame('2027-06-30', $l->fresh()->expires_on->toDateString(), 'the date stays when it is not touched');
    }

    public function test_only_the_owner_opens_sectors(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);

        $this->actingAs($staff)->post('/admin/market/sectors', ['slug' => 'x', 'name' => 'X', 'unit' => 'piece', 'allow_fraction' => 0, 'preorder' => 0, 'chat' => 0, 'is_active' => 1])->assertRedirect('/');
        $this->assertSame(0, Sector::count());
    }

    public function test_a_seller_sets_the_search_title_and_description_and_the_page_uses_them(): void
    {
        $this->open(['fields' => ['weight']]);

        $this->sell('cosmetics', ['seo_title' => 'Rose cream for dry skin', 'seo_description' => 'Made in Bangladesh, 50 g jar'])->assertSessionHasNoErrors();
        $l = Listing::firstOrFail();
        $this->assertSame('Rose cream for dry skin', $l->seo_title);

        $l->update(['status' => 'published', 'approved_at' => now(), 'commission_kind' => 'percent', 'commission_value' => 2]);
        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('listing.seo_title', 'Rose cream for dry skin')->where('listing.seo_description', 'Made in Bangladesh, 50 g jar'));

        $this->sell('cosmetics', ['seo_title' => str_repeat('x', 71)])->assertSessionHasErrors('seo_title');
    }
}
