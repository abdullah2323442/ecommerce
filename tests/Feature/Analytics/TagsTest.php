<?php

namespace Tests\Feature\Analytics;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27, Facebook Pixel and Analytics: the owner types the ids in Connections, and the site reports visits to them, never for staff. */
class TagsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function save(string $key, array $fields, bool $on = true, string $mode = 'live'): void
    {
        app(Integrations::class)->save($key, $fields, $on, $mode, [], $this->admin);
    }

    public function test_nothing_is_sent_until_the_owner_switches_it_on(): void
    {
        $this->get('/')->assertOk()->assertDontSee('fbevents.js')->assertDontSee('googletagmanager')->assertInertia(fn (Assert $p) => $p->where('analytics', null));
    }

    public function test_the_meta_pixel_is_added_to_every_public_page_when_on_and_live(): void
    {
        $this->save('analytics:meta_pixel', ['pixel_id' => '123456789012345']);

        $this->get('/')->assertSee('connect.facebook.net/en_US/fbevents.js', false)->assertSee("fbq('init', \"123456789012345\")", false)->assertDontSee('googletagmanager')
            ->assertInertia(fn (Assert $p) => $p->where('analytics.meta_pixel', '123456789012345')->where('analytics.ga4', null));
    }

    public function test_google_analytics_is_added_with_its_own_page_views_turned_off_because_the_app_sends_them(): void
    {
        $this->save('analytics:ga4', ['measurement_id' => 'G-ABC123XYZ4']);

        $this->get('/')->assertSee('https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ4', false)->assertSee('send_page_view: false', false)->assertDontSee('fbevents.js');
    }

    public function test_both_can_be_on_together(): void
    {
        $this->save('analytics:meta_pixel', ['pixel_id' => '123456789012345']);
        $this->save('analytics:ga4', ['measurement_id' => 'G-ABC123XYZ4']);

        $this->get('/')->assertSee('fbevents.js', false)->assertSee('googletagmanager', false);
    }

    public function test_in_test_mode_nothing_is_sent(): void
    {
        $this->save('analytics:meta_pixel', ['pixel_id' => '123456789012345'], true, 'test');

        $this->get('/')->assertDontSee('fbevents.js');
    }

    public function test_switched_off_means_nothing_is_sent_though_the_id_is_kept(): void
    {
        $this->save('analytics:meta_pixel', ['pixel_id' => '123456789012345'], false);

        $this->get('/')->assertDontSee('fbevents.js');
    }

    public function test_staff_the_owner_and_the_admin_screens_are_never_counted(): void
    {
        $this->save('analytics:meta_pixel', ['pixel_id' => '123456789012345']);
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        $this->actingAs($this->admin)->get('/')->assertDontSee('fbevents.js');
        $this->actingAs($staff)->get('/')->assertDontSee('fbevents.js');
        $this->actingAs($this->admin)->get('/admin/customers')->assertDontSee('fbevents.js');
    }

    public function test_a_signed_in_buyer_is_counted(): void
    {
        $this->save('analytics:meta_pixel', ['pixel_id' => '123456789012345']);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($buyer)->get('/')->assertSee('fbevents.js', false);
    }

    public function test_an_id_that_is_not_written_the_way_the_service_writes_it_is_refused(): void
    {
        foreach (['abc', '12345', "123456789012345');alert(1);//", '123456789012345678901234'] as $bad) {
            try {
                $this->save('analytics:meta_pixel', ['pixel_id' => $bad]);
                $this->fail("Accepted {$bad}");
            } catch (StockException $e) {
                $this->assertStringContainsString('digits', $e->getMessage());
            }
        }

        foreach (['UA-123', 'G-', 'g-abc123xyz4', "G-ABC';alert(1);//"] as $bad) {
            try {
                $this->save('analytics:ga4', ['measurement_id' => $bad]);
                $this->fail("Accepted {$bad}");
            } catch (StockException $e) {
                $this->assertStringContainsString('G-', $e->getMessage());
            }
        }
    }

    public function test_the_owner_saves_both_from_the_connections_screen_including_the_one_with_a_digit_in_its_name(): void
    {
        $this->actingAs($this->admin);

        $this->put('/admin/connections/analytics:meta_pixel', ['fields' => ['pixel_id' => '123456789012345'], 'enabled' => 1, 'mode' => 'live'])->assertSessionHasNoErrors();
        $this->put('/admin/connections/analytics:ga4', ['fields' => ['measurement_id' => 'G-ABC123XYZ4'], 'enabled' => 1, 'mode' => 'live'])->assertSessionHasNoErrors();
        $this->put('/admin/connections/analytics:ga4', ['fields' => ['measurement_id' => 'oops'], 'enabled' => 1, 'mode' => 'live'])->assertSessionHasErrors('connection');

        auth()->logout(); // the owner's own visits are never counted, so look as a visitor
        $this->get('/')->assertSee('fbevents.js', false)->assertSee('G-ABC123XYZ4', false);
    }

    public function test_it_cannot_be_switched_on_without_its_id(): void
    {
        $this->expectException(StockException::class);
        $this->save('analytics:ga4', []);
    }

    public function test_the_connections_screen_lists_both_with_their_help_and_the_test_button_answers(): void
    {
        $this->save('analytics:meta_pixel', ['pixel_id' => '123456789012345'], false);

        $r = $this->actingAs($this->admin)->get('/admin/connections');
        $r->assertOk();
        $types = collect($r->viewData('page')['props']['types'] ?? [])->pluck('type')->all();
        $this->assertContains('analytics', $types);

        $this->assertTrue(app(Integrations::class)->test('analytics:meta_pixel', [], $this->admin)['ok']);
    }
}
