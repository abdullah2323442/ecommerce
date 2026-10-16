<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Payments\Payments;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27: pay for an order online. Money is safe: nothing counts until we ask the gateway ourselves and every detail matches. */
class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sandbox.sslcommerz.com';

    /** The gateway's address for the mode in use: the practice one while testing, the real one when live. */
    private string $host = self::SANDBOX;

    private User $admin;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 50]],
        ]);
        $stock->setMasterSync(true);
    }

    private function connect(bool $on = true, string $mode = 'live'): void
    {
        $this->host = $mode === 'live' ? 'https://securepay.sslcommerz.com' : self::SANDBOX;
        app(Integrations::class)->save('payment:sslcommerz', ['store_id' => 'shop123live', 'store_password' => 'STORE-PASS-999'], $on, $mode, [], $this->admin);
    }

    /** A guest order, checked by staff, ready to be paid. */
    private function order(int $strips = 3, bool $check = true): Invoice
    {
        auth()->logout();
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => $strips]);
        $this->post('/checkout', ['name' => 'Hasan Ali', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery']);
        $order = Invoice::latest('id')->firstOrFail();

        if ($check) {
            $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
            $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();
            auth()->logout();
        }

        return $order->fresh();
    }

    private function fakeInit(): void
    {
        Http::fake([$this->host.'/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'sessionkey' => 'SK1', 'GatewayPageURL' => $this->host.'/EasyCheckOut/testcdeSK1'], 200)]);
    }

    /** What the gateway says when asked about a payment. */
    private function fakeValidation(Payment $p, array $override = []): void
    {
        Http::fake([$this->host.'/validator/api/validationserverAPI.php*' => Http::response($override + [
            'status' => 'VALID', 'tran_id' => $p->ref, 'val_id' => 'V1', 'amount' => number_format((float) $p->amount, 2, '.', ''), 'currency' => 'BDT', 'bank_tran_id' => 'BANK9', 'card_type' => 'BKASH-BKash', 'risk_level' => '0',
        ], 200)]);
    }

    private function start(Invoice $o)
    {
        return $this->post("/orders/{$o->public_token}/pay");
    }

    private function startedPayment(Invoice $o): Payment
    {
        $this->fakeInit();
        $this->start($o)->assertRedirect();

        return Payment::latest('id')->firstOrFail();
    }

    private function back(Payment $p, string $result = 'success', array $more = [])
    {
        return $this->post("/webhooks/payments/sslcommerz/result/{$result}", ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'VALID'] + $more);
    }

    // ------------------------------------------------------------------ who may pay, and for how much

    public function test_it_is_not_open_until_the_owner_switches_it_on(): void
    {
        $o = $this->order();

        $this->start($o)->assertSessionHasErrors('order');

        $this->assertSame(0, Payment::count());
        $this->get("/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('pay.can', false));
    }

    public function test_an_order_still_being_checked_cannot_be_paid_yet(): void
    {
        $this->connect(true, 'test');
        $o = $this->order(check: false);
        Http::fake();

        $this->start($o)->assertSessionHasErrors('order');

        Http::assertNothingSent();
        $this->get("/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('pay.can', false)->where('pay.why', 'We are still checking your order. You can pay when it is ready.'));
    }

    public function test_a_finished_or_tiny_order_cannot_be_paid(): void
    {
        $this->connect(true, 'test');
        Http::fake();
        $done = $this->order();
        $done->forceFill(['delivered_at' => now()])->save();
        $this->start($done)->assertSessionHasErrors('order');

        $small = $this->order(1);
        $small->forceFill(['paid_amount' => $this->due($small) - 5])->save();   // 5 taka left
        $this->start($small)->assertSessionHasErrors('order');

        Http::assertNothingSent();
    }

    private function due(Invoice $o): float
    {
        return app(Payments::class)->due($o->fresh());
    }

    public function test_a_wrong_link_pays_nothing(): void
    {
        $this->connect(true, 'test');
        Http::fake();

        $this->post('/orders/'.str_repeat('a', 40).'/pay')->assertRedirect('/');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ starting

    public function test_the_gateway_is_asked_for_what_we_say_is_owed_and_nothing_the_browser_says(): void
    {
        $this->connect(true, 'test');
        $this->fakeInit();
        $o = $this->order();
        $o->forceFill(['paid_amount' => 50])->save();
        $due = $this->due($o);

        $this->post("/orders/{$o->public_token}/pay", ['amount' => 1, 'total_amount' => 1, 'tran_id' => 'mine'])->assertRedirect(self::SANDBOX.'/EasyCheckOut/testcdeSK1');

        $p = Payment::firstOrFail();
        $this->assertEqualsWithDelta($due, (float) $p->amount, 0.001);
        $this->assertSame('started', $p->status);
        $this->assertFalse($p->live, 'testing mode is the practice gateway');
        Http::assertSent(fn ($r) => $r->url() === self::SANDBOX.'/gwprocess/v4/api.php' && $r['store_id'] === 'shop123live' && $r['store_passwd'] === 'STORE-PASS-999'
            && $r['total_amount'] === number_format($due, 2, '.', '') && $r['tran_id'] === $p->ref && $r['currency'] === 'BDT'
            && $r['success_url'] === route('payments.result', ['sslcommerz', 'success']) && $r['ipn_url'] === route('payments.ipn', 'sslcommerz') && $r['cus_phone'] === '01711000000');
    }

    public function test_live_mode_talks_to_the_real_gateway_and_testing_to_the_practice_one(): void
    {
        $this->connect(true, 'live');
        Http::fake(['https://securepay.sslcommerz.com/*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://securepay.sslcommerz.com/EasyCheckOut/abc'], 200)]);
        $o = $this->order();

        $this->start($o)->assertRedirect('https://securepay.sslcommerz.com/EasyCheckOut/abc');

        $this->assertTrue(Payment::firstOrFail()->live);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://securepay.sslcommerz.com/gwprocess'));
    }

    public function test_a_refusal_an_outage_or_a_strange_page_address_is_a_calm_failure(): void
    {
        $this->connect(true, 'test');
        $o = $this->order();

        Http::fake([self::SANDBOX.'/*' => Http::response(['status' => 'FAILED', 'failedreason' => 'Invalid Store SECRET-LEAK'], 200)]);
        $this->start($o)->assertSessionHasErrors('order');
        $this->assertStringNotContainsString('SECRET-LEAK', json_encode(session('errors')->all()));

        Http::fake(fn () => throw new ConnectionException('down'));
        $this->start($o)->assertSessionHasErrors('order');

        Http::fake([self::SANDBOX.'/*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://evil.example.com/pay'], 200)]);
        $this->start($o)->assertSessionHasErrors('order');

        $this->assertSame(0, Payment::where('status', 'started')->count(), 'a failed start leaves nothing waiting');
        $this->assertSame(3, Payment::where('status', 'failed')->count());
    }

    // ------------------------------------------------------------------ being sure it was paid

    public function test_a_proven_payment_is_counted_on_the_order(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        $due = (float) $p->amount;
        $this->fakeValidation($p);

        $this->back($p)->assertRedirect(route('orders.show', $o->public_token))->assertSessionHas('status');

        $p->refresh();
        $this->assertSame('paid', $p->status);
        $this->assertSame('BANK9', $p->bank_tran_id);
        $this->assertSame('BKASH-BKash', $p->method);
        $this->assertEqualsWithDelta($due, (float) $o->fresh()->paid_amount, 0.001);
        $this->assertSame('online', $o->fresh()->payment_method);
        $this->assertSame(0.0, $this->due($o), 'nothing left to collect at the door');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'validationserverAPI.php') && $r['val_id'] === 'V1' && $r['store_id'] === 'shop123live' && $r['store_passwd'] === 'STORE-PASS-999');
    }

    public function test_what_the_browser_says_is_never_believed_without_the_gateways_own_answer(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        Http::fake([$this->host.'/validator/*' => Http::response(['status' => 'INVALID_TRANSACTION'], 200)]);

        $this->back($p, 'success', ['amount' => $p->amount, 'status' => 'VALID'])->assertRedirect(route('orders.show', $o->public_token))->assertSessionHasErrors('order');

        $this->assertSame('started', $p->fresh()->status);
        $this->assertSame(0.0, (float) $o->fresh()->paid_amount);
    }

    public function test_a_payment_for_a_different_amount_a_different_transaction_or_a_different_currency_does_not_count(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);

        foreach ([['amount' => '10.00'], ['tran_id' => 'SOMEONE-ELSES'], ['currency' => 'USD'], ['status' => 'FAILED'], ['status' => 'CANCELLED']] as $bad) {
            $this->fakeValidation($p, $bad);
            $this->back($p)->assertSessionHasErrors('order');
        }

        $this->assertSame('started', $p->fresh()->status);
        $this->assertSame(0.0, (float) $o->fresh()->paid_amount);
    }

    public function test_one_payment_is_counted_once_however_often_it_is_confirmed(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        $due = (float) $p->amount;
        $this->fakeValidation($p);

        $this->back($p);
        $this->back($p);
        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'VALID'])->assertOk();
        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'VALID'])->assertOk();

        $this->assertEqualsWithDelta($due, (float) $o->fresh()->paid_amount, 0.001);
        $this->assertSame(1, ActivityLog::where('action', 'payment.received')->count());
    }

    public function test_the_gateways_own_message_confirms_a_payment_the_buyer_never_came_back_from(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        $this->fakeValidation($p);

        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'VALID'])->assertOk()->assertSee('OK');

        $this->assertSame('paid', $p->fresh()->status);
        $this->assertGreaterThan(0, (float) $o->fresh()->paid_amount);
    }

    public function test_a_forged_message_does_nothing(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        Http::fake([$this->host.'/validator/*' => Http::response(['status' => 'INVALID_TRANSACTION'], 200)]);

        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => $p->ref, 'val_id' => 'FORGED', 'status' => 'VALID'])->assertOk();
        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => $p->ref, 'status' => 'VALID'])->assertOk();
        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'FAILED'])->assertOk();
        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => 'NOT-OURS', 'val_id' => 'V1', 'status' => 'VALID'])->assertOk();

        $this->assertSame('started', $p->fresh()->status);
        $this->assertSame(0.0, (float) $o->fresh()->paid_amount);
    }

    public function test_the_gateways_practice_mode_never_marks_an_order_paid(): void
    {
        $this->connect(true, 'test');
        $o = $this->order();
        $p = $this->startedPayment($o);
        $this->fakeValidation($p);

        $this->back($p)->assertSessionHas('status');

        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(0.0, (float) $o->fresh()->paid_amount, 'no real money, no change to what is owed');
        $this->get("/orders/{$o->public_token}")->assertInertia(fn (Assert $page) => $page->where('pay.practice', true)->where('pay.paid_online', 0)->where('pay.can', true));
    }

    public function test_the_buyer_who_pressed_back_or_cancel_closes_only_a_waiting_payment(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);

        $this->back($p, 'cancel', ['val_id' => null])->assertSessionHasErrors('order');
        $this->assertSame('cancelled', $p->fresh()->status);

        $paid = Payment::create(['ref' => 'PAIDONE', 'payable_type' => 'invoice', 'payable_id' => $o->id, 'provider' => 'sslcommerz', 'amount' => 100, 'status' => 'paid', 'live' => true]);
        $this->post('/webhooks/payments/sslcommerz/result/fail', ['tran_id' => 'PAIDONE']);
        $this->post('/webhooks/payments/sslcommerz/result/cancel', ['tran_id' => 'PAIDONE']);
        $this->assertSame('paid', $paid->fresh()->status, 'a paid payment never goes backwards');
    }

    public function test_a_success_that_arrives_without_proof_waits_for_the_gateways_message(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);

        $this->post('/webhooks/payments/sslcommerz/result/success', ['tran_id' => $p->ref])->assertRedirect(route('orders.show', $o->public_token));
        $this->assertSame('failed', $p->fresh()->status);

        $this->fakeValidation($p);
        $this->post('/webhooks/payments/sslcommerz/ipn', ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'VALID']);
        $this->assertSame('paid', $p->fresh()->status, 'the real message still counts afterwards');
    }

    public function test_an_unknown_transaction_goes_home_with_no_hint(): void
    {
        $this->connect();

        $this->post('/webhooks/payments/sslcommerz/result/success', ['tran_id' => 'NOPE', 'val_id' => 'V'])->assertRedirect('/');
    }

    // ------------------------------------------------------------------ what is left to pay, and giving it back

    public function test_after_an_advance_only_the_rest_is_asked_for(): void
    {
        $this->connect(true, 'test');
        $o = $this->order();
        $full = $this->due($o);
        $o->forceFill(['paid_amount' => 100])->save();

        $this->get("/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('pay.amount', fn ($v) => abs($v - ($full - 100)) < 0.001));
    }

    public function test_a_second_try_after_a_failed_one_is_a_new_payment_and_the_first_cannot_pay_twice(): void
    {
        $this->connect();
        $o = $this->order();
        $first = $this->startedPayment($o);
        $this->back($first, 'fail', ['val_id' => null]);
        $second = $this->startedPayment($o);

        $this->assertNotSame($first->ref, $second->ref);
        $this->fakeValidation($second);
        $this->back($second);
        $this->assertEqualsWithDelta((float) $second->amount, (float) $o->fresh()->paid_amount, 0.001);

        // Now nothing is owed, so no further payment can be opened.
        $this->start($o->fresh())->assertSessionHasErrors('order');
    }

    public function test_an_order_cancelled_after_it_was_paid_is_owed_back_and_the_office_is_told(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        $this->fakeValidation($p);
        $this->back($p);

        app(InvoiceService::class)->cancel($o->fresh(), $this->staff);

        $this->assertSame('refund_due', $p->fresh()->status);
        $this->assertGreaterThan(0, Notice::where('kind', 'payment_refund_due')->count());
        $this->actingAs($this->staff)->get('/admin/payments')->assertInertia(fn (Assert $page) => $page->has('refunds', 1)->where('refunds.0.ref', $p->ref));
    }

    public function test_a_payment_that_lands_on_an_already_cancelled_order_is_owed_back_at_once(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        app(InvoiceService::class)->cancel($o->fresh(), $this->staff);
        $this->fakeValidation($p);

        $this->back($p);

        $this->assertSame('refund_due', $p->fresh()->status);
        $this->assertSame(0.0, (float) $o->fresh()->paid_amount);
    }

    public function test_a_practice_payment_on_a_cancelled_order_owes_nothing(): void
    {
        $this->connect(true, 'test');
        $o = $this->order();
        $p = $this->startedPayment($o);
        $this->fakeValidation($p);
        $this->back($p);

        app(InvoiceService::class)->cancel($o->fresh(), $this->staff);

        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(0, Notice::where('kind', 'payment_refund_due')->count());
    }

    public function test_the_office_marks_a_refund_done_once(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        $this->fakeValidation($p);
        $this->back($p);
        app(InvoiceService::class)->cancel($o->fresh(), $this->staff);

        $this->actingAs($this->staff)->post("/admin/payments/{$p->id}/refunded")->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/admin/payments/{$p->id}/refunded")->assertSessionHasErrors('payment');

        $this->assertSame('refunded', $p->fresh()->status);
        $this->assertSame($this->staff->id, $p->fresh()->refunded_by);
        $this->assertSame(1, ActivityLog::where('action', 'payment.refunded')->count());
    }

    // ------------------------------------------------------------------ the office and the secrets

    public function test_only_people_who_handle_orders_see_payments(): void
    {
        $blind = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Product manager')->value('id')]);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($blind)->get('/admin/payments')->assertRedirect('/');
        $this->actingAs($buyer)->get('/admin/payments')->assertRedirect('/');
        $this->get('/admin/payments')->assertRedirect();
        $this->actingAs($this->staff)->get('/admin/payments')->assertOk();
    }

    public function test_the_page_shows_what_came_in_today_and_marks_practice(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        $this->fakeValidation($p);
        $this->back($p);

        $this->actingAs($this->staff)->get('/admin/payments')->assertInertia(fn (Assert $page) => $page->where('today', fn ($v) => abs($v - (float) $p->amount) < 0.001)->has('recent', 1)->where('recent.0.live', true));
    }

    public function test_the_store_password_is_never_on_a_page_or_in_the_log(): void
    {
        $this->connect();
        $o = $this->order();
        $p = $this->startedPayment($o);
        $this->fakeValidation($p);
        $this->back($p);

        $pages = json_encode([
            $this->get("/orders/{$o->public_token}")->viewData('page')['props'],
            $this->actingAs($this->admin)->get('/admin/connections')->viewData('page')['props'],
            $this->actingAs($this->staff)->get('/admin/payments')->viewData('page')['props'],
            ActivityLog::all()->toArray(),
        ]);

        $this->assertStringNotContainsString('STORE-PASS-999', $pages);
    }

    public function test_the_owner_is_given_the_message_address_to_hand_the_gateway(): void
    {
        $this->connect();

        $page = $this->actingAs($this->admin)->get('/admin/connections')->viewData('page')['props'];
        $p = collect(collect($page['types'])->firstWhere('type', 'payment')['providers'])->firstWhere('key', 'payment:sslcommerz');

        $this->assertTrue($p['ready']);
        $this->assertSame(route('payments.ipn', 'sslcommerz'), $p['redirect_uri']);
    }

    public function test_bkash_and_nagad_directly_are_not_built_yet(): void
    {
        $this->assertFalse(config('integrations.providers.payment:bkash.ready'));
        $this->assertFalse(config('integrations.providers.payment:nagad.ready'));
    }
}
