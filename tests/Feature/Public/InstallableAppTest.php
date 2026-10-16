<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Site\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Spec ch 3 and 27: the customer app (an installable website) with a message when a newer version is ready. */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function dims(string $png): array
    {
        $i = getimagesizefromstring($png);

        return [$i[0], $i[1], $i['mime']];
    }

    public function test_the_manifest_is_made_from_the_shops_own_name_and_colour(): void
    {
        app(Site::class)->save('brand', ['name' => 'Aqua Bazar', 'name_bn' => 'অ্যাকোয়া বাজার', 'tagline' => ['en' => 'All shops', 'bn' => 'সব দোকান'], 'theme_color' => '#112233'], $this->admin);

        $r = $this->get('/manifest.webmanifest')->assertOk();

        $this->assertStringContainsString('application/manifest+json', $r->headers->get('Content-Type'));
        $r->assertJsonPath('name', 'অ্যাকোয়া বাজার')->assertJsonPath('theme_color', '#112233')->assertJsonPath('display', 'standalone')->assertJsonPath('start_url', '/')->assertJsonPath('lang', 'bn')->assertJsonCount(3, 'icons');
        $this->assertLessThanOrEqual(12, mb_strlen($r->json('short_name')));
        $this->assertSame(['any', 'any', 'maskable'], array_column($r->json('icons'), 'purpose'));
    }

    public function test_it_works_before_anyone_has_set_anything_up(): void
    {
        $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('theme_color', '#0f766e');
    }

    public function test_the_icons_are_real_pngs_of_the_right_size(): void
    {
        foreach (['192' => 192, '512' => 512, 'maskable-512' => 512, 'apple-180' => 180] as $name => $px) {
            $r = $this->get("/pwa/icon/{$name}.png")->assertOk();
            $this->assertSame([$px, $px, 'image/png'], $this->dims($r->getContent()), $name);
            $this->assertStringContainsString('max-age', $r->headers->get('Cache-Control'));
        }

        $this->get('/pwa/icon/999.png')->assertRedirect();
        $this->get('/pwa/icon/evil.png')->assertRedirect();
    }

    public function test_the_icon_background_follows_the_shops_colour(): void
    {
        app(Site::class)->save('brand', ['theme_color' => '#ff0000'], $this->admin);

        $img = imagecreatefromstring($this->get('/pwa/icon/192.png')->getContent());
        $rgb = imagecolorsforindex($img, imagecolorat($img, 2, 2));

        $this->assertSame([255, 0, 0], [$rgb['red'], $rgb['green'], $rgb['blue']]);
    }

    public function test_the_icon_is_drawn_from_the_logo_when_there_is_one(): void
    {
        Storage::fake();
        app(Site::class)->save('brand', ['theme_color' => '#ffffff'], $this->admin);
        app(Site::class)->putImage('logo', UploadedFile::fake()->image('logo.png', 400, 200)->size(40), $this->admin);

        $with = $this->get('/pwa/icon/512.png')->assertOk()->getContent();
        $this->assertSame([512, 512, 'image/png'], $this->dims($with));

        $manifest = $this->get('/manifest.webmanifest')->json();
        $this->assertStringContainsString('v=', $manifest['icons'][0]['src']);

        app(Site::class)->removeImage('logo', $this->admin);
        $this->assertNotSame(md5($with), md5($this->get('/pwa/icon/512.png')->getContent()));
    }

    public function test_a_bad_colour_is_refused(): void
    {
        foreach (['red', '#12345', '#gggggg', 'javascript:1', '#1234567'] as $bad) {
            $this->actingAs($this->admin)->put('/admin/site/brand', ['name' => 'X', 'theme_color' => $bad])->assertSessionHasErrors('site');
        }

        $this->actingAs($this->admin)->put('/admin/site/brand', ['name' => 'X', 'theme_color' => '#0A1B2C'])->assertSessionHasNoErrors();
    }

    public function test_pages_point_to_the_manifest_and_set_the_bar_colour(): void
    {
        app(Site::class)->save('brand', ['theme_color' => '#112233'], $this->admin);

        $html = $this->get('/')->getContent();

        $this->assertStringContainsString('rel="manifest" href="/manifest.webmanifest"', $html);
        $this->assertStringContainsString('name="theme-color" content="#112233"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon" href="/pwa/icon/apple-180.png"', $html);
    }

    public function test_the_service_worker_is_served_from_the_root_with_the_builds_fingerprint(): void
    {
        $r = $this->get('/sw.js')->assertOk();

        $this->assertStringContainsString('javascript', $r->headers->get('Content-Type'));
        $this->assertSame('/', $r->headers->get('Service-Worker-Allowed'));
        $this->assertStringContainsString('no-cache', $r->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/\n\/\/ build: [a-z0-9]+\n$/', $r->getContent());
        $this->assertStringContainsString("'skip-waiting'", $r->getContent());
        $this->assertStringContainsString('/offline', $r->getContent());
    }

    public function test_the_worker_waits_for_the_person_before_taking_over(): void
    {
        $js = $this->get('/sw.js')->getContent();

        $this->assertStringNotContainsString("addEventListener('install', () => self.skipWaiting())", $js);
        $this->assertSame(1, substr_count($js, 'self.skipWaiting()'));   // only when asked, by message
    }

    public function test_a_new_build_is_a_new_worker(): void
    {
        $manifest = public_path('build/manifest.json');
        $had = is_file($manifest);
        $old = $had ? file_get_contents($manifest) : null;

        @mkdir(dirname($manifest), 0777, true);
        file_put_contents($manifest, '{"a":1}');
        $a = $this->get('/sw.js')->getContent();
        file_put_contents($manifest, '{"a":1,"b":2}');
        $b = $this->get('/sw.js')->getContent();

        $had ? file_put_contents($manifest, $old) : unlink($manifest);

        $this->assertNotSame($a, $b);
    }

    public function test_the_no_line_page_opens_for_everyone_and_says_what_to_do(): void
    {
        $r = $this->get('/offline')->assertOk();

        $this->assertStringContainsString('<button', $r->getContent());
        $this->assertStringContainsString('location.reload()', $r->getContent());
        $this->assertStringNotContainsString('<script src', $r->getContent());
    }

    public function test_the_shop_name_in_the_no_line_page_cannot_inject_markup(): void
    {
        app(Site::class)->save('brand', ['name' => '<img src=x onerror=alert(1)>'], $this->admin);

        $this->assertStringNotContainsString('<img src=x', $this->get('/offline')->getContent());
    }
}
