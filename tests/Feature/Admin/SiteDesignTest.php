<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Site\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The owner changes the name, logo, contact, home page, footer, notice bar and search words from the admin panel. */
class SiteDesignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function save(string $group, array $data)
    {
        return $this->actingAs($this->admin)->put("/admin/site/{$group}", $data);
    }

    private function shared(?string $path = '/market'): array
    {
        return $this->get($path)->viewData('page')['props'];
    }

    public function test_the_site_is_complete_before_anyone_saves_anything(): void
    {
        $site = $this->shared()['site'];

        $this->assertCount(6, $site['home']['sections']);
        $this->assertTrue(collect($site['home']['sections'])->every(fn ($s) => $s['open'] && $s['href']));
        $this->assertCount(11, $site['footer']['links']);
        $this->assertNull($site['announcement']);
        $this->assertSame([], $site['social']);
    }

    public function test_a_new_name_shows_on_every_page_in_the_viewers_language(): void
    {
        $this->save('brand', ['name' => 'Aqua Bazar', 'name_bn' => 'অ্যাকোয়া বাজার', 'tagline' => ['en' => 'All shops', 'bn' => 'সব দোকান']])->assertSessionHasNoErrors();

        $this->assertSame('অ্যাকোয়া বাজার', $this->shared()['brand']['name'], 'Bangla is the default language');
        $this->withCookie('locale', 'en');
        $en = app(Site::class)->forViewer('en')['brand'];
        $this->assertSame('Aqua Bazar', $en['name']);
        $this->assertSame('All shops', $en['tagline']);
    }

    public function test_an_empty_bangla_text_shows_the_english_one(): void
    {
        $this->save('footer', ['about' => ['en' => 'We sell things', 'bn' => ''], 'copyright' => ['en' => '', 'bn' => ''], 'links' => []]);

        $this->assertSame('We sell things', app(Site::class)->forViewer('bn')['site']['footer']['about']);
        $this->assertNull(app(Site::class)->forViewer('bn')['site']['footer']['copyright']);
    }

    public function test_only_people_who_run_the_accounts_may_change_the_site(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($staff)->put('/admin/site/brand', ['name' => 'Hacked'])->assertRedirect('/');
        $this->actingAs($buyer)->get('/admin/site')->assertRedirect('/');
        $this->get('/admin/site')->assertRedirect();
        $this->assertSame(0, SiteSetting::count());
        $this->actingAs($this->admin)->get('/admin/site')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/site')->has('groups.home.sections', 6));
    }

    public function test_an_unknown_part_of_the_site_is_not_there(): void
    {
        $this->actingAs($this->admin)->put('/admin/site/secrets', ['x' => 1])->assertNotFound();
    }

    public function test_links_must_be_a_path_on_the_site_or_https(): void
    {
        foreach (['javascript:alert(1)', 'http://plain.example.com', '//evil.example.com', 'data:text/html,x', 'ftp://x.example.com', '/ok path'] as $bad) {
            $this->save('social', ['facebook' => $bad])->assertSessionHasErrors('site');
        }
        $this->assertSame(0, SiteSetting::count());

        $this->save('social', ['facebook' => 'https://facebook.com/shop', 'instagram' => '', 'youtube' => null])->assertSessionHasNoErrors();
        $this->assertSame(['facebook' => 'https://facebook.com/shop'], $this->shared()['site']['social']);
    }

    public function test_footer_links_are_replaced_and_half_filled_ones_are_dropped(): void
    {
        $this->save('footer', ['about' => [], 'copyright' => [], 'links' => [
            ['label' => 'Our story', 'label_bn' => 'আমাদের কথা', 'url' => '/about', 'enabled' => true],
            ['label' => 'No link', 'url' => '', 'enabled' => true],
            ['label' => '', 'url' => '/x', 'enabled' => true],
            ['label' => 'Hidden', 'url' => '/hidden', 'enabled' => false],
            ['label' => 'Facebook', 'url' => 'https://facebook.com/shop', 'enabled' => true],
        ]])->assertSessionHasNoErrors();

        $links = $this->shared()['site']['footer']['links'];

        $this->assertSame(['/about', 'https://facebook.com/shop'], array_column($links, 'url'));
        $this->assertSame('আমাদের কথা', $links[0]['label'], 'Bangla label for a Bangla reader');
        $this->assertFalse($links[0]['translate']);
        $this->assertTrue($links[1]['translate'], 'an English label with no Bangla is translated by the dictionary');
    }

    public function test_a_hidden_home_card_is_not_sent_and_the_order_and_status_follow_the_owner(): void
    {
        $this->save('home', ['hero_title' => ['en' => 'Hello', 'bn' => ''], 'hero_text' => [], 'sections' => [
            ['key' => 'market', 'enabled' => true, 'status' => 'soon'],
            ['key' => 'pharmacy', 'enabled' => true, 'status' => 'open'],
            ['key' => 'food', 'enabled' => false, 'status' => 'open'],
        ]])->assertSessionHasNoErrors();

        $home = $this->shared()['site']['home'];
        $keys = array_column($home['sections'], 'key');

        $this->assertSame(['market', 'pharmacy', 'games', 'custom', 'help'], $keys, 'the food card is hidden; the left-out cards stay at the end');
        $this->assertFalse($home['sections'][0]['open']);
        $this->assertSame('Hello', $home['hero_title']);
        $this->assertTrue(collect($home['sections'])->every(fn ($s) => $s['href'] !== null));
    }

    public function test_unknown_cards_and_repeats_are_ignored_so_a_card_can_only_point_where_the_code_says(): void
    {
        $this->save('home', ['sections' => [
            ['key' => 'evil', 'enabled' => true, 'status' => 'open', 'href' => 'https://evil.example.com'],
            ['key' => 'food', 'enabled' => true, 'status' => 'open'],
            ['key' => 'food', 'enabled' => false, 'status' => 'soon'],
        ]])->assertSessionHasNoErrors();

        $sections = $this->shared()['site']['home']['sections'];

        $this->assertCount(6, $sections);
        $this->assertNotContains('evil', array_column($sections, 'key'));
        $this->assertSame('/food', collect($sections)->firstWhere('key', 'food')['href']);
    }

    public function test_the_notice_bar_shows_only_when_it_is_on_and_has_words(): void
    {
        $this->save('announcement', ['enabled' => true, 'text' => ['en' => '', 'bn' => ''], 'link' => null]);
        $this->assertNull($this->shared()['site']['announcement']);

        $this->save('announcement', ['enabled' => true, 'text' => ['en' => 'Closed on Friday', 'bn' => ''], 'link' => '/faq']);
        $this->assertSame(['text' => 'Closed on Friday', 'link' => '/faq'], $this->shared()['site']['announcement']);

        $this->save('announcement', ['enabled' => false, 'text' => ['en' => 'Closed on Friday'], 'link' => null]);
        $this->assertNull($this->shared()['site']['announcement']);
    }

    public function test_contact_details_are_cleaned_and_empty_ones_fall_back_to_the_old_settings(): void
    {
        config(['brand.phone' => '01700000000']);
        $this->assertSame('01700000000', $this->shared()['brand']['phone']);

        $this->save('contact', ['phone' => '01811111111', 'email' => 'hello@example.com', 'whatsapp' => '+880 1811-111111', 'address' => ''])->assertSessionHasNoErrors();
        $brand = $this->shared()['brand'];

        $this->assertSame('01811111111', $brand['phone']);
        $this->assertSame('8801811111111', $brand['whatsapp']);
        $this->save('contact', ['phone' => ''])->assertSessionHasNoErrors();
        $this->assertSame('01700000000', $this->shared()['brand']['phone'], 'back to the older setting');
        $this->save('contact', ['email' => 'not an email'])->assertSessionHasErrors('site');
    }

    public function test_the_logo_is_stored_served_with_a_version_and_can_be_removed(): void
    {
        $this->actingAs($this->admin)->post('/admin/site/image/logo', ['image' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertSessionHasNoErrors();

        $url = $this->shared()['brand']['logo'];
        $this->assertStringContainsString('/brand/logo?v=', $url);
        $this->get($url)->assertOk()->assertHeader('cache-control');

        $path = app(Site::class)->group('brand')['logo'];
        $this->actingAs($this->admin)->delete('/admin/site/image/logo')->assertSessionHasNoErrors();
        $this->assertNull($this->shared()['brand']['logo']);
        Storage::assertMissing($path);
        $this->get('/brand/logo')->assertNotFound();
    }

    public function test_a_new_logo_replaces_the_old_file_and_changes_the_version(): void
    {
        $this->actingAs($this->admin)->post('/admin/site/image/logo', ['image' => UploadedFile::fake()->image('a.png', 100, 100)]);
        $first = app(Site::class)->group('brand')['logo'];
        $v1 = $this->shared()['brand']['logo'];

        $this->actingAs($this->admin)->post('/admin/site/image/logo', ['image' => UploadedFile::fake()->image('b.jpg', 100, 100)]);

        Storage::assertMissing($first);
        $this->assertNotSame($v1, $this->shared()['brand']['logo']);
    }

    public function test_only_pictures_are_taken_as_a_logo(): void
    {
        $this->actingAs($this->admin)->post('/admin/site/image/logo', ['image' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('image');
        $this->actingAs($this->admin)->post('/admin/site/image/logo', ['image' => UploadedFile::fake()->create('x.php', 10, 'text/x-php')])->assertSessionHasErrors('image');
        $this->actingAs($this->admin)->post('/admin/site/image/logo', ['image' => UploadedFile::fake()->image('big.png')->size(5000)])->assertSessionHasErrors('image');
        $this->actingAs($this->admin)->post('/admin/site/image/banner', ['image' => UploadedFile::fake()->image('x.png')])->assertNotFound();
        $this->assertNull(app(Site::class)->group('brand')['logo']);
    }

    public function test_saving_the_name_does_not_lose_the_logo(): void
    {
        $this->actingAs($this->admin)->post('/admin/site/image/logo', ['image' => UploadedFile::fake()->image('a.png', 100, 100)]);

        $this->save('brand', ['name' => 'New name', 'logo' => 'evil/path.png'])->assertSessionHasNoErrors();

        $this->assertNotNull($this->shared()['brand']['logo']);
        $this->assertNotSame('evil/path.png', app(Site::class)->group('brand')['logo']);
    }

    public function test_a_save_shows_at_once_not_after_the_cache_expires(): void
    {
        $this->assertNotSame('First', $this->shared()['brand']['name']);
        $this->save('brand', ['name' => 'First']);
        $this->assertSame('First', $this->shared()['brand']['name']);
        $this->save('brand', ['name' => 'Second']);
        $this->assertSame('Second', $this->shared()['brand']['name']);
    }

    public function test_the_log_says_what_changed_but_never_what_it_was_changed_to(): void
    {
        $this->save('contact', ['phone' => '01822222222', 'drug_license' => 'DL-SECRET-123']);

        $log = ActivityLog::where('action', 'site.saved')->firstOrFail();

        $this->assertSame('contact', $log->changes['group'] ?? null);
        $this->assertStringNotContainsString('01822222222', json_encode($log->toArray()));
        $this->assertStringNotContainsString('DL-SECRET-123', json_encode($log->toArray()));
    }

    public function test_the_search_description_and_icon_reach_the_page_head(): void
    {
        $this->save('seo', ['description' => ['en' => 'Medicine and more in Dhaka', 'bn' => '']]);

        $this->get('/')->assertSee('<meta name="description" content="Medicine and more in Dhaka">', false);
    }
}
