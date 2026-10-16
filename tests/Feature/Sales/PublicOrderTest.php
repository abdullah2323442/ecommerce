<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Address;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\PublicOrders;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13, 20: anyone can order without an account; staff check it like any online order; the buyer follows it. */
class PublicOrderTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $napa;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
        $stock->setMasterSync(true);
    }

    private function stock(): float
    {
        return app(StockService::class)->onHand($this->napa);
    }

    private function add(string $unit = 'strip', int $qty = 2)
    {
        return $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => $unit, 'quantity' => $qty]);
    }

    private function details(array $over = []): array
    {
        return array_merge([
            'name' => 'Hasan Ali', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5, Road 3', 'delivery_mode' => 'delivery',
        ], $over);
    }

    private function place(array $over = [])
    {
        return $this->post('/checkout', $this->details($over));
    }

    public function test_a_visitor_fills_a_cart_without_an_account(): void
    {
        $this->add('strip', 2)->assertSessionHasNoErrors();
        $this->add('strip', 1);
        $this->add('piece', 5);

        $this->get('/cart')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('cart')->has('lines', 2)
            ->where('lines.0.quantity', 3)->where('lines.0.unit_price', 100)->where('lines.0.total', 300)
            ->where('lines.1.total', 50)->where('subtotal', 350)->where('cartCount', 2));
    }

    public function test_the_cart_price_is_the_printed_price_for_everyone_even_a_tier_customer(): void
    {
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 15]);
        $user = User::factory()->create(['account_type' => AccountType::Public, 'tier_id' => $tier->id]);

        $this->actingAs($user);
        $this->add('box', 1);

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 300));
    }

    public function test_a_unit_the_medicine_does_not_come_in_or_an_inactive_medicine_is_refused(): void
    {
        $loose = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Saline', 'form' => 'syrup', 'packaging_type' => 'bottle', 'mrp_piece' => 80, 'is_active' => true]);
        $off = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Old', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 50, 'is_active' => false]);

        $this->post('/cart', ['medicine_id' => $loose->id, 'unit' => 'strip', 'quantity' => 1])->assertSessionHasErrors('cart');
        $this->post('/cart', ['medicine_id' => $off->id, 'unit' => 'box', 'quantity' => 1])->assertSessionHasErrors('cart');
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 0])->assertSessionHasErrors('quantity');
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 5000])->assertSessionHasErrors('quantity');
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 0));
    }

    public function test_many_cart_changes_do_not_use_up_the_checkout_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->add('piece', 1)->assertSessionHasNoErrors();
        }

        $this->place()->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Invoice::count());
    }

    public function test_the_cart_can_be_changed_and_emptied(): void
    {
        $this->add('strip', 2);
        $this->put('/cart/'.$this->napa->id.':strip', ['quantity' => 7])->assertSessionHasNoErrors();
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.quantity', 7));

        $this->delete('/cart/'.$this->napa->id.':strip');
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 0));
    }

    public function test_a_shop_or_bulk_buyer_or_staff_member_does_not_use_the_public_cart(): void
    {
        foreach ([AccountType::Paikari, AccountType::Wholesaler, AccountType::Staff] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]));
            $this->get('/cart')->assertRedirect('/');
            $this->add()->assertRedirect('/');
            $this->get('/checkout')->assertRedirect('/');
        }
    }

    public function test_checkout_asks_for_a_name_a_real_phone_and_an_address_even_for_a_guest(): void
    {
        $this->add();

        $this->place(['name' => ''])->assertSessionHasErrors('name');
        $this->place(['phone' => '12345'])->assertSessionHasErrors('phone');
        $this->place(['district' => 'Atlantis'])->assertSessionHasErrors('district');
        $this->place(['area' => ''])->assertSessionHasErrors('area');
        $this->place(['address' => ''])->assertSessionHasErrors('address');
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_checkout_errors_are_in_bangla_for_a_bangla_reader_and_in_english_otherwise(): void
    {
        $this->add();

        $bn = $this->withCookie(SetLocale::COOKIE, 'bn')->post('/checkout', $this->details(['name' => '']));
        $this->assertSame('নাম লিখতে হবে।', session('errors')->first('name'));

        $en = $this->withCookie(SetLocale::COOKIE, 'en')->post('/checkout', $this->details(['name' => '']));
        $this->assertSame('The name field is required.', session('errors')->first('name'));
    }

    public function test_an_empty_cart_goes_back_to_the_cart_page(): void
    {
        $this->get('/checkout')->assertRedirect('/cart');
        $this->place()->assertSessionHasErrors('order');
    }

    public function test_a_guest_order_becomes_a_placed_online_retail_invoice_that_takes_no_stock_yet(): void
    {
        $this->add('strip', 2);
        $before = $this->stock();

        $response = $this->place(['phone' => '+880 1711-000000', 'how_to_find' => 'Red gate', 'for_whom' => 'Mother', 'note' => 'Call first']);

        $order = Invoice::firstOrFail();
        $response->assertRedirect("/orders/{$order->public_token}");
        $this->assertSame('retail', $order->kind);
        $this->assertSame('online', $order->channel);
        $this->assertSame('placed', $order->status);
        $this->assertNull($order->customer_id);
        $this->assertSame(40, strlen($order->public_token));
        $this->assertSame('01711000000', $order->ship_to['phone']);
        $this->assertSame('Mother', $order->ship_to['for_whom']);
        $this->assertSame('Call first', $order->note);
        $this->assertSame(1, $order->lines()->count());
        $this->assertSame($before, $this->stock());
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 0));
    }

    public function test_the_delivery_charge_follows_the_district_and_the_free_level(): void
    {
        $this->add('strip', 1);
        $this->place(['district' => 'Dhaka']);
        $this->assertSame('60.00', (string) Invoice::latest('id')->first()->delivery_charge);

        $this->add('strip', 1);
        $this->place(['district' => 'Sylhet']);
        $this->assertSame('120.00', (string) Invoice::latest('id')->first()->delivery_charge);

        Setting::write('delivery.free_over', 90);
        $this->add('strip', 1);
        $this->place(['district' => 'Sylhet']);
        $this->assertSame('0.00', (string) Invoice::latest('id')->first()->delivery_charge);

        Setting::write('delivery.shop_district', 'Sylhet');
        Setting::write('delivery.free_over', 0);
        $this->add('strip', 1);
        $this->place(['district' => 'Sylhet'])->assertSessionHasNoErrors();
        $this->assertSame('60.00', (string) Invoice::latest('id')->first()->delivery_charge);
    }

    public function test_collecting_from_the_shop_costs_nothing_and_needs_a_shop_address(): void
    {
        $this->add();
        $this->place(['delivery_mode' => 'collect'])->assertSessionHasErrors('order');

        config(['brand.address' => 'Shop 4, Mirpur 10']);
        $this->place(['delivery_mode' => 'collect'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', (string) Invoice::firstOrFail()->delivery_charge);
    }

    public function test_the_buyer_follows_the_order_from_the_private_link_and_nobody_else_can_guess_one(): void
    {
        $this->add('strip', 2);
        $this->place();
        $order = Invoice::firstOrFail();

        $this->get("/orders/{$order->public_token}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('order-public')->where('order.status', 'placed')->where('order.subtotal', 200)->where('order.delivery', 60)->where('order.total', 260)
            ->has('order.lines', 1)->where('order.lines.0.check', 'pending'));

        $this->get('/orders/'.str_repeat('a', 40))->assertRedirect('/');
        $this->get('/orders/short')->assertRedirect('/');
    }

    public function test_the_buyer_can_cancel_for_a_short_time_and_then_only_by_calling(): void
    {
        $this->add();
        $this->place();
        $order = Invoice::firstOrFail();

        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.cancel_minutes', fn ($v) => $v > 0 && $v <= 20));

        $order->update(['placed_at' => now()->subMinutes(25)]);
        $this->post("/orders/{$order->public_token}/cancel")->assertSessionHasErrors('order');
        $this->assertSame('placed', $order->fresh()->status);

        $order->update(['placed_at' => now()->subMinutes(5)]);
        $this->post("/orders/{$order->public_token}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_the_buyer_cannot_cancel_once_staff_have_started_checking(): void
    {
        $this->add();
        $this->place();
        $order = Invoice::firstOrFail();
        $line = $order->lines()->firstOrFail();

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);
        auth()->logout();

        $this->post("/orders/{$order->public_token}/cancel")->assertSessionHasErrors('order');
        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_staff_see_the_guest_order_with_where_to_send_it_and_check_it_like_any_other(): void
    {
        $this->add('strip', 2);
        $this->place(['how_to_find' => 'Red gate']);
        $order = Invoice::firstOrFail();
        $line = $order->lines()->firstOrFail();

        $this->actingAs($this->staff);
        $this->get('/admin/orders')->assertInertia(fn (Assert $p) => $p->where('orders.0.customer', 'Hasan Ali')->where('orders.0.phone', '01711000000')->where('orders.0.public', true));
        $this->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p
            ->where('order.customer', 'Hasan Ali')->where('order.ship_to.address', 'House 5, Road 3')->where('order.ship_to.how_to_find', 'Red gate')->where('order.public', true));

        $before = $this->stock();
        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta($before - 2 / 3, $this->stock(), 0.001);
        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();

        auth()->logout();
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.status', 'verified')->where('order.lines.0.check', 'full'));
    }

    public function test_a_finished_order_opens_as_a_memo_from_the_same_private_link(): void
    {
        $this->add('strip', 2);
        $this->place();
        $order = Invoice::firstOrFail();
        $line = $order->lines()->firstOrFail();

        $this->get("/memo/t/{$order->public_token}")->assertRedirect('/');

        $this->actingAs($this->staff);
        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");
        $this->post("/admin/counter/{$order->id}/finalise")->assertSessionHasNoErrors();
        auth()->logout();

        $this->get("/memo/t/{$order->public_token}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('memo')->where('memo.customer', 'Hasan Ali')->where('memo.phone', '01711000000')->where('memo.delivery', 60)->where('canPrint', false));
    }

    public function test_a_signed_in_buyer_gets_the_order_in_their_list_and_can_keep_the_address(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Rina', 'phone' => '01722222222']);
        $this->actingAs($user);
        $this->add();

        $this->get('/checkout')->assertInertia(fn (Assert $p) => $p->where('defaults.name', 'Rina')->where('signedIn', true));
        $this->place(['save_address' => true]);

        $order = Invoice::firstOrFail();
        $this->assertSame($user->id, $order->customer_id);
        $this->assertDatabaseHas('addresses', ['user_id' => $user->id, 'area' => 'Mirpur']);

        $this->get('/my-orders')->assertInertia(fn (Assert $p) => $p->has('orders', 1)->where('orders.0.token', $order->public_token));
        $this->add();
        $this->get('/checkout')->assertInertia(fn (Assert $p) => $p->has('addresses', 1));
    }

    public function test_the_order_list_and_address_book_are_private_to_their_owner(): void
    {
        $owner = User::factory()->create(['account_type' => AccountType::Public]);
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $address = Address::create(['user_id' => $owner->id, 'name' => 'A', 'phone' => '01711111111', 'district' => 'Dhaka', 'area' => 'X', 'address' => 'Y']);

        $this->get('/my-orders')->assertRedirect();
        $this->actingAs($other)->delete("/addresses/{$address->id}")->assertRedirect('/');
        $this->assertDatabaseHas('addresses', ['id' => $address->id]);

        $this->actingAs($owner)->delete("/addresses/{$address->id}");
        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    public function test_an_order_nobody_answered_is_cancelled_after_a_while_and_the_account_holder_is_told(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($user);
        $this->add();
        $this->place();
        $order = Invoice::firstOrFail();

        $order->update(['placed_at' => now()->subHours(10)]);
        $this->assertSame(0, app(PublicOrders::class)->cancelStale());

        $order->update(['placed_at' => now()->subHours(73)]);
        $this->assertSame(1, app(PublicOrders::class)->cancelStale());
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('notices', ['user_id' => $user->id, 'kind' => 'order_cancelled', 'importance' => 'urgent']);
    }

    public function test_an_order_staff_have_started_on_is_never_cancelled_as_stale(): void
    {
        $this->add();
        $this->place();
        $order = Invoice::firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        $order->update(['placed_at' => now()->subDays(10)]);

        $this->assertSame(0, app(PublicOrders::class)->cancelStale());
        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_the_stale_hours_are_a_setting(): void
    {
        $this->add();
        $this->place();
        $order = Invoice::firstOrFail();
        $order->update(['placed_at' => now()->subHours(30)]);
        Setting::write('orders.stale_hours', 24);

        $this->assertSame(1, app(PublicOrders::class)->cancelStale());
        $this->assertSame(0, Notice::count());
    }

    public function test_no_page_here_gives_away_a_stock_number(): void
    {
        $this->add('box', 3);
        $this->place();
        $order = Invoice::firstOrFail();

        $json = json_encode($this->get("/orders/{$order->public_token}")->viewData('page')['props']);
        foreach (['stock', 'purchase', 'cost', 'units_remaining', 'on_hand'] as $word) {
            $this->assertStringNotContainsString($word, strtolower((string) preg_replace('/"translations":\{.*?\},"/s', '"', $json)), $word);
        }
    }
}
