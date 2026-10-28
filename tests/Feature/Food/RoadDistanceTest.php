<?php

namespace Tests\Feature\Food;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\FoodItem;
use App\Models\FoodOrder;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Food\FoodOrders;
use App\Services\Food\Restaurants;
use App\Services\Food\RoadDistance;
use App\Services\Integrations\Integrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Spec ch 17, item 436: delivery by the kilometre uses the real road distance from Google Maps once the owner adds the key, and the estimate until then. */
class RoadDistanceTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 23.8069;

    private const LNG = 90.3687;

    private User $admin;

    private Restaurant $shop;

    private FoodItem $burger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 04:00:00');
        Cache::flush();
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $restaurants = app(Restaurants::class);
        $this->shop = $restaurants->open(['name' => 'Burger Barn', 'owner_name' => 'Rafi', 'phone' => '01811111111', 'password' => 'long-password-1', 'area' => 'Mirpur', 'areas' => ['Pallabi'], 'commission_value' => 10], $this->admin);
        $this->shop->update(['opens_at' => '09:00', 'closes_at' => '23:00', 'lat' => self::LAT, 'lng' => self::LNG, 'charge_mode' => 'distance', 'per_km' => 10, 'min_charge' => 30]);
        $this->burger = $restaurants->saveItem($this->shop, ['name' => 'Beef burger', 'price' => 250, 'cook_minutes' => 15]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function googleOn(): void
    {
        app(Integrations::class)->save('map:google', ['api_key' => 'GOOGLE-KEY-1234'], true, 'live', [], $this->admin);
    }

    private function matrix(int $meters, string $status = 'OK'): array
    {
        return ['status' => 'OK', 'rows' => [['elements' => [$status === 'OK' ? ['status' => 'OK', 'distance' => ['value' => $meters, 'text' => 'x'], 'duration' => ['value' => 600, 'text' => 'y']] : ['status' => $status]]]]];
    }

    private function order(): FoodOrder
    {
        return app(FoodOrders::class)->place($this->shop, null, [['item_id' => $this->burger->id, 'quantity' => 1]], 'delivery', ['name' => 'Bibi', 'phone' => '01712345678', 'area' => 'Pallabi', 'address' => 'House 4', 'lat' => self::LAT + 0.03, 'lng' => self::LNG]);
    }

    public function test_with_google_on_the_charge_follows_the_real_road_distance(): void
    {
        $this->googleOn();
        Http::fake(['maps.googleapis.com/maps/api/distancematrix/*' => Http::response($this->matrix(7300))]);

        $order = $this->order();

        $this->assertSame(7.3, (float) $order->distance_km);
        $this->assertSame(73.0, (float) $order->delivery_charge);
        $this->assertTrue((bool) $order->distance_by_road);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'distancematrix') && $r['key'] === 'GOOGLE-KEY-1234' && $r['mode'] === 'driving' && str_contains($r['origins'], '23.8069'));
    }

    public function test_without_google_the_estimate_stands_in_and_is_marked_so(): void
    {
        Http::fake();

        $order = $this->order();

        $this->assertFalse((bool) $order->distance_by_road);
        $this->assertEqualsWithDelta(RoadDistance::estimate(self::LAT, self::LNG, self::LAT + 0.03, self::LNG), (float) $order->distance_km, 0.01);
        Http::assertNothingSent();
    }

    public function test_when_google_does_not_answer_the_estimate_stands_in_and_the_order_still_goes_through(): void
    {
        $this->googleOn();
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'The Distance Matrix API is not enabled'], 200)]);

        $order = $this->order();

        $this->assertFalse((bool) $order->distance_by_road);
        $this->assertGreaterThan(0, (float) $order->distance_km);
    }

    public function test_a_route_google_cannot_find_falls_back_too(): void
    {
        $this->googleOn();
        Http::fake(['maps.googleapis.com/*' => Http::response($this->matrix(0, 'ZERO_RESULTS'))]);

        $this->assertFalse((bool) $this->order()->distance_by_road);
    }

    public function test_the_same_pair_of_places_is_asked_once_a_week(): void
    {
        $this->googleOn();
        Http::fake(['maps.googleapis.com/*' => Http::response($this->matrix(4000))]);

        $this->order();
        $this->order();

        Http::assertSentCount(1);
    }

    public function test_the_menu_page_says_whether_the_distance_is_by_road(): void
    {
        Http::fake(['maps.googleapis.com/*' => Http::response($this->matrix(4000))]);
        $lat = self::LAT + 0.03;

        $this->get("/food/r/{$this->shop->id}?lat={$lat}&lng=".self::LNG)->assertInertia(fn ($p) => $p->where('by_road', false));

        $this->googleOn();
        $this->get("/food/r/{$this->shop->id}?lat={$lat}&lng=".self::LNG)->assertInertia(fn ($p) => $p->where('by_road', true)->where('distance', 4)->where('charge', 40));
    }

    public function test_the_connections_test_button_tries_both_jobs_of_google_maps_and_says_which_failed(): void
    {
        $this->googleOn();
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'OK', 'results' => [['address_components' => [['long_name' => 'Dhaka', 'types' => ['administrative_area_level_2']]]]]]),
            'maps.googleapis.com/maps/api/distancematrix/*' => Http::response($this->matrix(5200)),
        ]);

        $good = app(Integrations::class)->test('map:google', [], $this->admin);
        $this->assertTrue($good['ok']);
        $this->assertStringContainsString('Road distance works', $good['note']);
    }

    public function test_the_test_button_says_when_the_distance_matrix_api_is_not_turned_on(): void
    {
        $this->googleOn();
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'OK', 'results' => [['address_components' => [['long_name' => 'Dhaka', 'types' => ['administrative_area_level_2']]]]]]),
            'maps.googleapis.com/maps/api/distancematrix/*' => Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'This API project is not authorized to use this API.']),
        ]);

        $bad = app(Integrations::class)->test('map:google', [], $this->admin);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('Distance Matrix API', $bad['note']);
    }

    public function test_the_owner_finds_the_restaurants_place_from_its_address(): void
    {
        $this->googleOn();
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'OK', 'results' => [['formatted_address' => 'Road 2, Mirpur 10, Dhaka', 'geometry' => ['location' => ['lat' => 23.8071, 'lng' => 90.3689]]]]])]);

        $this->actingAs($this->shop->owner)->getJson('/food/vendor/locate?address='.urlencode('House 4, Road 2, Mirpur 10, Dhaka'))
            ->assertOk()->assertJson(['lat' => 23.8071, 'lng' => 90.3689]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'geocode') && $r['components'] === 'country:BD');
    }

    public function test_an_address_that_lands_outside_bangladesh_or_nowhere_is_refused(): void
    {
        $this->googleOn();
        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'OK', 'results' => [['formatted_address' => 'Somewhere', 'geometry' => ['location' => ['lat' => 51.5, 'lng' => -0.12]]]]])]);

        $this->actingAs($this->shop->owner)->getJson('/food/vendor/locate?address='.urlencode('10 Downing Street London'))->assertStatus(422);

        Http::fake(['maps.googleapis.com/maps/api/geocode/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);
        $this->getJson('/food/vendor/locate?address='.urlencode('Nowhere at all road'))->assertStatus(422);
    }

    public function test_only_a_restaurant_owner_can_use_the_address_finder(): void
    {
        $this->googleOn();
        Http::fake();
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->getJson('/food/vendor/locate?address=House+4+Mirpur')->assertUnauthorized();
        $this->actingAs($buyer)->getJson('/food/vendor/locate?address=House+4+Mirpur')->assertForbidden();
        Http::assertNothingSent();
    }
}
