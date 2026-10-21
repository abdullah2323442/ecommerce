<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\StorefrontBlock;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The marketplace home at /market: every shop under one roof, built from sections the admin controls. */
class MarketHomeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        Storage::fake();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['electronics', 'wood']])->fresh();

        foreach ([['electronics', 'Electronics', [['Desk speaker', 3000], ['Smart watch', 8000]]], ['wood', 'Wood', [['Teak chair', 12000], ['Side table', 5000]]]] as [$slug, $name, $products]) {
            $sector = Sector::create(['slug' => $slug, 'name' => $name, 'unit' => 'piece', 'allow_fraction' => false, 'tagline' => $name.' tagline']);

            foreach ($products as [$title, $price]) {
                Listing::create([
                    'seller_id' => $seller->id, 'sector_id' => $sector->id, 'title' => $title, 'public_price' => $price, 'min_qty' => 1, 'stock' => 5,
                    'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
                ]);
            }
        }
    }

    private function blocks(string $url = '/market'): array
    {
        return $this->get($url)->viewData('page')['props']['storefront']['blocks'] ?? [];
    }

    public function test_without_sections_the_market_keeps_its_plain_list(): void
    {
        $props = $this->get('/market')->viewData('page')['props'];

        $this->assertNull($props['storefront']);
        $this->assertCount(4, $props['items']);
    }

    public function test_the_standard_sections_fill_the_market_once(): void
    {
        StorefrontBlock::seedPage('market');
        StorefrontBlock::seedPage('market');

        $this->assertSame(22, StorefrontBlock::where('page', 'market')->count());
    }

    public function test_the_home_shows_every_shop_as_a_department_and_products_across_them(): void
    {
        StorefrontBlock::seedPage('market');
        $blocks = collect($this->blocks());

        $this->assertSame('showcase', $blocks[0]['kind']);
        $this->assertEqualsCanonicalizing(['electronics', 'wood'], array_column($blocks->firstWhere('kind', 'departments')['departments'], 'slug'));

        // A product shows once on the page, even though two shelves would both reach for it.
        $titles = $blocks->where('kind', 'products')->flatMap(fn ($b) => array_column($b['products'], 'title'));
        $this->assertSame($titles->unique()->count(), $titles->count());
    }

    public function test_a_shelf_can_be_filled_from_one_shop(): void
    {
        StorefrontBlock::seedPage('market');
        $shelf = StorefrontBlock::where('page', 'market')->where('kind', 'products')->first();

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => 'sector:wood'])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['Teak chair', 'Side table'], array_column(collect($this->blocks())->firstWhere('id', $shelf->id)['products'], 'title'));

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => 'sector:nope'])->assertSessionHasErrors('source');
    }

    public function test_the_buyer_words_come_from_the_testimonials(): void
    {
        StorefrontBlock::seedPage('market');
        Testimonial::create(['name' => 'Nusrat', 'place' => 'Dhaka', 'quote' => 'Lovely teak, arrived on time.', 'quote_bn' => null, 'rating' => 5, 'is_active' => true, 'sort' => 1]);
        Testimonial::create(['name' => 'Hidden', 'place' => null, 'quote' => 'Not shown.', 'quote_bn' => null, 'rating' => 4, 'is_active' => false, 'sort' => 2]);

        $reviews = collect($this->blocks())->firstWhere('kind', 'reviews')['reviews'];

        $this->assertSame(['Nusrat'], array_column($reviews, 'name'));
    }

    public function test_the_all_products_view_is_not_the_home(): void
    {
        StorefrontBlock::seedPage('market');

        $this->get('/market?all=1')->assertInertia(fn ($p) => $p->where('storefront', null)->has('items', 4));
        $this->get('/market?sector=wood')->assertInertia(fn ($p) => $p->where('storefront', null));
    }

    public function test_a_hidden_section_is_not_on_the_home(): void
    {
        StorefrontBlock::seedPage('market');
        $faq = StorefrontBlock::where('page', 'market')->where('kind', 'faq')->first();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$faq->id}", ['is_active' => false]);

        $this->assertNotContains('faq', array_column($this->blocks(), 'kind'));
    }

    public function test_a_link_must_stay_on_this_site(): void
    {
        StorefrontBlock::seedPage('market');
        $promos = StorefrontBlock::where('page', 'market')->where('kind', 'promos')->first();
        $item = fn ($link) => ['items' => [['title' => 'Gift', 'text' => 'x', 'link' => $link]]];

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$promos->id}", $item('https://evil.example/phish'))->assertSessionHasErrors('items.0.link');
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$promos->id}", $item('//evil.example'))->assertSessionHasErrors('items.0.link');
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$promos->id}", $item('/market?sector=wood'))->assertSessionHasNoErrors();
        $this->assertSame('/market?sector=wood', collect($this->blocks())->firstWhere('kind', 'promos')['items'][0]['link']);
    }

    public function test_the_opening_collage_takes_a_tone_and_a_link_from_the_admin(): void
    {
        StorefrontBlock::seedPage('market');
        $show = StorefrontBlock::where('page', 'market')->where('kind', 'showcase')->first();
        $items = $show->items;
        $items[0]['tone'] = 'plum';

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$show->id}", ['items' => $items, 'link' => '/market?all=1'])->assertSessionHasNoErrors();
        $this->assertSame('plum', collect($this->blocks())->firstWhere('kind', 'showcase')['items'][0]['tone']);

        $items[0]['tone'] = 'neon';
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$show->id}", ['items' => $items])->assertSessionHasErrors('items.0.tone');
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$show->id}", ['link' => 'https://evil.example'])->assertSessionHasErrors('link');
    }

    public function test_the_long_home_carries_the_new_section_kinds(): void
    {
        StorefrontBlock::seedPage('market');
        $kinds = array_column($this->blocks(), 'kind');

        foreach (['showcase', 'ticker', 'feature', 'rail', 'numbers'] as $kind) {
            $this->assertContains($kind, $kinds);
        }
        $this->assertGreaterThanOrEqual(14, count($kinds));
    }

    public function test_an_item_picture_is_uploaded_served_kept_and_removed(): void
    {
        StorefrontBlock::seedPage('market');
        $collections = StorefrontBlock::where('page', 'market')->where('kind', 'collections')->first();

        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$collections->id}/items/1/image", ['image' => UploadedFile::fake()->image('wood.jpg', 600, 800)])->assertSessionHasNoErrors();
        $path = $collections->fresh()->items[1]['image_path'];
        $this->assertNotNull($path);
        $this->get("/storefront-item-image/{$collections->id}/1")->assertOk();
        $this->get("/storefront-item-image/{$collections->id}/0")->assertNotFound();

        // Saving the words keeps the picture; a made-up path is dropped.
        $items = $collections->fresh()->items;
        $items[1]['image_path'] = $path;
        $items[2]['image_path'] = 'storefront/not-mine.jpg';
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$collections->id}", ['items' => $items])->assertSessionHasNoErrors();
        $this->assertSame($path, $collections->fresh()->items[1]['image_path']);
        $this->assertNull($collections->fresh()->items[2]['image_path']);
        $this->assertStringContainsString('storefront-item-image', collect($this->blocks())->firstWhere('kind', 'collections')['items'][1]['image']);

        $this->actingAs($this->admin)->delete("/admin/storefront/blocks/{$collections->id}/items/1/image");
        $this->assertNull($collections->fresh()->items[1]['image_path']);
        $this->get("/storefront-item-image/{$collections->id}/1")->assertNotFound();
    }

    public function test_pictures_belong_only_to_items_that_exist_on_sections_that_take_them(): void
    {
        StorefrontBlock::seedPage('market');
        $collections = StorefrontBlock::where('page', 'market')->where('kind', 'collections')->first();
        $faq = StorefrontBlock::where('page', 'market')->where('kind', 'faq')->first();
        $picture = ['image' => UploadedFile::fake()->image('a.jpg')];

        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$collections->id}/items/99/image", $picture)->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$faq->id}/items/0/image", $picture)->assertNotFound();
        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$collections->id}/items/0/image", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');

        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($buyer)->post("/admin/storefront/blocks/{$collections->id}/items/0/image", $picture)->assertRedirect('/');
    }
}
