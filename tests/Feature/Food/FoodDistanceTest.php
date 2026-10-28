<?php

namespace Tests\Feature\Food;

use App\Enums\AccountType;
use App\Models\FoodItem;
use App\Models\FoodOrder;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Services\Food\FoodOrders;
use App\Services\Food\Restaurants;
use App\Services\Food\RoadDistance;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 17, delivery charge: by the kilometre, a longest distance and a least charge. Distance is straight line times a road factor until a map service is connected. */
class FoodDistanceTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = 23.8069;

    private const LNG = 90.3687;

    private Restaurant $shop;

    private FoodItem $burger;

    private FoodOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 04:00:00');
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->orders = app(FoodOrders::class);
        $restaurants = app(Restaurants::class);
        $this->shop = $restaurants->open(['name' => 'Burger Barn', 'owner_name' => 'Rafi', 'phone' => '01811111111', 'password' => 'long-password-1', 'area' => 'Mirpur', 'areas' => ['Pallabi'], 'commission_value' => 10], $admin);
        $this->shop->update(['opens_at' => '09:00', 'closes_at' => '23:00', 'lat' => self::LAT, 'lng' => self::LNG]);
        $this->burger = $restaurants->saveItem($this->shop, ['name' => 'Beef burger', 'price' => 250, 'cook_minutes' => 15]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A buyer this many straight-line kilometres north of the restaurant (one degree of latitude is about 111.2 km). */
    private function north(float $km): array
    {
        return ['lat' => self::LAT + $km / 111.195, 'lng' => self::LNG];
    }

    private function ship(array $where = []): array
    {
        return ['name' => 'Bibi', 'phone' => '01712345678', 'area' => 'Pallabi', 'address' => 'House 4'] + $where;
    }

    private function order(array $where = []): FoodOrder
    {
        return $this->orders->place($this->shop, null, [['item_id' => $this->burger->id, 'quantity' => 1]], 'delivery', $this->ship($where));
    }

    public function test_the_distance_is_the_straight_line_times_the_road_factor(): void
    {
        $this->assertEqualsWithDelta(5.0, RoadDistance::straight(self::LAT, self::LNG, ...array_values($this->north(5))), 0.01);
        $this->assertEqualsWithDelta(6.5, RoadDistance::estimate(self::LAT, self::LNG, ...array_values($this->north(5))), 0.01);

        Setting::write('food.road_factor', 1.5);
        $this->assertEqualsWithDelta(7.5, RoadDistance::estimate(self::LAT, self::LNG, ...array_values($this->north(5))), 0.01);
    }

    public function test_by_the_kilometre_the_charge_follows_the_distance(): void
    {
        $this->shop->update(['charge_mode' => 'distance', 'per_km' => 10, 'min_charge' => 0]);

        $order = $this->order($this->north(5)); // 6.5 km by road

        $this->assertSame(65.0, (float) $order->delivery_charge);
        $this->assertSame(6.5, (float) $order->distance_km);
        $this->assertFalse((bool) $order->charge_open);
    }

    public function test_however_close_the_charge_is_never_below_the_least(): void
    {
        $this->shop->update(['charge_mode' => 'distance', 'per_km' => 10, 'min_charge' => 30]);

        $this->assertSame(30.0, (float) $this->order($this->north(0.4))->delivery_charge);
    }

    public function test_beyond_the_longest_distance_there_is_no_order(): void
    {
        $this->shop->update(['charge_mode' => 'distance', 'per_km' => 10, 'max_km' => 5]);

        try {
            $this->order($this->north(5)); // 6.5 km by road
            $this->fail('A buyer beyond the longest distance was served.');
        } catch (StockException $e) {
            $this->assertStringContainsString('delivers up to 5 km', $e->getMessage());
        }

        $this->assertSame(0, FoodOrder::count());
        $this->assertSame(1, $this->order($this->north(3))->id); // 3.9 km is fine
    }

    public function test_the_longest_distance_also_holds_for_area_prices(): void
    {
        $this->shop->update(['charge_mode' => 'area', 'max_km' => 4]);

        $this->expectException(StockException::class);
        $this->order($this->north(4)); // 5.2 km
    }

    public function test_a_buyer_who_does_not_share_a_position_is_asked_to_where_it_matters(): void
    {
        $this->shop->update(['charge_mode' => 'distance', 'per_km' => 10]);

        $this->expectExceptionMessage('Share your location');
        $this->order();
    }

    public function test_a_restaurant_with_no_distance_rules_never_asks_for_a_position(): void
    {
        $order = $this->order();

        $this->assertNull($order->distance_km);
        $this->assertSame(60.0, (float) $order->delivery_charge); // outside its own area, as before
    }

    public function test_the_buyers_position_is_not_kept(): void
    {
        $this->shop->update(['charge_mode' => 'distance', 'per_km' => 10]);

        $order = $this->order($this->north(2));

        $this->assertStringNotContainsString((string) round(self::LAT + 2 / 111.195, 3), json_encode($order->fresh()->toArray()));
        $this->assertArrayNotHasKey('lat', $order->ship_to);
    }

    public function test_the_menu_page_previews_the_distance_and_the_charge_when_the_phone_shares_a_position(): void
    {
        $this->shop->update(['charge_mode' => 'distance', 'per_km' => 10, 'max_km' => 5]);
        $near = $this->north(3);
        $far = $this->north(5);

        $this->get("/food/r/{$this->shop->id}")->assertInertia(fn (Assert $p) => $p->where('charge', null)->where('distance', null)->where('restaurant.needs_location', true)->where('restaurant.by_distance', true));
        $this->get("/food/r/{$this->shop->id}?lat={$near['lat']}&lng={$near['lng']}")->assertInertia(fn (Assert $p) => $p->where('distance', 3.9)->where('charge', 39)->where('too_far', false));
        $this->get("/food/r/{$this->shop->id}?lat={$far['lat']}&lng={$far['lng']}")->assertInertia(fn (Assert $p) => $p->where('too_far', true));
    }

    public function test_an_order_from_the_page_carries_the_position_and_is_refused_when_too_far(): void
    {
        $this->shop->update(['charge_mode' => 'distance', 'per_km' => 10, 'max_km' => 5]);
        $body = ['mode' => 'delivery', 'lines' => [['item_id' => $this->burger->id, 'quantity' => 1]], 'name' => 'Bibi', 'phone' => '01712345678', 'area' => 'Pallabi', 'address' => 'House 4'];

        $this->post("/food/r/{$this->shop->id}/order", $body + $this->north(5))->assertSessionHasErrors('order');
        $this->post("/food/r/{$this->shop->id}/order", $body + $this->north(3))->assertSessionHasNoErrors();

        $this->assertSame(39.0, (float) FoodOrder::firstOrFail()->delivery_charge);
    }

    public function test_the_owner_must_say_where_the_restaurant_is_before_charging_by_the_kilometre(): void
    {
        $this->shop->update(['lat' => null, 'lng' => null]);
        $owner = $this->shop->owner;

        $this->actingAs($owner)->post('/food/vendor/settings', ['charge_mode' => 'distance', 'per_km' => 8])->assertSessionHasErrors();
        $this->post('/food/vendor/settings', ['max_km' => 6])->assertSessionHasErrors();

        $this->post('/food/vendor/settings', ['charge_mode' => 'distance', 'per_km' => 8, 'max_km' => 6, 'lat' => 23.8, 'lng' => 90.4])->assertSessionHasNoErrors();
        $fresh = $this->shop->fresh();
        $this->assertSame('distance', $fresh->charge_mode);
        $this->assertEquals(8, $fresh->per_km);
        $this->assertEquals(6, $fresh->max_km);
    }

    public function test_charging_by_the_kilometre_needs_a_price_for_each_kilometre(): void
    {
        $this->actingAs($this->shop->owner)->post('/food/vendor/settings', ['charge_mode' => 'distance', 'per_km' => 0])->assertSessionHasErrors();
    }

    public function test_the_owner_can_clear_the_longest_distance(): void
    {
        $this->shop->update(['max_km' => 5]);

        $this->actingAs($this->shop->owner)->post('/food/vendor/settings', ['max_km' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->shop->fresh()->max_km);
    }
}
