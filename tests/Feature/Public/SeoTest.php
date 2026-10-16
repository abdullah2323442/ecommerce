<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Game;
use App\Models\Listing;
use App\Models\Medicine;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Spec ch 27: SEO. Search engines find only what any visitor can open, and are told to leave the private side alone. */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    private User $named;

    private User $quiet;

    private Sector $wood;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece']);
        $this->named = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'show_shop_name' => true])->fresh();
        $this->quiet = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Karim Wood', 'show_shop_name' => false])->fresh();
    }

    private function listing(User $seller, string $title, array $more = []): Listing
    {
        return Listing::create($more + [
            'seller_id' => $seller->id, 'sector_id' => $this->wood->id, 'title' => $title, 'public_price' => 500, 'min_qty' => 1, 'stock' => 5, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    private function medicine(string $name, bool $active = true): Medicine
    {
        return Medicine::create([
            'company_id' => Company::firstOrCreate(['name' => 'Square'])->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => $active,
        ]);
    }

    public function test_robots_keeps_the_private_sides_out_and_points_to_the_sitemap(): void
    {
        $body = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

        foreach (['/admin', '/paikari', '/wholesale', '/mpo', '/orders/', '/market/orders/', '/checkout', '/login'] as $path) {
            $this->assertStringContainsString('Disallow: '.$path, $body);
        }
        $this->assertStringNotContainsString("Disallow: /\n", $body, 'the public pages are not shut');
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $body);
    }

    public function test_the_index_lists_the_pages_file_and_the_medicine_files(): void
    {
        $this->medicine('Napa');

        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString(url('/sitemap-pages.xml'), $xml);
        $this->assertStringContainsString(url('/sitemap-medicines-1.xml'), $xml);
    }

    public function test_medicines_are_listed_and_a_switched_off_one_is_not(): void
    {
        $on = $this->medicine('Napa');
        $off = $this->medicine('Hidden', false);

        $xml = $this->get('/sitemap-medicines-1.xml')->assertOk()->getContent();

        $this->assertStringContainsString(route('medicines.show', $on), $xml);
        $this->assertStringNotContainsString(route('medicines.show', $off), $xml);
        $this->get('/sitemap-medicines-0.xml')->assertRedirect('/');
    }

    public function test_the_pages_file_has_the_open_pages(): void
    {
        $xml = $this->get('/sitemap-pages.xml')->assertOk()->getContent();

        foreach ([route('home'), route('medicines.index'), route('market.index'), route('info.faq')] as $url) {
            $this->assertStringContainsString('<loc>'.$url.'</loc>', $xml);
        }
    }

    public function test_a_product_a_stranger_can_open_is_listed_and_one_they_cannot_is_not(): void
    {
        $open = $this->listing($this->named, 'Teak chair');
        $paused = $this->listing($this->named, 'Paused', ['status' => 'paused']);
        $pending = $this->listing($this->named, 'Pending', ['status' => 'pending']);
        $tradeOnly = $this->listing($this->named, 'Bulk only', ['public_price' => null, 'wholesale_price' => 300]);
        $expired = $this->listing($this->named, 'Expired', ['expires_on' => now()->subDays(3)]);
        $this->wood->update(['is_active' => false]);
        $closed = $this->listing($this->quiet, 'In a closed sector');
        $this->wood->update(['is_active' => true]);

        $xml = $this->get('/sitemap-pages.xml')->getContent();

        $this->assertStringContainsString(route('market.show', $open), $xml);
        foreach ([$paused, $pending, $tradeOnly, $expired] as $l) {
            $this->assertStringNotContainsString(route('market.show', $l), $xml, $l->title);
        }
        $this->assertNotNull($closed);
    }

    public function test_a_shop_page_is_listed_only_when_the_shop_has_one(): void
    {
        $this->listing($this->named, 'Teak chair');
        $this->listing($this->quiet, 'Teak table');

        $xml = $this->get('/sitemap-pages.xml')->getContent();

        $this->assertStringContainsString(route('shop.show', $this->named->id), $xml);
        $this->assertStringNotContainsString(route('shop.show', $this->quiet->id), $xml);
    }

    public function test_an_open_game_is_listed_and_a_closed_one_is_not(): void
    {
        Game::create(['name' => 'Free Fire', 'slug' => 'free-fire', 'id_label' => 'Player ID', 'is_active' => true]);
        Game::create(['name' => 'Old', 'slug' => 'old-game', 'id_label' => 'Player ID', 'is_active' => false]);

        $xml = $this->get('/sitemap-pages.xml')->getContent();

        $this->assertStringContainsString('/gaming/free-fire', $xml);
        $this->assertStringNotContainsString('old-game', $xml);
    }

    public function test_the_sitemap_has_nothing_private(): void
    {
        $this->listing($this->named, 'Teak chair');
        $xml = $this->get('/sitemap-pages.xml')->getContent().$this->get('/sitemap.xml')->getContent();

        foreach (['/admin', '/paikari', '/wholesale', '/orders/', '/checkout', '/login', 'token'] as $private) {
            $this->assertStringNotContainsString($private, $xml, $private);
        }
    }

    public function test_the_private_side_carries_a_no_index_header_and_the_public_side_does_not(): void
    {
        $this->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/cart')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Admin]))->get('/admin/staff')->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        auth()->logout();
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/market')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/medicines')->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_a_page_for_the_holder_of_a_link_is_not_for_search_engines(): void
    {
        $token = str_repeat('a', 40);

        $this->get("/orders/{$token}")->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get("/market/orders/{$token}")->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get("/gaming/orders/{$token}")->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_a_word_that_only_starts_like_a_private_path_is_not_caught(): void
    {
        $this->get('/administer')->assertHeaderMissing('X-Robots-Tag');
        $this->get('/ordersheet')->assertHeaderMissing('X-Robots-Tag');
    }
}
