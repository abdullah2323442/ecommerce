<?php

namespace Tests\Feature\Food;

use App\Enums\AccountType;
use App\Models\FoodBase;
use App\Models\FoodFavorite;
use App\Models\FoodOrder;
use App\Models\Ingredient;
use App\Models\IngredientRequest;
use App\Models\Notice;
use App\Models\Restaurant;
use App\Models\RestaurantIngredient;
use App\Models\Role;
use App\Models\User;
use App\Services\Food\CustomFood;
use App\Services\Food\FoodOrders;
use App\Services\Food\Restaurants;
use App\Services\Stock\StockException;
use Database\Seeders\IngredientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 17: custom food — the central ingredient list, a restaurant's own prices, limits, rules, favourites. */
class CustomFoodTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Restaurant $shop;

    private User $owner;

    private Ingredient $cheddar;

    private Ingredient $bbq;

    private Ingredient $onion;

    private FoodBase $burger;

    private CustomFood $custom;

    private FoodOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 04:00:00');   // 10:00 Thursday in Dhaka
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $restaurants = app(Restaurants::class);
        $this->shop = $restaurants->open(['name' => 'Burger Barn', 'owner_name' => 'Rafi', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 10], $this->admin);
        $this->shop->update(['opens_at' => '09:00', 'closes_at' => '23:00']);
        $this->owner = $this->shop->owner;
        $this->custom = app(CustomFood::class);
        $this->orders = app(FoodOrders::class);

        $this->cheddar = $this->custom->addIngredient('Cheddar', 'চেডার', 'cheese');
        $this->bbq = $this->custom->addIngredient('Barbecue sauce', null, 'sauces');
        $this->onion = $this->custom->addIngredient('Onion', null, 'vegetables');
        foreach ([[$this->cheddar, 30], [$this->bbq, 15], [$this->onion, 10]] as [$i, $price]) {
            $this->custom->tick($this->shop, $i, $price);
        }
        $this->burger = $this->custom->saveBase($this->shop, ['kind' => 'burger', 'name' => 'Burger', 'price' => 200, 'cook_minutes' => 12]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ship(): array
    {
        return ['name' => 'Bibi', 'phone' => '01712345678', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    private function buy(array $ids, int $qty = 1, ?User $buyer = null): FoodOrder
    {
        return $this->orders->place($this->shop, $buyer, [['base_id' => $this->burger->id, 'ingredient_ids' => $ids, 'quantity' => $qty, 'note' => 'no ice']], 'delivery', $this->ship());
    }

    // ------------------------------------------------------------------ the central list

    public function test_the_list_has_no_repeats_however_they_are_spelt(): void
    {
        foreach (['cheddar', 'CHEDDAR', ' Cheddar '] as $again) {
            try {
                $this->custom->addIngredient($again, null, 'cheese');
                $this->fail('a repeat');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, Ingredient::whereRaw('lower(name) = ?', ['cheddar'])->count());
    }

    public function test_an_ingredient_needs_a_group_from_the_list(): void
    {
        $this->expectException(StockException::class);
        $this->custom->addIngredient('Mystery', null, 'stuff');
    }

    public function test_the_starter_list_is_there_and_running_it_again_adds_nothing(): void
    {
        $this->seed(IngredientSeeder::class);
        $count = Ingredient::count();
        $this->assertGreaterThan(50, $count);
        $this->assertSame(['cheese', 'meat', 'sauces', 'spices', 'vegetables'], Ingredient::where('name', '!=', 'x')->whereIn('group', ['cheese', 'meat', 'sauces', 'spices', 'vegetables'])->distinct()->orderBy('group')->pluck('group')->all());

        $this->seed(IngredientSeeder::class);
        $this->assertSame($count, Ingredient::count());
    }

    // ------------------------------------------------------------------ requests

    public function test_a_restaurant_asks_for_one_and_we_add_it_and_everyone_gets_it(): void
    {
        $other = app(Restaurants::class)->open(['name' => 'Other', 'owner_name' => 'O', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 5], $this->admin);
        $request = $this->custom->request($this->shop, 'Halloumi', 'cheese', 'Grilled');
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'ingredient_request')->count());

        $done = $this->custom->decide($request, $this->admin, true);

        $this->assertSame('added', $done->status);
        $halloumi = Ingredient::where('name', 'Halloumi')->firstOrFail();
        $this->assertSame($halloumi->id, $done->ingredient_id);
        $this->assertSame(25.0, (float) $this->custom->tick($other, $halloumi, 25)->price);   // another restaurant can tick it
    }

    public function test_a_request_can_be_turned_down_and_cannot_repeat_the_list_or_itself(): void
    {
        $request = $this->custom->request($this->shop, 'Dust', 'extras');
        $this->assertSame('declined', $this->custom->decide($request, $this->admin, false)->status);
        $this->assertNull(Ingredient::where('name', 'Dust')->first());

        foreach (['cheddar', 'Fresh basil'] as $i => $name) {
            try {
                $this->custom->request($this->shop, $name, 'extras');
                if ($i === 0) {
                    $this->fail('already in the list');
                }
            } catch (StockException) {
                $this->assertSame(0, $i);
            }
        }

        $this->expectException(StockException::class);
        $this->custom->request($this->shop, 'Fresh basil', 'extras');   // already asked
    }

    public function test_a_request_that_needs_a_group_must_get_one_and_is_decided_once(): void
    {
        $request = $this->custom->request($this->shop, 'Sumac', null);

        try {
            $this->custom->decide($request, $this->admin, true);
            $this->fail('a group is needed');
        } catch (StockException) {
        }

        $this->custom->decide($request, $this->admin, true, 'spices');
        $this->assertSame('spices', Ingredient::where('name', 'Sumac')->value('group'));
        $this->expectException(StockException::class);
        $this->custom->decide($request->fresh(), $this->admin, false);
    }

    // ------------------------------------------------------------------ what a restaurant has

    public function test_each_restaurant_sets_its_own_price_for_the_same_ingredient(): void
    {
        $other = app(Restaurants::class)->open(['name' => 'Other', 'owner_name' => 'O', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 5], $this->admin);
        $this->custom->tick($other, $this->cheddar, 45);

        $this->assertEquals([30, 45], [RestaurantIngredient::where('restaurant_id', $this->shop->id)->where('ingredient_id', $this->cheddar->id)->value('price'), RestaurantIngredient::where('restaurant_id', $other->id)->where('ingredient_id', $this->cheddar->id)->value('price')]);

        $this->custom->tick($this->shop, $this->cheddar, 35);   // ticking again changes the price, it does not repeat
        $this->assertSame(1, RestaurantIngredient::where('restaurant_id', $this->shop->id)->where('ingredient_id', $this->cheddar->id)->count());
    }

    public function test_an_ingredient_we_switched_off_cannot_be_ticked_and_is_not_orderable(): void
    {
        $this->custom->updateIngredient($this->onion, ['is_active' => false]);

        try {
            $this->buy([$this->onion->id]);
            $this->fail('switched off');
        } catch (StockException) {
        }

        $this->expectException(StockException::class);
        $this->custom->tick($this->shop, $this->onion->fresh(), 10);
    }

    public function test_unticking_also_drops_the_rules_about_it(): void
    {
        $this->custom->addRule($this->shop, $this->cheddar, $this->bbq);
        $this->custom->untick($this->shop, $this->cheddar);

        $this->assertSame(0, \App\Models\IngredientRule::count());
        $this->assertSame(0, RestaurantIngredient::where('ingredient_id', $this->cheddar->id)->count());
    }

    public function test_sold_out_today_ends_by_itself_and_a_switch_holds_until_flipped(): void
    {
        $ri = RestaurantIngredient::where('ingredient_id', $this->cheddar->id)->firstOrFail();
        $this->custom->soldOutToday($ri, true);

        try {
            $this->buy([$this->cheddar->id]);
            $this->fail('sold out');
        } catch (StockException) {
        }

        Carbon::setTestNow('2026-10-16 04:00:00');   // tomorrow
        $this->assertNotNull($this->buy([$this->cheddar->id]));

        $this->custom->setAvailable($ri->fresh(), false);
        $this->expectException(StockException::class);
        $this->buy([$this->cheddar->id]);
    }

    // ------------------------------------------------------------------ building

    public function test_a_build_is_priced_from_the_base_and_this_restaurants_prices_and_takes_longer(): void
    {
        $built = $this->custom->build($this->shop, $this->burger->id, [$this->cheddar->id, $this->bbq->id]);

        $this->assertEquals([245, 22], [$built['unit_price'], $built['minutes']]);   // 200 + 30 + 15; 12 minutes + 10 extra
    }

    public function test_the_limit_stops_a_dish_with_too_many_things(): void
    {
        $this->custom->setLimits($this->shop->fresh(), 2, 10);

        $this->assertNotNull($this->custom->build($this->shop->fresh(), $this->burger->id, [$this->cheddar->id, $this->bbq->id]));
        $this->expectException(StockException::class);
        $this->custom->build($this->shop->fresh(), $this->burger->id, [$this->cheddar->id, $this->bbq->id, $this->onion->id]);
    }

    public function test_a_pair_that_does_not_go_together_is_refused_in_either_order(): void
    {
        $this->custom->addRule($this->shop, $this->bbq, $this->cheddar);
        $this->custom->addRule($this->shop, $this->cheddar, $this->bbq);   // the same rule once, whichever way round
        $this->assertSame(1, \App\Models\IngredientRule::count());

        try {
            $this->custom->build($this->shop, $this->burger->id, [$this->bbq->id, $this->cheddar->id]);
            $this->fail('rule');
        } catch (StockException $e) {
            $this->assertStringContainsString('does not go with', $e->getMessage());
        }

        $this->assertNotNull($this->custom->build($this->shop, $this->burger->id, [$this->bbq->id, $this->onion->id]));
    }

    public function test_an_ingredient_the_restaurant_never_ticked_is_refused_and_repeats_count_once(): void
    {
        $other = $this->custom->addIngredient('Pesto', null, 'sauces');

        try {
            $this->custom->build($this->shop, $this->burger->id, [$other->id]);
            $this->fail('not ticked');
        } catch (StockException) {
        }

        $built = $this->custom->build($this->shop, $this->burger->id, [$this->cheddar->id, $this->cheddar->id]);
        $this->assertEquals(230, $built['unit_price']);
    }

    public function test_a_base_can_be_sold_out_or_belong_to_another_restaurant(): void
    {
        $this->custom->soldOutBase($this->burger, true);

        try {
            $this->custom->build($this->shop, $this->burger->id, []);
            $this->fail('sold out');
        } catch (StockException) {
        }

        $this->custom->soldOutBase($this->burger, false);
        $other = app(Restaurants::class)->open(['name' => 'Other', 'owner_name' => 'O', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 5], $this->admin);
        $this->expectException(StockException::class);
        $this->custom->build($other, $this->burger->id, []);
    }

    // ------------------------------------------------------------------ ordering

    public function test_a_custom_order_keeps_the_base_each_ingredient_and_its_price_as_ordered(): void
    {
        $o = $this->buy([$this->cheddar->id, $this->bbq->id], 2);

        $line = $o->lines()->firstOrFail();
        $this->assertEquals([490, 'no ice'], [$o->subtotal, $line->note]);
        $this->assertSame(['Cheddar', 'Barbecue sauce'], collect($line->ingredients['items'])->pluck('name')->all());

        $this->custom->tick($this->shop, $this->cheddar, 99);   // the price moves; the order does not
        $this->assertEquals(30, $line->fresh()->ingredients['items'][0]['price']);
    }

    public function test_custom_and_ordinary_dishes_can_share_an_order(): void
    {
        $fries = app(Restaurants::class)->saveItem($this->shop, ['name' => 'Fries', 'price' => 100]);

        $o = $this->orders->place($this->shop, null, [['item_id' => $fries->id, 'quantity' => 1], ['base_id' => $this->burger->id, 'ingredient_ids' => [$this->onion->id], 'quantity' => 1]], 'delivery', $this->ship());

        $this->assertEquals(310, $o->subtotal);   // 100 + 200 + 10
        $this->assertTrue($this->orders->forBuyer($o)['custom']);
    }

    public function test_the_order_is_refused_when_the_build_breaks_a_rule_or_the_limit(): void
    {
        $this->custom->addRule($this->shop, $this->cheddar, $this->onion);

        $this->expectException(StockException::class);
        $this->buy([$this->cheddar->id, $this->onion->id]);
    }

    public function test_the_minutes_suggested_to_the_owner_count_the_custom_time(): void
    {
        $o = $this->buy([$this->cheddar->id]);

        $this->assertSame(22, $this->orders->suggestMinutes($o));
    }

    public function test_the_kitchen_and_memo_get_every_ingredient_on_its_own_line(): void
    {
        $o = $this->buy([$this->cheddar->id, $this->bbq->id]);

        $this->actingAs($this->owner)->get("/food/vendor/orders/{$o->id}/print/kitchen")->assertInertia(fn (Assert $p) => $p->where('order.lines.0.ingredients.base.name', 'Burger')->where('order.lines.0.ingredients.items.1.name', 'Barbecue sauce')->where('order.lines.0.ingredients.items.1.price', 15));
    }

    // ------------------------------------------------------------------ favourites

    public function test_a_favourite_is_saved_under_a_name_and_one_tap_brings_it_back(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->custom->saveFavorite($buyer, $this->shop, 'My usual', $this->burger->id, [$this->cheddar->id, $this->bbq->id]);

        $favs = $this->custom->favorites($buyer, $this->shop);

        $this->assertSame([true, 245.0, 'My usual'], [$favs[0]['ok'], $favs[0]['price'], $favs[0]['name']]);

        $this->custom->saveFavorite($buyer, $this->shop, 'My usual', $this->burger->id, [$this->onion->id]);   // the same name changes it
        $this->assertSame(1, FoodFavorite::count());
    }

    public function test_a_favourite_with_something_sold_out_today_says_so(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->custom->saveFavorite($buyer, $this->shop, 'Cheesy', $this->burger->id, [$this->cheddar->id]);
        $this->custom->soldOutToday(RestaurantIngredient::where('ingredient_id', $this->cheddar->id)->firstOrFail(), true);

        $fav = $this->custom->favorites($buyer, $this->shop)[0];

        $this->assertFalse($fav['ok']);
        $this->assertStringContainsString('Cheddar', $fav['problem']);
    }

    public function test_a_favourite_must_be_a_valid_build_and_have_a_name(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->custom->addRule($this->shop, $this->cheddar, $this->bbq);

        foreach ([['', [$this->onion->id]], ['Bad pair', [$this->cheddar->id, $this->bbq->id]]] as [$name, $ids]) {
            try {
                $this->custom->saveFavorite($buyer, $this->shop, $name, $this->burger->id, $ids);
                $this->fail('refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_favourites_need_an_account_and_belong_to_their_owner(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $body = ['restaurant_id' => $this->shop->id, 'name' => 'Mine', 'base_id' => $this->burger->id, 'ingredient_ids' => [$this->cheddar->id]];

        $this->post('/food/favorites', $body)->assertRedirect();
        $this->assertSame(0, FoodFavorite::count());

        $this->actingAs($buyer)->post('/food/favorites', $body)->assertSessionHasNoErrors();
        $fav = FoodFavorite::firstOrFail();

        $this->actingAs(User::factory()->create())->delete("/food/favorites/{$fav->id}")->assertRedirect('/');
        $this->assertSame(1, FoodFavorite::count());
        $this->actingAs($buyer)->delete("/food/favorites/{$fav->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, FoodFavorite::count());
    }

    // ------------------------------------------------------------------ for us

    public function test_the_ingredient_that_sells_most_is_counted_on_delivered_orders_only(): void
    {
        foreach ([[2, [$this->cheddar->id, $this->bbq->id]], [1, [$this->cheddar->id]]] as [$qty, $ids]) {
            $o = $this->buy($ids, $qty);
            $o = $this->orders->confirm($o, $this->owner, 10);
            $this->orders->deliver($this->orders->out($o, $this->owner), $this->owner);
        }
        $this->orders->cancel($this->buy([$this->onion->id], 5), 'buyer');   // not a sale

        $top = $this->custom->topSelling();

        $this->assertSame([['name' => 'Cheddar', 'count' => 3], ['name' => 'Barbecue sauce', 'count' => 2]], $top->all());
    }

    // ------------------------------------------------------------------ the pages

    public function test_the_menu_page_carries_the_build_screen_data_at_this_restaurants_prices(): void
    {
        $this->custom->addRule($this->shop, $this->cheddar, $this->bbq);

        $this->get("/food/r/{$this->shop->id}")->assertInertia(fn (Assert $p) => $p->component('food/show')->has('custom.bases', 1)->has('custom.ingredients', 3)->where('custom.max', 10)->where('custom.extra', 10)->has('custom.rules', 1)->where('custom.ingredients.0.price', 30));
    }

    public function test_a_guest_orders_a_custom_dish_through_the_page(): void
    {
        $this->post("/food/r/{$this->shop->id}/order", ['mode' => 'delivery', 'lines' => [['base_id' => $this->burger->id, 'ingredient_ids' => [$this->cheddar->id], 'quantity' => 1]], 'name' => 'Bibi', 'phone' => '01712345678', 'area' => 'Mirpur', 'address' => 'House 4'])->assertSessionHasNoErrors();

        $order = FoodOrder::firstOrFail();
        $this->assertEquals(230, $order->subtotal);
        $this->get("/food/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.custom', true)->where('order.lines.0.ingredients.items.0.name', 'Cheddar'));
    }

    public function test_the_page_refuses_a_build_that_breaks_the_rules_with_the_reason(): void
    {
        $this->custom->addRule($this->shop, $this->cheddar, $this->bbq);

        $this->post("/food/r/{$this->shop->id}/order", ['mode' => 'delivery', 'lines' => [['base_id' => $this->burger->id, 'ingredient_ids' => [$this->cheddar->id, $this->bbq->id], 'quantity' => 1]], 'name' => 'Bibi', 'phone' => '01712345678', 'area' => 'Mirpur', 'address' => 'House 4'])->assertSessionHasErrors('order');
        $this->assertSame(0, FoodOrder::count());
    }

    public function test_the_owner_runs_custom_food_from_their_own_page(): void
    {
        $pesto = Ingredient::create(['name' => 'Pesto', 'group' => 'sauces']);
        $this->actingAs($this->owner)->get('/food/vendor/custom')->assertInertia(fn (Assert $p) => $p->component('food/vendor/custom')->has('catalog', 4)->has('bases', 1)->where('catalog.1.ticked', true));

        $this->post('/food/vendor/custom/tick', ['ingredient_id' => $pesto->id, 'price' => 20])->assertSessionHasNoErrors();
        $ri = RestaurantIngredient::where('ingredient_id', $pesto->id)->firstOrFail();
        $this->put("/food/vendor/custom/ri/{$ri->id}/sold-out", ['on' => true])->assertSessionHasNoErrors();
        $this->assertFalse($ri->fresh()->isOrderable());
        $this->put("/food/vendor/custom/ri/{$ri->id}/available", ['on' => false])->assertSessionHasNoErrors();

        $this->post('/food/vendor/custom/bases', ['kind' => 'pizza', 'name' => 'Pizza', 'price' => 350, 'cook_minutes' => 20])->assertSessionHasNoErrors();
        $this->post('/food/vendor/custom/rules', ['a' => $this->cheddar->id, 'b' => $this->bbq->id])->assertSessionHasNoErrors();
        $this->put('/food/vendor/custom/limits', ['max' => 6, 'extra' => 15])->assertSessionHasNoErrors();
        $this->post('/food/vendor/custom/requests', ['name' => 'Truffle oil', 'group' => 'sauces'])->assertSessionHasNoErrors();
        $this->delete("/food/vendor/custom/ingredients/{$pesto->id}")->assertSessionHasNoErrors();

        $this->assertEquals([6, 15, 2, 1], [$this->shop->fresh()->max_ingredients, $this->shop->fresh()->custom_extra_minutes, FoodBase::count(), IngredientRequest::count()]);
        $this->post('/food/vendor/custom/bases', ['kind' => 'taco', 'price' => 100])->assertSessionHasErrors('kind');
    }

    public function test_another_restaurant_cannot_touch_these_ingredients_bases_or_rules(): void
    {
        $rival = app(Restaurants::class)->open(['name' => 'Rival', 'owner_name' => 'R', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 5], $this->admin)->owner;
        $ri = RestaurantIngredient::firstOrFail();
        $this->custom->addRule($this->shop, $this->cheddar, $this->bbq);
        $rule = \App\Models\IngredientRule::firstOrFail();

        $this->actingAs($rival)->put("/food/vendor/custom/ri/{$ri->id}/available", ['on' => false])->assertRedirect('/');
        $this->actingAs($rival)->put("/food/vendor/custom/bases/{$this->burger->id}/sold-out", ['on' => true])->assertRedirect('/');
        $this->actingAs($rival)->delete("/food/vendor/custom/rules/{$rule->id}")->assertRedirect('/');
        $this->actingAs($rival)->post("/food/vendor/custom/bases/{$this->burger->id}", ['kind' => 'burger', 'price' => 1])->assertRedirect('/');

        $this->assertTrue($ri->fresh()->available);
        $this->assertSame(1, \App\Models\IngredientRule::count());
        $this->assertEquals(200, $this->burger->fresh()->price);
    }

    public function test_we_keep_the_list_read_requests_and_see_what_sells(): void
    {
        $request = $this->custom->request($this->shop, 'Halloumi', 'cheese');

        $this->actingAs($this->admin)->get('/admin/food-ingredients')->assertInertia(fn (Assert $p) => $p->component('admin/food-ingredients')->has('ingredients', 3)->has('requests', 1)->has('top')->where('groups.0', 'meat'));
        $this->post('/admin/food-ingredients', ['name' => 'Beef patty', 'name_bn' => 'গরুর প্যাটি', 'group' => 'meat'])->assertSessionHasNoErrors();
        $this->post('/admin/food-ingredients', ['name' => 'beef PATTY', 'group' => 'meat'])->assertSessionHasErrors('food');
        $this->post("/admin/food-ingredient-requests/{$request->id}", ['decision' => 'add'])->assertSessionHasNoErrors();

        $this->assertNotNull(Ingredient::where('name', 'Halloumi')->first());
        $patty = Ingredient::where('name', 'Beef patty')->firstOrFail();
        $this->put("/admin/food-ingredients/{$patty->id}", ['group' => 'meat', 'is_active' => false, 'name_bn' => 'গরুর প্যাটি'])->assertSessionHasNoErrors();
        $this->assertFalse($patty->fresh()->is_active);
    }

    public function test_only_the_owner_keeps_the_list_and_a_restaurant_cannot_type_a_new_name(): void
    {
        $this->actingAs($this->staff)->post('/admin/food-ingredients', ['name' => 'Nope', 'group' => 'meat'])->assertRedirect('/');
        $this->actingAs($this->owner)->post('/admin/food-ingredients', ['name' => 'Nope', 'group' => 'meat'])->assertRedirect('/');
        $this->assertNull(Ingredient::where('name', 'Nope')->first());

        // the restaurant's only way in is to ask
        $this->actingAs($this->owner)->post('/food/vendor/custom/tick', ['ingredient_id' => 99999, 'price' => 5])->assertSessionHasErrors('ingredient_id');
    }
}
