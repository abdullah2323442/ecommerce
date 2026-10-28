<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Market\MarketOrders;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Spec ch 13: cash on delivery is set for each product; one product without it and it is hidden for the whole cart. A product that cannot be paid on
 * delivery is paid online first, the seller sees the order only once it is paid, and is paid out after the sale is complete.
 */
class CashOnDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://securepay.sslcommerz.com';

    private User $admin;

    private User $seller;

    private User $buyer;

    private Listing $cash;

    private Listing $prepaid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'seller_trusted_at' => now()]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => false]);
        $make = fn (string $t, bool $cod) => Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => $t, 'public_price' => 1000, 'delivery_charge' => 100, 'stock' => 10, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(), 'cod_allowed' => $cod,
        ]);
        $this->cash = $make('Teak chair', true);
        $this->prepaid = $make('Digital plan', false);
    }

    private function online(bool $on = true, string $mode = 'live'): void
    {
        app(Integrations::class)->save('payment:sslcommerz', ['store_id' => 'shop123live', 'store_password' => 'STORE-PASS-999'], $on, $mode, [], $this->admin);
    }

    private function ship(): array
    {
        return ['name' => 'Hasan', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    private function buyPrepaid(): MarketOrder
    {
        $this->actingAs($this->buyer)->post("/market/{$this->prepaid->id}/order", $this->ship() + ['quantity' => 1])->assertSessionHasNoErrors();

        return MarketOrder::latest('id')->firstOrFail();
    }

    private function pay(MarketOrder $o): Payment
    {
        Http::fake([self::HOST.'/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'sessionkey' => 'SK', 'GatewayPageURL' => self::HOST.'/EasyCheckOut/x'], 200)]);
        $this->actingAs($this->buyer)->post("/market/orders/{$o->public_token}/pay")->assertRedirect();

        return Payment::latest('id')->firstOrFail();
    }

    private function confirmPaid(Payment $p): void
    {
        Http::fake([self::HOST.'/validator/api/validationserverAPI.php*' => Http::response([
            'status' => 'VALID', 'tran_id' => $p->ref, 'val_id' => 'V1', 'amount' => number_format((float) $p->amount, 2, '.', ''), 'currency' => 'BDT', 'bank_tran_id' => 'B1', 'card_type' => 'BKASH-BKash', 'risk_level' => '0',
        ], 200)]);
        $this->post('/webhooks/payments/sslcommerz/result/success', ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'VALID'])->assertRedirect();
    }

    // ------------------------------------------------------------------ the flag

    public function test_everything_is_cash_on_delivery_unless_the_seller_says_otherwise(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->cash->id}/order", $this->ship() + ['quantity' => 1])->assertSessionHasNoErrors();

        $o = MarketOrder::firstOrFail();
        $this->assertSame('cod', $o->pay_by);
        $this->assertNull($o->paid_at);
    }

    public function test_the_seller_sets_it_on_the_product_form_and_it_defaults_on(): void
    {
        $this->assertTrue((bool) Listing::find($this->cash->id)->cod_allowed);

        $this->actingAs($this->seller)->get('/wholesale/sell')->assertOk();
    }

    public function test_the_seller_can_switch_it_off_when_changing_a_listing_and_when_sending_a_new_one(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->cash->id}", ['cod_allowed' => false])->assertSessionHasNoErrors();
        $this->assertFalse((bool) Listing::find($this->cash->id)->cod_allowed);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->cash->id}", ['cod_allowed' => true])->assertSessionHasNoErrors();
        $this->assertTrue((bool) Listing::find($this->cash->id)->cod_allowed);

        $sector = Sector::where('slug', 'wood')->firstOrFail();
        $this->actingAs($this->seller)->post('/wholesale/sell', [
            'sector_id' => $sector->id, 'title' => 'Prepaid stool', 'public_price' => 500, 'min_qty' => 1, 'delivery_charge' => 0, 'stock' => 3, 'cod_allowed' => false,
        ])->assertSessionHasNoErrors();
        $this->assertFalse((bool) Listing::where('title', 'Prepaid stool')->firstOrFail()->cod_allowed);

        $this->actingAs($this->seller)->post('/wholesale/sell', [
            'sector_id' => $sector->id, 'title' => 'Plain stool', 'public_price' => 500, 'min_qty' => 1, 'delivery_charge' => 0, 'stock' => 3,
        ])->assertSessionHasNoErrors();
        $this->assertTrue((bool) Listing::where('title', 'Plain stool')->firstOrFail()->cod_allowed);
    }

    public function test_the_product_page_says_which_way_it_is_paid(): void
    {
        $this->actingAs($this->buyer);

        $this->get("/market/{$this->cash->id}")->assertInertia(fn (Assert $p) => $p->where('cod', true));
        $this->get("/market/{$this->prepaid->id}")->assertInertia(fn (Assert $p) => $p->where('cod', false));
    }

    public function test_a_product_paid_online_only_cannot_be_ordered_while_online_payment_is_off(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->prepaid->id}/order", $this->ship() + ['quantity' => 1])->assertSessionHasErrors('order');

        $this->assertSame(0, MarketOrder::count());
        $this->assertSame(10.0, (float) $this->prepaid->fresh()->stock);
    }

    // ------------------------------------------------------------------ the cart rule

    public function test_one_product_without_cash_on_delivery_hides_it_for_the_whole_cart(): void
    {
        $this->online();
        $this->actingAs($this->buyer);
        $this->post('/market-cart', ['listing_id' => $this->cash->id, 'quantity' => 1]);

        $this->get('/market-cart')->assertInertia(fn (Assert $p) => $p->where('cart.cod', true));

        $this->post('/market-cart', ['listing_id' => $this->prepaid->id, 'quantity' => 1]);

        $this->get('/market-cart')->assertInertia(fn (Assert $p) => $p->where('cart.cod', false)->where('cart.online_open', true));
    }

    public function test_such_a_cart_makes_every_order_in_it_an_online_order(): void
    {
        $this->online();
        $this->actingAs($this->buyer);
        $this->post('/market-cart', ['listing_id' => $this->cash->id, 'quantity' => 1]);
        $this->post('/market-cart', ['listing_id' => $this->prepaid->id, 'quantity' => 1]);

        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasNoErrors();

        $this->assertSame(['online', 'online'], MarketOrder::orderBy('id')->pluck('pay_by')->all());
    }

    public function test_a_cart_of_cash_products_stays_cash_even_when_online_payment_is_open(): void
    {
        $this->online();
        $this->actingAs($this->buyer);
        $this->post('/market-cart', ['listing_id' => $this->cash->id, 'quantity' => 2]);

        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasNoErrors();

        $this->assertSame('cod', MarketOrder::firstOrFail()->pay_by);
    }

    public function test_a_cart_that_must_be_paid_online_is_refused_when_online_payment_is_off(): void
    {
        $this->actingAs($this->buyer);
        $this->post('/market-cart', ['listing_id' => $this->prepaid->id, 'quantity' => 1]);

        $this->get('/market-cart')->assertInertia(fn (Assert $p) => $p->where('cart.cod', false)->where('cart.online_open', false));
        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasErrors('order');

        $this->assertSame(0, MarketOrder::count());
    }

    // ------------------------------------------------------------------ paying

    public function test_the_seller_does_not_see_or_accept_an_order_until_it_is_paid(): void
    {
        $this->online();
        $o = $this->buyPrepaid();

        $this->assertSame('online', $o->pay_by);
        $this->assertSame(0, Notice::where('user_id', $this->seller->id)->where('kind', 'market_order_new')->count());
        $this->assertSame('The buyer has not paid yet.', $this->tryAccept($o));
    }

    private function tryAccept(MarketOrder $o): string
    {
        try {
            app(MarketOrders::class)->accept($o, $this->seller);
        } catch (StockException $e) {
            return $e->getMessage();
        }

        return '';
    }

    public function test_paying_online_counts_once_and_tells_the_seller(): void
    {
        $this->online();
        $o = $this->buyPrepaid();
        $p = $this->pay($o);

        $this->assertEqualsWithDelta((float) $o->total, (float) $p->amount, 0.001);
        $this->assertSame('market_order', $p->payable_type);

        $this->confirmPaid($p);
        $this->confirmPaid($p);   // the gateway's own message arrives as well

        $this->assertNotNull($o->fresh()->paid_at);
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame('', $this->tryAccept($o->fresh()));
    }

    public function test_the_amount_comes_from_our_books_not_the_browser(): void
    {
        $this->online();
        $o = $this->buyPrepaid();

        Http::fake([self::HOST.'/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'sessionkey' => 'SK', 'GatewayPageURL' => self::HOST.'/EasyCheckOut/x'], 200)]);
        $this->actingAs($this->buyer)->post("/market/orders/{$o->public_token}/pay", ['amount' => 1, 'total_amount' => 1])->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'gwprocess') && abs((float) $r['total_amount'] - (float) $o->total) < 0.001);
    }

    public function test_a_wrong_amount_from_the_gateway_is_not_counted(): void
    {
        $this->online();
        $o = $this->buyPrepaid();
        $p = $this->pay($o);

        Http::fake([self::HOST.'/validator/api/validationserverAPI.php*' => Http::response(['status' => 'VALID', 'tran_id' => $p->ref, 'val_id' => 'V1', 'amount' => '5.00', 'currency' => 'BDT', 'bank_tran_id' => 'B', 'card_type' => 'X', 'risk_level' => '0'], 200)]);
        $this->post('/webhooks/payments/sslcommerz/result/success', ['tran_id' => $p->ref, 'val_id' => 'V1'])->assertRedirect();

        $this->assertNull($o->fresh()->paid_at);
        $this->assertNotSame('paid', $p->fresh()->status);
    }

    public function test_the_gateways_practice_mode_never_marks_an_order_paid(): void
    {
        $this->online(true, 'test');
        $o = $this->buyPrepaid();
        $p = $this->pay($o);

        Http::fake(['https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php*' => Http::response(['status' => 'VALID', 'tran_id' => $p->ref, 'val_id' => 'V1', 'amount' => number_format((float) $p->amount, 2, '.', ''), 'currency' => 'BDT', 'bank_tran_id' => 'B', 'card_type' => 'X', 'risk_level' => '0'], 200)]);
        $this->post('/webhooks/payments/sslcommerz/result/success', ['tran_id' => $p->ref, 'val_id' => 'V1'])->assertRedirect();

        $this->assertNull($o->fresh()->paid_at);
    }

    public function test_a_cash_order_cannot_be_paid_online_and_a_paid_one_not_twice(): void
    {
        $this->online();
        $this->actingAs($this->buyer)->post("/market/{$this->cash->id}/order", $this->ship() + ['quantity' => 1]);
        $cash = MarketOrder::latest('id')->firstOrFail();

        $this->post("/market/orders/{$cash->public_token}/pay")->assertSessionHasErrors('order');

        $o = $this->buyPrepaid();
        $this->confirmPaid($this->pay($o));
        $this->post("/market/orders/{$o->public_token}/pay")->assertSessionHasErrors('order');
        $this->assertSame(1, Payment::count());
    }

    public function test_the_order_page_offers_the_payment_to_its_buyer(): void
    {
        $this->online();
        $o = $this->buyPrepaid();

        $this->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('pay.can', true)->where('pay.online', true)->where('pay.amount', fn ($v) => abs($v - (float) $o->total) < 0.01));
        $this->confirmPaid($this->pay($o));
        $this->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('pay.can', false)->where('pay.paid_online', fn ($v) => abs($v - (float) $o->total) < 0.01));
    }

    // ------------------------------------------------------------------ cancel, expire, pay out

    public function test_cancelling_a_paid_order_makes_the_money_owed_back(): void
    {
        $this->online();
        $o = $this->buyPrepaid();
        $p = $this->pay($o);
        $this->confirmPaid($p);

        $this->post("/market/orders/{$o->public_token}/cancel")->assertSessionHasNoErrors();

        $this->assertSame('refund_due', $p->fresh()->status);
        $this->assertSame(10.0, (float) $this->prepaid->fresh()->stock);

        $this->actingAs($this->admin)->get('/admin/payments')->assertInertia(fn (Assert $page) => $page->has('refunds', 1)->where('refunds.0.market', true)->where('refunds.0.order_id', null));
    }

    public function test_an_unpaid_online_order_is_cancelled_after_a_day_and_the_stock_returns(): void
    {
        $this->online();
        $o = $this->buyPrepaid();
        $this->assertSame(9.0, (float) $this->prepaid->fresh()->stock);

        $this->assertSame(0, app(MarketOrders::class)->expireUnpaid());

        $this->travel(25)->hours();
        $this->assertSame(1, app(MarketOrders::class)->expireUnpaid());

        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame(10.0, (float) $this->prepaid->fresh()->stock);
        $this->assertSame(0, Notice::where('user_id', $this->seller->id)->where('kind', 'market_order_cancelled')->count());
    }

    public function test_a_paid_order_is_never_expired(): void
    {
        $this->online();
        $o = $this->buyPrepaid();
        $this->confirmPaid($this->pay($o));

        $this->travel(48)->hours();

        $this->assertSame(0, app(MarketOrders::class)->expireUnpaid());
        $this->assertSame('placed', $o->fresh()->status);
    }

    public function test_a_delivered_paid_order_is_owed_to_the_seller_less_our_commission_and_is_paid_out_once(): void
    {
        $this->online();
        $o = $this->buyPrepaid();
        $this->confirmPaid($this->pay($o));
        $orders = app(MarketOrders::class);
        $orders->accept($o->fresh(), $this->seller);
        $orders->ship($o->fresh(), $this->seller, 'T1');

        $this->assertCount(0, $orders->payouts());   // not delivered yet

        $orders->confirm($o->fresh());
        $rows = $orders->payouts();

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta((float) $o->total - (float) $o->commission, $rows[0]['amount'], 0.001);

        // The seller has nothing to hand over: the commission was kept when the buyer paid.
        $this->assertCount(0, $orders->owed());

        $this->actingAs($this->admin)->post("/admin/payments/payout/{$this->seller->id}")->assertSessionHasNoErrors();

        $this->assertCount(0, $orders->payouts());
        $this->assertNotNull($o->fresh()->payout_at);
    }

    public function test_cash_sales_still_owe_commission_and_are_not_in_payouts(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->cash->id}/order", $this->ship() + ['quantity' => 1]);
        $o = MarketOrder::firstOrFail();
        $orders = app(MarketOrders::class);
        $orders->accept($o, $this->seller);
        $orders->ship($o->fresh(), $this->seller);
        $orders->confirm($o->fresh());

        $this->assertCount(0, $orders->payouts());
        $this->assertCount(1, $orders->owed());
    }

    public function test_a_sale_sent_back_or_not_received_is_not_paid_out(): void
    {
        $this->online();
        $o = $this->buyPrepaid();
        $this->confirmPaid($this->pay($o));
        $orders = app(MarketOrders::class);
        $orders->accept($o->fresh(), $this->seller);
        $orders->ship($o->fresh(), $this->seller);
        $orders->notReceived($o->fresh());

        $this->assertCount(0, $orders->payouts());
    }

    public function test_only_staff_see_payouts_and_press_the_button(): void
    {
        $this->actingAs($this->buyer)->post("/admin/payments/payout/{$this->seller->id}")->assertRedirect('/');
        $this->actingAs($this->seller)->get('/admin/payments')->assertRedirect('/');
    }

    public function test_the_packages_page_shows_what_is_waiting_for_payment(): void
    {
        $this->online();
        $this->actingAs($this->buyer);
        $this->post('/market-cart', ['listing_id' => $this->prepaid->id, 'quantity' => 1]);
        $this->post('/market-cart/checkout', $this->ship());
        $group = MarketOrder::firstOrFail()->group_token;

        $this->get("/market/packages/{$group}")->assertInertia(fn (Assert $p) => $p->where('packages.0.pay_by', 'online')->where('packages.0.paid', false));
    }
}
