<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\StorefrontBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Shop fronts: the owner builds each shop's front page from sections, and the public page draws exactly that. */
class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Sector $sector;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['electronics']])->fresh();
        $this->sector = Sector::create(['slug' => 'electronics', 'name' => 'Electronics', 'unit' => 'piece', 'allow_fraction' => false, 'tagline' => 'Everyday gadgets']);
        $audio = Category::create(['sector_id' => $this->sector->id, 'slug' => 'audio', 'name' => 'Audio']);

        foreach ([['Studio cans', 4000], ['Party speaker', 9000], ['Mini speaker', 1500]] as [$title, $price]) {
            Listing::create([
                'seller_id' => $seller->id, 'sector_id' => $this->sector->id, 'category_id' => $audio->id, 'title' => $title, 'public_price' => $price, 'min_qty' => 1, 'stock' => 5,
                'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
            ]);
        }
    }

    private function blocks(): array
    {
        return $this->get('/market?sector=electronics')->viewData('page')['props']['storefront']['blocks'] ?? [];
    }

    public function test_a_shop_with_no_sections_shows_the_plain_list(): void
    {
        $this->get('/market?sector=electronics')->assertInertia(fn (Assert $p) => $p->where('storefront', null)->has('items', 3));
    }

    public function test_the_standard_sections_fill_an_empty_shop_once(): void
    {
        $this->actingAs($this->admin)->post("/admin/storefront/{$this->sector->id}/defaults")->assertRedirect();
        $this->actingAs($this->admin)->post("/admin/storefront/{$this->sector->id}/defaults");

        $this->assertSame(10, StorefrontBlock::where('sector_id', $this->sector->id)->count());
    }

    public function test_the_admin_page_lists_the_sections_and_what_can_be_set(): void
    {
        StorefrontBlock::seedDefaults($this->sector);

        $this->actingAs($this->admin)->get('/admin/storefront?sector=electronics')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/storefront')
            ->where('sector', 'electronics')
            ->has('blocks', 10)
            ->has('kinds.faq.fields')
            ->has('sources.best'));
    }

    public function test_only_the_owner_gets_in(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->get('/admin/storefront')->assertRedirect();
        $this->actingAs($buyer)->get('/admin/storefront')->assertRedirect('/');
        $this->actingAs($buyer)->post("/admin/storefront/{$this->sector->id}/defaults")->assertRedirect('/');
        $this->assertSame(0, StorefrontBlock::count());
    }

    public function test_the_public_page_draws_the_sections_in_the_admins_order_with_the_admins_words(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $heading = StorefrontBlock::where('kind', 'categories')->first();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$heading->id}", ['title' => 'Shop the aisles', 'title_bn' => 'Shop the aisles'])->assertSessionHasNoErrors();

        $kinds = array_column($this->blocks(), 'kind');
        $this->assertSame('hero', $kinds[0]);
        $this->assertSame('cta', end($kinds));
        $this->assertSame('Shop the aisles', collect($this->blocks())->firstWhere('kind', 'categories')['title']);
    }

    public function test_a_hidden_section_is_not_on_the_public_page(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $faq = StorefrontBlock::where('kind', 'faq')->first();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$faq->id}", ['is_active' => false]);

        $this->assertNotContains('faq', array_column($this->blocks(), 'kind'));
    }

    public function test_moving_a_section_swaps_it_with_its_neighbour(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $second = StorefrontBlock::where('sector_id', $this->sector->id)->orderBy('sort')->skip(1)->first();
        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$second->id}/move", ['direction' => 'up']);

        $this->assertSame('categories', StorefrontBlock::where('sector_id', $this->sector->id)->orderBy('sort')->first()->kind);
    }

    public function test_a_section_can_be_added_and_removed(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $this->actingAs($this->admin)->post("/admin/storefront/{$this->sector->id}/blocks", ['kind' => 'products'])->assertSessionHasNoErrors();
        $added = StorefrontBlock::where('sector_id', $this->sector->id)->where('kind', 'products')->orderByDesc('sort')->first();

        $this->assertSame(11, StorefrontBlock::count());
        $this->actingAs($this->admin)->delete("/admin/storefront/blocks/{$added->id}");
        $this->assertSame(10, StorefrontBlock::count());
        $this->actingAs($this->admin)->post("/admin/storefront/{$this->sector->id}/blocks", ['kind' => 'nope'])->assertSessionHasErrors('kind');
    }

    public function test_a_product_shows_once_even_when_two_shelves_would_both_pick_it(): void
    {
        StorefrontBlock::seedDefaults($this->sector);

        $titles = collect($this->blocks())->whereIn('kind', ['products', 'banner', 'premium'])->flatMap(fn ($b) => isset($b['product']) ? [$b['product']['title']] : array_column($b['products'], 'title'));

        $this->assertSame($titles->unique()->count(), $titles->count());
    }

    public function test_a_shelf_can_be_filled_from_a_category_or_one_chosen_product(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $shelf = StorefrontBlock::where('kind', 'products')->first();
        $one = Listing::where('title', 'Mini speaker')->first();

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => 'category:audio', 'item_limit' => 2])->assertSessionHasNoErrors();
        $this->assertCount(2, collect($this->blocks())->firstWhere('id', $shelf->id)['products']);

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => (string) $one->id])->assertSessionHasNoErrors();
        $this->assertSame(['Mini speaker'], array_column(collect($this->blocks())->firstWhere('id', $shelf->id)['products'], 'title'));
    }

    public function test_an_unknown_source_or_an_empty_question_is_refused(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $shelf = StorefrontBlock::where('kind', 'products')->first();
        $faq = StorefrontBlock::where('kind', 'faq')->first();

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => 'category:missing'])->assertSessionHasErrors('source');
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$faq->id}", ['items' => [['title' => '', 'text' => 'x']]])->assertSessionHasErrors('items.0.title');
    }

    public function test_the_questions_are_the_ones_the_admin_wrote(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $faq = StorefrontBlock::where('kind', 'faq')->first();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$faq->id}", ['items' => [['title' => 'Do you open on Friday?', 'text' => 'Yes, every day.']]])->assertSessionHasNoErrors();

        $this->assertSame([['title' => 'Do you open on Friday?', 'text' => 'Yes, every day.', 'link' => null, 'tone' => null, 'image' => null]], collect($this->blocks())->firstWhere('kind', 'faq')['items']);
    }

    public function test_a_banner_picture_is_uploaded_served_and_removed(): void
    {
        Storage::fake();
        StorefrontBlock::seedDefaults($this->sector);
        $hero = StorefrontBlock::where('kind', 'hero')->first();

        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$hero->id}/image", ['image' => UploadedFile::fake()->image('banner.jpg', 800, 400)])->assertSessionHasNoErrors();
        $this->assertNotNull($hero->fresh()->image_path);
        $this->get("/storefront-image/{$hero->id}")->assertOk();
        $this->assertStringContainsString('storefront-image', $this->blocks()[0]['image']);

        $this->actingAs($this->admin)->delete("/admin/storefront/blocks/{$hero->id}/image");
        $this->assertNull($hero->fresh()->image_path);
        $this->get("/storefront-image/{$hero->id}")->assertNotFound();
    }

    public function test_only_pictures_are_accepted_and_only_where_a_picture_belongs(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $hero = StorefrontBlock::where('kind', 'hero')->first();
        $faq = StorefrontBlock::where('kind', 'faq')->first();

        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$hero->id}/image", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('image');
        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$faq->id}/image", ['image' => UploadedFile::fake()->image('a.jpg')])->assertNotFound();
    }

    public function test_the_bengali_line_is_used_in_bengali_and_english_is_the_fallback(): void
    {
        StorefrontBlock::seedDefaults($this->sector);
        $cta = StorefrontBlock::where('kind', 'cta')->first();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$cta->id}", ['button_bn' => null]);

        app()->setLocale('bn');
        $this->assertSame('Shop all {n} products', $cta->fresh()->say('button'));
        $cta->update(['button_bn' => 'সব দেখুন']);
        $this->assertSame('সব দেখুন', $cta->fresh()->say('button'));
    }
}
