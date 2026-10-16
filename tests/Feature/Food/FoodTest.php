<?php

namespace Tests\Feature\Food;

use App\Enums\AccountType;
use App\Models\FoodItem;
use App\Models\FoodOrder;
use App\Models\Notice;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Services\Food\FoodOrders;
use App\Services\Food\Restaurants;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 17: restaurants, menu, hours, ordering, the steps, advance, cancelling, commission, complaints. */
class FoodTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Restaurant $shop;

    private User $owner;

    private FoodItem $burger;

    private FoodItem $fries;

    private Restaurants $restaurants;

    private FoodOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        // 10:00 on a Thursday in Dhaka (04:00 UTC).
        Carbon::setTestNow('2026-10-15 04:00:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->restaurants = app(Restaurants::class);
        $this->orders = app(FoodOrders::class);
        $this->shop = $this->restaurants->open([
            'name' => 'Burger Barn', 'owner_name' => 'Rafi', 'phone' => '01811111111', 'password' => 'long-password-1', 'area' => 'Mirpur', 'areas' => ['Pallabi', 'Kafrul'],
            'commission_kind' => 'percent', 'commission_value' => 10,
        ], $this->admin);
        $this->owner = $this->shop->owner;
        $this->shop->update(['opens_at' => '09:00', 'closes_at' => '23:00']);
        $this->burger = $this->restaurants->saveItem($this->shop, ['name' => 'Beef burger', 'price' => 250, 'cook_minutes' => 15]);
        $this->fries = $this->restaurants->saveItem($this->shop, ['name' => 'Fries', 'price' => 100, 'cook_minutes' => 5, 'ready_made' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ship(string $area = 'Mirpur'): array
    {
        return ['name' => 'Bibi', 'phone' => '01712345678', 'area' => $area, 'address' => 'House 4, Road 2'];
    }

    private function place(array $lines = null, string $mode = 'delivery', string $area = 'Mirpur', ?User $buyer = null): FoodOrder
    {
        return $this->orders->place($this->shop, $buyer, $lines ?? [['item_id' => $this->burger->id, 'quantity' => 2], ['item_id' => $this->fries->id, 'quantity' => 1]], $mode, $this->ship($area));
    }

    // ------------------------------------------------------------------ account

    public function test_opening_a_restaurant_makes_the_owner_a_food_account_with_our_commission(): void
    {
        $this->assertSame(AccountType::FoodVendor, $this->owner->account_type);
        $this->assertSame(['Mirpur', 'Pallabi', 'Kafrul'], $this->shop->areas);
        $this->assertEquals([10, 'percent'], [$this->shop->commission_value, $this->shop->commission_kind]);
    }

    public function test_the_commission_is_set_by_hand_and_bounded(): void
    {
        $this->restaurants->setCommission($this->shop, 'taka', 15, $this->admin);
        $this->assertEquals(15, $this->shop->fresh()->commission_value);

        $this->expectException(StockException::class);
        $this->restaurants->setCommission($this->shop, 'percent', 95, $this->admin);
    }

    // ------------------------------------------------------------------ browsing

    public function test_only_restaurants_that_deliver_to_the_area_are_shown_and_open_ones_first(): void
    {
        $far = $this->restaurants->open(['name' => 'Aardvark Grill', 'owner_name' => 'Z', 'password' => 'long-password-1', 'area' => 'Gulshan', 'commission_value' => 5], $this->admin);
        $far->update(['areas' => ['Gulshan', 'Pallabi'], 'opens_at' => '09:00', 'closes_at' => '23:00']);
        $near = $this->restaurants->near('pallabi');
        $this->assertSame(['Aardvark Grill', 'Burger Barn'], $near->pluck('name')->all());

        $this->restaurants->setOpen($far, false);   // closed ones come after the open ones
        $this->assertSame(['Burger Barn', 'Aardvark Grill'], $this->restaurants->near('Pallabi')->pluck('name')->all());
        $this->assertCount(0, $this->restaurants->near('Dhanmondi'));
    }

    public function test_a_suspended_restaurant_is_not_listed(): void
    {
        $this->restaurants->suspend($this->shop, true, $this->admin);

        $this->assertCount(0, $this->restaurants->near('Mirpur'));
        $this->assertFalse($this->restaurants->isOpenNow($this->shop->fresh()));
    }

    // ------------------------------------------------------------------ hours

    public function test_it_closes_by_itself_by_the_hours_and_the_switch_and_closed_days(): void
    {
        $this->assertTrue($this->restaurants->isOpenNow($this->shop));

        Carbon::setTestNow('2026-10-15 17:30:00');   // 23:30 in Dhaka, after closing
        $this->assertFalse($this->restaurants->isOpenNow($this->shop));

        Carbon::setTestNow('2026-10-15 04:00:00');
        $this->restaurants->setOpen($this->shop, false);
        $this->assertFalse($this->restaurants->isOpenNow($this->shop->fresh()));

        $this->restaurants->setOpen($this->shop, true);
        $this->shop->update(['closed_days' => [4]]);   // Thursday
        $this->assertFalse($this->restaurants->isOpenNow($this->shop->fresh()));
    }

    public function test_hours_that_run_past_midnight_work(): void
    {
        $this->shop->update(['opens_at' => '18:00', 'closes_at' => '02:00']);

        Carbon::setTestNow('2026-10-15 19:00:00');   // 01:00 Dhaka
        $this->assertTrue($this->restaurants->isOpenNow($this->shop->fresh()));
        Carbon::setTestNow('2026-10-15 09:00:00');   // 15:00 Dhaka
        $this->assertFalse($this->restaurants->isOpenNow($this->shop->fresh()));
    }

    public function test_a_closed_restaurant_takes_no_orders(): void
    {
        $this->restaurants->setOpen($this->shop, false);

        $this->expectException(StockException::class);
        $this->place();
    }

    // ------------------------------------------------------------------ the menu

    public function test_sold_out_today_ends_by_itself_tomorrow(): void
    {
        $this->restaurants->soldOutToday($this->burger, true);
        $this->assertCount(1, $this->restaurants->menu($this->shop->fresh()));

        try {
            $this->place([['item_id' => $this->burger->id, 'quantity' => 1]]);
            $this->fail('sold out');
        } catch (StockException) {
        }

        Carbon::setTestNow('2026-10-16 04:00:00');   // tomorrow
        $this->assertCount(2, $this->restaurants->menu($this->shop->fresh()));
    }

    public function test_a_dish_can_be_switched_off_and_on_any_time(): void
    {
        $this->restaurants->setAvailable($this->fries, false);
        $this->assertSame(['Beef burger'], $this->restaurants->menu($this->shop->fresh())->pluck('name')->all());

        $this->restaurants->setAvailable($this->fries, true);
        $this->assertCount(2, $this->restaurants->menu($this->shop->fresh()));
    }

    public function test_a_dish_needs_a_name_and_a_price(): void
    {
        foreach ([['name' => 'x', 'price' => 10], ['name' => 'Pizza', 'price' => 0], ['name' => 'Pizza', 'price' => 'free']] as $bad) {
            try {
                $this->restaurants->saveItem($this->shop, $bad);
                $this->fail('refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    // ------------------------------------------------------------------ ordering

    public function test_an_order_is_priced_and_the_owner_is_told(): void
    {
        $o = $this->place();

        $this->assertEquals([600, 30, 630, 'pending'], [$o->subtotal, $o->delivery_charge, $o->total, $o->status]);   // 2 × 250 + 100, inside the area: 30
        $this->assertSame(1, Notice::where('user_id', $this->owner->id)->where('kind', 'food_order_new')->count());
        $this->assertSame('Beef burger', $o->lines()->first()->name);
    }

    public function test_the_charge_is_by_area_and_never_below_the_minimum(): void
    {
        $this->shop->update(['charge_inside' => 20, 'charge_outside' => 60, 'min_charge' => 30]);

        $this->assertEquals(30, $this->place(null, 'delivery', 'Mirpur')->delivery_charge);    // 20 is below the least
        $this->assertEquals(60, $this->place(null, 'delivery', 'Pallabi')->delivery_charge);
    }

    public function test_a_manual_charge_is_left_open_for_the_owner(): void
    {
        $this->shop->update(['charge_mode' => 'manual']);

        $o = $this->place();
        $this->assertSame([0.0, true], [(float) $o->delivery_charge, $o->charge_open]);

        $o = $this->orders->confirm($o, $this->owner, 20, 45);
        $this->assertEquals([45, 645, false], [$o->delivery_charge, $o->total, $o->charge_open]);
    }

    public function test_the_manual_charge_has_to_be_written_when_confirming(): void
    {
        $this->shop->update(['charge_mode' => 'manual']);
        $o = $this->place();

        $this->expectException(StockException::class);
        $this->orders->confirm($o, $this->owner, 20);
    }

    public function test_an_area_it_does_not_deliver_to_is_refused(): void
    {
        $this->expectException(StockException::class);
        $this->place(null, 'delivery', 'Dhanmondi');
    }

    public function test_eating_there_needs_no_address_or_charge_and_can_be_switched_off(): void
    {
        $o = $this->orders->place($this->shop, null, [['item_id' => $this->burger->id, 'quantity' => 1]], 'eat_there', ['name' => 'Bibi', 'phone' => '01712345678'], 'in 30 minutes');
        $this->assertEquals([0, 250, 'in 30 minutes'], [$o->delivery_charge, $o->total, $o->eat_time]);

        $this->shop->update(['eat_there' => false]);
        $this->expectException(StockException::class);
        $this->orders->place($this->shop, null, [['item_id' => $this->burger->id, 'quantity' => 1]], 'eat_there', ['name' => 'Bibi', 'phone' => '01712345678']);
    }

    public function test_quantities_and_dishes_are_checked(): void
    {
        $other = $this->restaurants->open(['name' => 'Other', 'owner_name' => 'O', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 5], $this->admin);
        $foreign = $this->restaurants->saveItem($other, ['name' => 'Their dish', 'price' => 50]);

        foreach ([[['item_id' => $this->burger->id, 'quantity' => 0]], [['item_id' => $this->burger->id, 'quantity' => 51]], [['item_id' => $foreign->id, 'quantity' => 1]], []] as $lines) {
            try {
                $this->place($lines);
                $this->fail('refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    // ------------------------------------------------------------------ advance

    public function test_a_big_order_needs_an_advance_before_it_is_confirmed(): void
    {
        $this->shop->update(['advance_over' => 500, 'advance_percent' => 50]);

        $big = $this->place();
        $small = $this->place([['item_id' => $this->fries->id, 'quantity' => 1]]);
        $this->assertEquals([315, 0], [$big->advance_due, $small->advance_due]);   // 50% of 630

        try {
            $this->orders->confirm($big, $this->owner, 20);
            $this->fail('the advance is not in');
        } catch (StockException) {
        }

        $this->orders->receiveAdvance($big, $this->owner, 315);
        $this->assertSame('confirmed', $this->orders->confirm($big->fresh(), $this->owner, 20)->status);
    }

    // ------------------------------------------------------------------ the steps

    public function test_confirming_sets_the_timer_and_tells_the_buyer(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $o = $this->place(null, 'delivery', 'Mirpur', $buyer);

        $o = $this->orders->confirm($o, $this->owner, 25);

        $this->assertSame(['confirmed', 25], [$o->status, $o->cook_minutes]);
        $this->assertSame(25 * 60, $this->orders->forBuyer($o)['seconds_left']);
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'food_update')->count());

        Carbon::setTestNow(now()->addMinutes(10));
        $this->assertSame(15 * 60, $this->orders->forBuyer($o->fresh())['seconds_left']);
    }

    public function test_a_delivery_goes_packing_out_delivered_and_the_commission_counts_then(): void
    {
        $o = $this->orders->confirm($this->place(), $this->owner, 20);
        $this->assertEquals(0, $o->commission);

        $o = $this->orders->packing($o, $this->owner);
        $o = $this->orders->out($o, $this->owner);
        $o = $this->orders->deliver($o, $this->owner);

        $this->assertSame('delivered', $o->status);
        $this->assertEquals(60, $o->commission);   // 10% of the food, 600; the delivery charge is not ours
    }

    public function test_eating_there_goes_ready_then_served_and_cannot_go_out(): void
    {
        $o = $this->orders->place($this->shop, null, [['item_id' => $this->burger->id, 'quantity' => 1]], 'eat_there', ['name' => 'Bibi', 'phone' => '01712345678']);
        $o = $this->orders->confirm($o, $this->owner, 15);

        try {
            $this->orders->out($o, $this->owner);
            $this->fail('no delivery for eating there');
        } catch (StockException) {
        }

        $o = $this->orders->ready($o, $this->owner);
        $this->assertSame('delivered', $this->orders->deliver($o, $this->owner)->status);
    }

    public function test_a_per_order_commission_in_taka(): void
    {
        $this->restaurants->setCommission($this->shop, 'taka', 25, $this->admin);
        $o = $this->orders->deliver($this->orders->out($this->orders->confirm($this->place(), $this->owner, 10), $this->owner), $this->owner);

        $this->assertEquals(25, $o->commission);
    }

    public function test_steps_cannot_be_skipped_or_done_by_someone_else(): void
    {
        $o = $this->place();
        $stranger = User::factory()->create(['account_type' => AccountType::FoodVendor]);

        foreach ([fn () => $this->orders->packing($o, $this->owner), fn () => $this->orders->deliver($o, $this->owner), fn () => $this->orders->confirm($o, $stranger, 10)] as $try) {
            try {
                $try();
                $this->fail('refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    // ------------------------------------------------------------------ cancelling

    public function test_cancelled_before_confirming_the_advance_comes_back_and_after_it_does_not(): void
    {
        $this->shop->update(['advance_over' => 100, 'advance_percent' => 50]);

        $early = $this->place();
        $this->orders->receiveAdvance($early, $this->owner, 315);
        $this->assertEquals(315, $this->orders->cancel($early->fresh(), 'buyer')->refund_amount);

        $late = $this->place();
        $this->orders->receiveAdvance($late, $this->owner, 315);
        $this->orders->confirm($late->fresh(), $this->owner, 20);
        $done = $this->orders->cancel($late->fresh(), 'buyer');
        $this->assertEquals([0, 0], [$done->refund_amount, $done->commission]);   // cooking had started, and no commission on a cancelled sale
    }

    public function test_if_the_restaurant_cancels_the_advance_always_comes_back(): void
    {
        $this->shop->update(['advance_over' => 100, 'advance_percent' => 50]);
        $o = $this->place();
        $this->orders->receiveAdvance($o, $this->owner, 315);
        $this->orders->confirm($o->fresh(), $this->owner, 20);

        $this->assertEquals(315, $this->orders->cancel($o->fresh(), 'owner', 'We ran out', $this->owner)->refund_amount);
    }

    public function test_nothing_can_be_cancelled_once_it_is_out(): void
    {
        $o = $this->orders->out($this->orders->confirm($this->place(), $this->owner, 10), $this->owner);

        $this->expectException(StockException::class);
        $this->orders->cancel($o, 'buyer');
    }

    public function test_a_restaurant_that_never_confirms_asks_the_buyer_once(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $o = $this->place(null, 'delivery', 'Mirpur', $buyer);
        $this->assertFalse($this->orders->forBuyer($o)['ask_cancel']);
        $this->assertSame(0, $this->orders->nudgeUnconfirmed());

        Carbon::setTestNow(now()->addMinutes(11));
        $this->assertTrue($this->orders->forBuyer($o->fresh())['ask_cancel']);
        $this->assertSame(1, $this->orders->nudgeUnconfirmed());
        $this->assertSame(0, $this->orders->nudgeUnconfirmed());
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'food_unconfirmed')->count());
    }

    // ------------------------------------------------------------------ complaints

    public function test_a_complaint_needs_a_photo_and_goes_to_the_restaurant_and_to_us(): void
    {
        $o = $this->orders->deliver($this->orders->out($this->orders->confirm($this->place(), $this->owner, 10), $this->owner), $this->owner);

        try {
            $this->orders->complain($o, 'It was cold and sour', null);
            $this->fail('a photo is needed');
        } catch (StockException) {
        }

        $o = $this->orders->complain($o->fresh(), 'It was cold and sour', UploadedFile::fake()->image('food.jpg'));

        $this->assertNotNull($o->complaint_photo);
        $this->assertSame(1, Notice::where('user_id', $this->owner->id)->where('kind', 'food_complaint')->count());
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'food_complaint')->count());
        $this->assertSame('We will make it right', $this->orders->reply($o, $this->owner, 'We will make it right')->complaint_reply);
    }

    public function test_only_a_delivered_order_can_be_complained_about_once(): void
    {
        $o = $this->place();

        $this->expectException(StockException::class);
        $this->orders->complain($o, 'Bad', UploadedFile::fake()->image('a.jpg'));
    }

    // ------------------------------------------------------------------ money

    public function test_sales_today_and_this_month_and_what_is_left_after_our_commission(): void
    {
        $deliver = fn () => $this->orders->deliver($this->orders->out($this->orders->confirm($this->place(), $this->owner, 10), $this->owner), $this->owner);
        $deliver();
        $deliver();
        $cancelled = $this->orders->cancel($this->place(), 'buyer');

        $sales = $this->restaurants->sales($this->shop);

        $this->assertEquals([2, 1200, 120, 1080], [$sales['month']['orders'], $sales['month']['sales'], $sales['month']['commission'], $sales['month']['net']]);
        $this->assertSame(2, $sales['today']['orders']);
        $this->assertSame('cancelled', $cancelled->status);   // not in the sales

        Carbon::setTestNow('2026-11-15 04:00:00');
        $this->assertSame(0, $this->restaurants->sales($this->shop)['month']['orders']);
        $this->assertSame(2, $this->restaurants->sales($this->shop)['all']['orders']);
    }

    // ------------------------------------------------------------------ the pages

    public function test_anyone_can_browse_and_a_guest_can_order_and_follow_it(): void
    {
        $this->get('/food?area=Mirpur')->assertInertia(fn (Assert $p) => $p->component('food/index')->where('area', 'Mirpur')->has('restaurants', 1)->where('restaurants.0.open', true));
        $this->get("/food/r/{$this->shop->id}")->assertInertia(fn (Assert $p) => $p->component('food/show')->has('items', 2)->where('serves', true)->where('charge', 30));

        $this->post("/food/r/{$this->shop->id}/order", ['mode' => 'delivery', 'lines' => [['item_id' => $this->burger->id, 'quantity' => 2]], 'name' => 'Bibi', 'phone' => '+880 1712-345678', 'area' => 'Mirpur', 'address' => 'House 4'])->assertSessionHasNoErrors();
        $order = FoodOrder::firstOrFail();

        $this->get("/food/orders/{$order->public_token}")->assertInertia(function (Assert $p) {
            $p->component('food/order')->where('order.status', 'pending')->where('order.total', 530);
            $this->assertStringNotContainsString('commission', json_encode($p->toArray()['props']['order']));
        });
    }

    public function test_a_bad_phone_is_refused(): void
    {
        $this->post("/food/r/{$this->shop->id}/order", ['mode' => 'delivery', 'lines' => [['item_id' => $this->burger->id, 'quantity' => 1]], 'name' => 'Bibi', 'phone' => '123', 'area' => 'Mirpur', 'address' => 'House 4'])->assertSessionHasErrors('phone');
        $this->assertSame(0, FoodOrder::count());
    }

    public function test_a_suspended_restaurant_is_not_found_but_its_orders_can_be_finished(): void
    {
        $o = $this->orders->confirm($this->place(), $this->owner, 10);
        $this->restaurants->suspend($this->shop, true, $this->admin);

        $this->get("/food/r/{$this->shop->id}")->assertRedirect('/');
        $this->assertSame('out', $this->orders->out($o, $this->owner)->status);   // old ones must be completed
    }

    public function test_the_buyer_cancels_and_complains_from_the_page(): void
    {
        $o = $this->place();
        $this->post("/food/orders/{$o->public_token}/cancel", ['reason' => 'Changed my mind'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $o->fresh()->status);

        $d = $this->orders->deliver($this->orders->out($this->orders->confirm($this->place(), $this->owner, 10), $this->owner), $this->owner);
        $this->post("/food/orders/{$d->public_token}/complain", ['text' => 'Cold'])->assertSessionHasErrors('photo');
        $this->post("/food/orders/{$d->public_token}/complain", ['text' => 'Cold', 'photo' => UploadedFile::fake()->image('f.jpg')])->assertSessionHasNoErrors();
        $this->get("/food/orders/{$d->public_token}/photo")->assertOk();
    }

    public function test_the_owner_runs_the_restaurant_from_their_own_screen(): void
    {
        $o = $this->place();

        $this->actingAs($this->owner)->get('/food/vendor')->assertInertia(fn (Assert $p) => $p->component('food/vendor/index')->has('orders', 1)->has('items', 2)->where('restaurant.commission_value', 10)->has('sales'));
        $this->post("/food/vendor/orders/{$o->id}/confirm", ['minutes' => 20])->assertSessionHasNoErrors();
        $this->post("/food/vendor/orders/{$o->id}/packing")->assertSessionHasNoErrors();
        $this->post("/food/vendor/orders/{$o->id}/out")->assertSessionHasNoErrors();
        $this->get('/food/vendor')->assertInertia(fn (Assert $p) => $p->has('together', 0));
        $this->post("/food/vendor/orders/{$o->id}/deliver")->assertSessionHasNoErrors();
        $this->assertSame('delivered', $o->fresh()->status);

        $this->put('/food/vendor/open', ['open' => false])->assertSessionHasNoErrors();
        $this->put("/food/vendor/items/{$this->fries->id}/sold-out", ['on' => true])->assertSessionHasNoErrors();
        $this->assertFalse($this->shop->fresh()->is_open);
        $this->assertFalse($this->fries->fresh()->isOrderable());

        $this->post('/food/vendor/items', ['name' => 'Wrap', 'price' => 180, 'cook_minutes' => 10])->assertSessionHasNoErrors();
        $this->post('/food/vendor/settings', ['opens_at' => '11:00', 'closes_at' => '23:30', 'advance_over' => 800, 'advance_percent' => 40, 'closed_days' => [5]])->assertSessionHasNoErrors();
        $this->assertEquals(['11:00', 40, [5]], [$this->shop->fresh()->opens_at, $this->shop->fresh()->advance_percent, $this->shop->fresh()->closed_days]);
        $this->post('/food/vendor/settings', ['opens_at' => 'late'])->assertSessionHasErrors('vendor');
    }

    public function test_two_orders_to_nearby_houses_show_together(): void
    {
        $a = $this->orders->packing($this->orders->confirm($this->place(), $this->owner, 10), $this->owner);
        $b = $this->orders->packing($this->orders->confirm($this->place(), $this->owner, 10), $this->owner);

        $this->actingAs($this->owner)->get('/food/vendor')->assertInertia(fn (Assert $p) => $p->has('together', 1)->where('together.0.area', 'mirpur')->has('together.0.numbers', 2));
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_print_the_memo_in_the_restaurants_name_and_the_kitchen_ticket(): void
    {
        $o = $this->place();

        $this->actingAs($this->owner)->get("/food/vendor/orders/{$o->id}/print/memo")->assertInertia(fn (Assert $p) => $p->component('food/vendor/print')->where('kind', 'memo')->where('restaurant.name', 'Burger Barn')->where('brand', config('brand.name')));
        $this->get("/food/vendor/orders/{$o->id}/print/kitchen")->assertInertia(fn (Assert $p) => $p->where('kind', 'kitchen')->has('order.lines', 2));
        $this->get("/food/vendor/orders/{$o->id}/print/other")->assertRedirect('/');
    }

    public function test_another_restaurant_and_other_accounts_cannot_touch_the_order(): void
    {
        $o = $this->place();
        $rival = $this->restaurants->open(['name' => 'Rival', 'owner_name' => 'R', 'password' => 'long-password-1', 'area' => 'Mirpur', 'commission_value' => 5], $this->admin)->owner;

        $this->actingAs($rival)->post("/food/vendor/orders/{$o->id}/confirm", ['minutes' => 10])->assertRedirect('/');
        $this->actingAs($rival)->get("/food/vendor/orders/{$o->id}/print/memo")->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/food/vendor')->assertRedirect('/');
        $this->actingAs($this->staff)->get('/food/vendor')->assertRedirect('/');
        $this->assertSame('pending', $o->fresh()->status);
    }

    public function test_we_open_restaurants_set_commission_and_suspend_from_the_admin_page(): void
    {
        $this->actingAs($this->admin)->post('/admin/food', ['name' => 'Wrap World', 'owner_name' => 'Nila', 'password' => 'long-password-1', 'area' => 'Uttara', 'areas' => ['Uttara', 'Tongi'], 'commission_kind' => 'percent', 'commission_value' => 8])->assertSessionHasNoErrors();
        $r = Restaurant::where('name', 'Wrap World')->firstOrFail();

        $this->get('/admin/food')->assertInertia(fn (Assert $p) => $p->component('admin/food')->has('restaurants', 2));
        $this->put("/admin/food/{$r->id}/commission", ['kind' => 'taka', 'value' => 20])->assertSessionHasNoErrors();
        $this->put("/admin/food/{$r->id}/suspend", ['on' => true])->assertSessionHasNoErrors();
        $this->assertEquals(['taka', 20, 'suspended'], [$r->fresh()->commission_kind, $r->fresh()->commission_value, $r->fresh()->status]);

        $this->actingAs($this->staff)->post('/admin/food', ['name' => 'Nope', 'owner_name' => 'N', 'password' => 'long-password-1', 'area' => 'X', 'commission_kind' => 'percent', 'commission_value' => 1])->assertRedirect('/');
        $this->assertSame(0, Restaurant::where('name', 'Nope')->count());
    }
}
