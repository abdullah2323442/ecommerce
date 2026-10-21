<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\FoodItem;
use App\Models\Game;
use App\Models\GamePackage;
use App\Models\Restaurant;
use App\Models\StorefrontBlock;
use App\Models\User;
use App\Services\Food\Restaurants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The front pages of pharmacy, food and game top-up, built from sections in Admin > Shop fronts. */
class PageFrontsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function props(string $url): array
    {
        return $this->get($url)->viewData('page')['props'];
    }

    private function restaurantWithDishes(): Restaurant
    {
        $shop = app(Restaurants::class)->open(['name' => 'Burger Barn', 'owner_name' => 'Rafi', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 10], $this->admin);

        foreach ([['Chicken biryani', 'Biryani', 320], ['Beef biryani', 'Biryani', 380], ['Cheese burger', 'Burgers', 250], ['Fried chicken', 'Chicken', 290]] as [$name, $cat, $price]) {
            FoodItem::create(['restaurant_id' => $shop->id, 'name' => $name, 'category' => $cat, 'price' => $price, 'available' => true]);
        }

        return $shop;
    }

    public function test_each_page_is_filled_with_its_standard_sections_once(): void
    {
        foreach (['medicines', 'food', 'gaming'] as $page) {
            StorefrontBlock::seedPage($page);
            StorefrontBlock::seedPage($page);
        }

        $this->assertSame(7, StorefrontBlock::where('page', 'medicines')->count());
        $this->assertSame(8, StorefrontBlock::where('page', 'food')->count());
        $this->assertSame(6, StorefrontBlock::where('page', 'gaming')->count());
        $this->assertSame(0, StorefrontBlock::whereNotNull('sector_id')->count());
    }

    public function test_a_page_with_no_sections_keeps_its_plain_layout(): void
    {
        $this->get('/medicines')->assertInertia(fn (Assert $p) => $p->where('front', null));
        $this->get('/food')->assertInertia(fn (Assert $p) => $p->where('front', null));
        $this->get('/gaming')->assertInertia(fn (Assert $p) => $p->where('front', null));
    }

    public function test_the_admin_sees_a_page_with_only_the_kinds_that_belong_to_it(): void
    {
        StorefrontBlock::seedPage('food');

        $this->actingAs($this->admin)->get('/admin/storefront?page=food')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/storefront')
            ->where('page', 'food')
            ->has('blocks', 8)
            ->has('kinds.dishes')
            ->missing('kinds.games')
            ->has('pages', 4)
            ->has('sources.best'));
    }

    public function test_only_the_owner_may_change_a_page(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($buyer)->post('/admin/storefront/pages/food/defaults')->assertRedirect('/');
        $this->assertSame(0, StorefrontBlock::count());
    }

    public function test_a_page_takes_only_its_own_kinds(): void
    {
        StorefrontBlock::seedPage('medicines');

        $this->actingAs($this->admin)->post('/admin/storefront/pages/medicines/blocks', ['kind' => 'games'])->assertSessionHasErrors('kind');
        $this->actingAs($this->admin)->post('/admin/storefront/pages/medicines/blocks', ['kind' => 'fresh'])->assertSessionHasNoErrors();
        $this->assertSame(8, StorefrontBlock::where('page', 'medicines')->count());
    }

    public function test_the_pharmacy_front_shows_the_admins_sections_and_hides_the_rest_when_browsing(): void
    {
        StorefrontBlock::seedPage('medicines');

        $kinds = array_column($this->props('/medicines')['front']['blocks'], 'kind');
        $this->assertSame('hero', $kinds[0]);
        $this->assertContains('faq', $kinds);

        // A chosen letter is a list, not a front page: only the banner with its search, and the list.
        $this->assertSame(['hero', 'browse'], array_column($this->props('/medicines?letter=A')['front']['blocks'], 'kind'));

        $faq = StorefrontBlock::where('page', 'medicines')->where('kind', 'faq')->first();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$faq->id}", ['is_active' => false]);
        $this->assertNotContains('faq', array_column($this->props('/medicines')['front']['blocks'], 'kind'));
    }

    public function test_the_food_front_carries_dishes_categories_and_restaurants(): void
    {
        $this->restaurantWithDishes();
        StorefrontBlock::seedPage('food');

        $blocks = collect($this->props('/food')['front']['blocks']);
        $this->assertContains('Biryani', array_column($blocks->firstWhere('kind', 'cuisines')['categories'], 'name'));
        $this->assertNotEmpty($blocks->firstWhere('kind', 'dishes')['dishes']);
        $this->assertNotNull($blocks->firstWhere('kind', 'restaurants'));

        // A dish shows once on the page.
        $titles = $blocks->whereIn('kind', ['dishes', 'banner'])->flatMap(fn ($b) => isset($b['dish']) ? [$b['dish']['name']] : array_column($b['dishes'], 'name'));
        $this->assertSame($titles->unique()->count(), $titles->count());
    }

    public function test_a_chosen_kind_of_food_narrows_the_page_to_it(): void
    {
        $this->restaurantWithDishes();
        StorefrontBlock::seedPage('food');

        $front = $this->props('/food?category=Biryani')['front'];
        $this->assertSame('Biryani', $front['category']);
        $list = collect($front['blocks'])->first(fn ($b) => $b['kind'] === 'dishes');
        $this->assertSame(['Beef biryani', 'Chicken biryani'], collect($list['dishes'])->pluck('name')->sort()->values()->all());
        $this->assertNotContains('faq', array_column($front['blocks'], 'kind'));

        $this->assertNull($this->props('/food?category=Nonsense')['front']['category']);
    }

    public function test_a_dish_shelf_can_be_filled_from_a_category_or_one_dish(): void
    {
        $this->restaurantWithDishes();
        StorefrontBlock::seedPage('food');
        $shelf = StorefrontBlock::where('page', 'food')->where('kind', 'dishes')->first();
        $one = FoodItem::where('name', 'Cheese burger')->first();

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => 'category:Chicken'])->assertSessionHasNoErrors();
        $this->assertSame(['Fried chicken'], array_column(collect($this->props('/food')['front']['blocks'])->firstWhere('id', $shelf->id)['dishes'], 'name'));

        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => (string) $one->id])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => 'category:Nope'])->assertSessionHasErrors('source');
    }

    public function test_the_game_front_lists_games_and_the_cheapest_packs_across_them(): void
    {
        foreach ([['Alpha', 'alpha', [['100 diamonds', 120], ['500 diamonds', 560]]], ['Beta', 'beta', [['Pass', 90]]]] as [$name, $slug, $packs]) {
            $game = Game::create(['name' => $name, 'slug' => $slug, 'id_label' => 'Player ID', 'is_active' => true]);

            foreach ($packs as [$pack, $price]) {
                GamePackage::create(['game_id' => $game->id, 'name' => $pack, 'price' => $price, 'is_active' => true]);
            }
        }

        StorefrontBlock::seedPage('gaming');
        $blocks = collect($this->props('/gaming')['front']['blocks']);

        $this->assertSame(['Alpha', 'Beta'], array_column($blocks->firstWhere('kind', 'games')['games'], 'name'));
        $this->assertSame(120.0, (float) $blocks->firstWhere('kind', 'games')['games'][0]['from']);
        $this->assertSame([90.0, 120.0, 560.0], array_map('floatval', array_column($blocks->firstWhere('kind', 'packs')['packs'], 'price')));

        $shelf = StorefrontBlock::where('page', 'gaming')->where('kind', 'packs')->first();
        $this->actingAs($this->admin)->put("/admin/storefront/blocks/{$shelf->id}", ['source' => 'game:alpha'])->assertSessionHasNoErrors();
        $this->assertSame(['100 diamonds', '500 diamonds'], array_column(collect($this->props('/gaming')['front']['blocks'])->firstWhere('kind', 'packs')['packs'], 'name'));
    }

    public function test_a_page_banner_picture_is_uploaded_and_served(): void
    {
        Storage::fake();
        StorefrontBlock::seedPage('gaming');
        $hero = StorefrontBlock::where('page', 'gaming')->where('kind', 'hero')->first();

        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$hero->id}/image", ['image' => UploadedFile::fake()->image('hero.jpg', 800, 400)])->assertSessionHasNoErrors();

        $this->get("/storefront-image/{$hero->id}")->assertOk();
        $this->assertStringContainsString('storefront-image', $this->props('/gaming')['front']['blocks'][0]['image']);
    }

    public function test_moving_a_page_section_stays_within_that_page(): void
    {
        StorefrontBlock::seedPage('food');
        StorefrontBlock::seedPage('gaming');
        $second = StorefrontBlock::where('page', 'food')->orderBy('sort')->skip(1)->first();

        $this->actingAs($this->admin)->post("/admin/storefront/blocks/{$second->id}/move", ['direction' => 'up']);

        $this->assertSame('cuisines', StorefrontBlock::where('page', 'food')->orderBy('sort')->first()->kind);
        $this->assertSame('hero', StorefrontBlock::where('page', 'gaming')->orderBy('sort')->first()->kind);
    }
}
