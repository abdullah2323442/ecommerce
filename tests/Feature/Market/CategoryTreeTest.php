<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\StorefrontBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    private Sector $sector;

    private Category $audio;

    private Category $headphones;

    private Category $overEar;

    protected function setUp(): void
    {
        parent::setUp();
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['electronics']])->fresh();
        $this->sector = Sector::create(['slug' => 'electronics', 'name' => 'Electronics', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->audio = Category::create(['sector_id' => $this->sector->id, 'slug' => 'audio', 'name' => 'Audio']);
        $this->headphones = Category::create(['sector_id' => $this->sector->id, 'parent_id' => $this->audio->id, 'slug' => 'headphones', 'name' => 'Headphones']);
        $this->overEar = Category::create(['sector_id' => $this->sector->id, 'parent_id' => $this->headphones->id, 'slug' => 'over-ear', 'name' => 'Over-ear']);
        StorefrontBlock::seedDefaults($this->sector);
        $speakers = Category::create(['sector_id' => $this->sector->id, 'parent_id' => $this->audio->id, 'slug' => 'speakers', 'name' => 'Speakers']);

        foreach ([['Studio cans', $this->overEar], ['Party speaker', $speakers]] as [$title, $cat]) {
            Listing::create([
                'seller_id' => $seller->id, 'sector_id' => $this->sector->id, 'category_id' => $cat->id, 'title' => $title, 'public_price' => 1000, 'min_qty' => 1, 'stock' => 5,
                'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
            ]);
        }
    }

    public function test_a_node_finds_everything_at_or_below_it(): void
    {
        $this->assertEqualsCanonicalizing([$this->audio->id, $this->headphones->id, $this->overEar->id, Category::where('slug', 'speakers')->value('id')], $this->audio->selfAndDescendantIds());
        $this->assertSame([$this->overEar->id], $this->overEar->selfAndDescendantIds());
    }

    public function test_a_category_page_lists_its_whole_subtree_and_names_the_way_down(): void
    {
        $this->get('/market?sector=electronics&category=audio')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('items', 2)
            ->where('category.slug', 'audio')
            ->has('category.children', 2)
            ->where('category.children.0.slug', 'headphones'));
    }

    public function test_a_deep_node_shows_only_its_own_products_and_the_trail_above_it(): void
    {
        $this->get('/market?sector=electronics&category=over-ear')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.title', 'Studio cans')
            ->where('category.trail.0.slug', 'audio')
            ->where('category.trail.2.slug', 'over-ear'));
    }

    public function test_the_tree_counts_products_below_each_node_and_hides_empty_ones(): void
    {
        Category::create(['sector_id' => $this->sector->id, 'slug' => 'cameras', 'name' => 'Cameras']);

        $this->get('/market?sector=electronics')->assertInertia(fn (Assert $page) => $page
            ->has('tree', 1)
            ->where('tree.0.slug', 'audio')
            ->where('tree.0.count', 2)
            ->where('storefront.blocks.1.kind', 'categories')
            ->has('storefront.blocks.1.categories', 1));
    }

    public function test_an_unknown_category_is_just_the_whole_shop(): void
    {
        $this->get('/market?sector=electronics&category=nope')->assertOk()->assertInertia(fn (Assert $page) => $page->where('category', null)->has('items', 2));
    }
}
