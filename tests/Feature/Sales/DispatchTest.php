<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\Dispatching;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 20: send an order (with any extra charge), the buyer sees "on the way" and says it arrived or not, or it confirms itself. */
class DispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $shop;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 10])->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20]],
        ]);
        $stock->setMasterSync(true);
    }

    /** A guest order that staff have checked, ready to send. */
    private function checkedGuest(string $mode = 'delivery'): Invoice
    {
        auth()->logout();   // a visitor, not staff
        config(['brand.address' => 'Shop 4']);
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => $mode]);

        return $this->check(Invoice::latest('id')->firstOrFail());
    }

    private function check(Invoice $order): Invoice
    {
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();
        auth()->logout();

        return $order->fresh();
    }

    private function shopOrder(): Invoice
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
        $this->post('/paikari/order', ['delivery_mode' => 'send']);

        return $this->check(Invoice::latest('id')->firstOrFail());
    }

    public function test_a_checked_order_is_sent_with_a_tracking_note_and_an_extra_charge_that_is_added(): void
    {
        $order = $this->checkedGuest();
        $this->assertSame('60.00', (string) $order->delivery_charge);

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send", ['tracking' => 'PT-4471', 'extra' => 50])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->sent_at);
        $this->assertSame('PT-4471', $order->tracking);
        $this->assertSame('50.00', (string) $order->delivery_extra);
        $this->assertSame('110.00', (string) $order->delivery_charge);
        $this->assertSame(1, ActivityLog::where('action', 'order.sent')->count());
    }

    public function test_a_shop_order_can_share_the_courier_charge_50_50_or_take_a_fixed_amount_and_retail_cannot(): void
    {
        $order = $this->shopOrder();   // charge as quoted
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send", ['terms' => 'split'])->assertSessionHasErrors('order');   // the courier cost is needed

        $this->post("/admin/orders/{$order->id}/send", ['terms' => 'split', 'courier_cost' => 400, 'cartons' => 3])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('200.00', (string) $order->delivery_charge, 'half of 400');
        $this->assertSame('split', $order->courier_terms);
        $this->assertSame(3, $order->cartons);

        $fixed = $this->shopOrder();
        $this->actingAs($this->staff)->post("/admin/orders/{$fixed->id}/send", ['terms' => 'fixed', 'courier_cost' => 150])->assertSessionHasNoErrors();
        $this->assertSame('150.00', (string) $fixed->fresh()->delivery_charge);

        $retail = $this->checkedGuest();
        $this->actingAs($this->staff)->post("/admin/orders/{$retail->id}/send", ['terms' => 'split', 'courier_cost' => 400])->assertSessionHasErrors('order');
        $this->assertNull($retail->fresh()->sent_at);
    }

    public function test_only_a_checked_order_that_is_to_be_sent_can_be_sent_and_only_once(): void
    {
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'X', 'address' => 'Y', 'delivery_mode' => 'delivery']);
        $unchecked = Invoice::firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$unchecked->id}/send")->assertSessionHasErrors('order');
        auth()->logout();

        $collect = $this->checkedGuest('collect');
        $this->actingAs($this->staff)->post("/admin/orders/{$collect->id}/send")->assertSessionHasErrors('order');

        $ready = $this->checkedGuest();
        $this->actingAs($this->staff)->post("/admin/orders/{$ready->id}/send", ['extra' => -5])->assertSessionHasErrors('extra');
        $this->post("/admin/orders/{$ready->id}/send")->assertSessionHasNoErrors();
        $this->post("/admin/orders/{$ready->id}/send")->assertSessionHasErrors('order');
    }

    public function test_a_big_order_pays_its_delivery_charge_first_and_a_small_one_does_not(): void
    {
        Setting::write('delivery.advance_over', 150);
        $big = $this->checkedGuest();   // two strips: 200, over the line

        $this->assertTrue(app(Dispatching::class)->advanceMissing($big));
        $this->actingAs($this->staff)->post("/admin/orders/{$big->id}/send")->assertSessionHasErrors('order');
        $this->assertNull($big->fresh()->sent_at);

        $this->post("/admin/orders/{$big->id}/delivery-advance")->assertSessionHasNoErrors();
        $this->assertNotNull($big->fresh()->delivery_advance_at);
        $this->post("/admin/orders/{$big->id}/send")->assertSessionHasNoErrors();
        $this->assertNotNull($big->fresh()->sent_at);

        Setting::write('delivery.advance_over', 500);
        $small = $this->checkedGuest();
        $this->assertFalse(app(Dispatching::class)->advanceMissing($small));
        $this->actingAs($this->staff)->post("/admin/orders/{$small->id}/delivery-advance")->assertSessionHasErrors('order');
        $this->post("/admin/orders/{$small->id}/send")->assertSessionHasNoErrors();
    }

    public function test_cancelled_before_sending_half_the_paid_delivery_charge_goes_back_and_sent_means_none(): void
    {
        Setting::write('delivery.advance_over', 150);
        $order = $this->checkedGuest();
        $charge = (float) $order->delivery_charge;
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/delivery-advance");
        app(InvoiceService::class)->cancel($order->fresh(), $this->staff, 'Out of stock');

        $this->assertEqualsWithDelta($charge / 2, (float) $order->fresh()->delivery_refund, 0.011);
        $this->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.delivery_refund', (int) ($charge / 2)));

        $unpaid = $this->checkedGuest();
        app(InvoiceService::class)->cancel($unpaid->fresh(), $this->staff);
        $this->assertSame(0.0, (float) $unpaid->fresh()->delivery_refund, 'nothing was paid, nothing goes back');

        $sent = $this->checkedGuest();
        $this->actingAs($this->staff)->post("/admin/orders/{$sent->id}/delivery-advance");
        $this->post("/admin/orders/{$sent->id}/send");
        $this->assertSame(0.0, app(Dispatching::class)->deliveryRefundFor($sent->fresh()), 'sent, so the whole charge stays');
    }

    public function test_the_buyer_is_told_why_and_it_is_off_by_default(): void
    {
        $order = $this->checkedGuest();
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.advance_needed', false));

        Setting::write('delivery.advance_over', 150);
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.advance_needed', true));
    }

    public function test_an_account_holder_is_told_it_is_on_the_way_with_the_tracking_and_a_guest_is_not(): void
    {
        $guest = $this->checkedGuest();
        $this->actingAs($this->staff)->post("/admin/orders/{$guest->id}/send")->assertSessionHasNoErrors();
        $this->assertSame(0, Notice::count());

        $order = $this->shopOrder();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send", ['tracking' => 'SF-9'])->assertSessionHasNoErrors();

        $notice = Notice::where('kind', 'order_sent')->firstOrFail();
        $this->assertSame($this->shop->id, $notice->user_id);
        $this->assertSame('SF-9', json_decode($notice->body, true)['tracking']);
    }

    public function test_the_memo_shows_the_extra_delivery_charge_on_its_own_line(): void
    {
        $order = $this->checkedGuest();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send", ['extra' => 40]);
        $this->post("/admin/counter/{$order->id}/finalise")->assertSessionHasNoErrors();
        auth()->logout();

        $this->get("/memo/t/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('memo.delivery', 100)->where('memo.delivery_extra', 40));
    }

    public function test_a_guest_sees_it_on_the_way_and_says_it_arrived_or_that_it_did_not(): void
    {
        $order = $this->checkedGuest();
        $token = $order->public_token;

        $this->post("/orders/{$token}/received")->assertSessionHasErrors('order');   // not sent yet
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send", ['tracking' => 'PT-1']);
        auth()->logout();

        $this->get("/orders/{$token}")->assertInertia(fn (Assert $p) => $p->where('order.sent_at', fn ($v) => $v !== null)->where('order.tracking', 'PT-1')->where('order.delivered', false)->where('order.not_received', false));

        $this->post("/orders/{$token}/received")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertNotNull($order->delivered_at);
        $this->assertSame('buyer', $order->delivered_by);
        $this->post("/orders/{$token}/received")->assertSessionHasNoErrors();   // saying it twice changes nothing
        $this->post("/orders/{$token}/not-received")->assertSessionHasErrors('order');
    }

    public function test_not_received_is_reported_flagged_for_the_owner_and_never_confirms_itself(): void
    {
        $order = $this->checkedGuest();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send");
        auth()->logout();
        $order->update(['sent_at' => now()->subDays(20)]);

        $this->post("/orders/{$order->public_token}/not-received")->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->not_received_at);
        $this->assertSame(0, app(Dispatching::class)->autoConfirm());
        $this->assertNull($order->fresh()->delivered_at);

        $this->actingAs($this->staff)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.not_received', true));

        $this->post("/admin/orders/{$order->id}/not-received", ['arrived' => true])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertNotNull($order->delivered_at);
        $this->assertNull($order->not_received_at);
    }

    public function test_the_owner_can_settle_a_report_as_lost(): void
    {
        $order = $this->checkedGuest();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send");
        $this->post("/orders/{$order->public_token}/not-received");

        $this->post("/admin/orders/{$order->id}/not-received", ['arrived' => false])->assertSessionHasNoErrors();

        $this->assertStringContainsString('Lost on the way', (string) $order->fresh()->note);
        $this->assertNull($order->fresh()->delivered_at);
        $this->post("/admin/orders/{$order->id}/not-received", ['arrived' => 'maybe'])->assertSessionHasErrors('arrived');
    }

    public function test_an_order_with_no_answer_confirms_itself_after_the_set_days(): void
    {
        $old = $this->checkedGuest();
        $recent = $this->checkedGuest();
        $this->actingAs($this->staff);
        $this->post("/admin/orders/{$old->id}/send");
        $this->post("/admin/orders/{$recent->id}/send");
        $old->update(['sent_at' => now()->subDays(8)]);
        $recent->update(['sent_at' => now()->subDays(3)]);

        $this->assertSame(1, app(Dispatching::class)->autoConfirm());
        $this->assertSame('auto', $old->fresh()->delivered_by);
        $this->assertNull($recent->fresh()->delivered_at);

        Setting::write('delivery.auto_confirm_days', 2);
        $this->assertSame(1, app(Dispatching::class)->autoConfirm());
        $this->assertNotNull($recent->fresh()->delivered_at);
    }

    public function test_a_shop_confirms_from_its_own_portal_and_no_other_shop_can(): void
    {
        $order = $this->shopOrder();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/send");
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->post("/paikari/orders/{$order->id}/received")->assertRedirect('/');
        $this->assertNull($order->fresh()->delivered_at);

        $this->actingAs($this->shop)->get("/paikari/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.sent_at', fn ($v) => $v !== null)->where('order.delivered', false));
        $this->post("/paikari/orders/{$order->id}/received")->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_sending_is_for_those_who_handle_orders_and_the_settle_buttons_need_a_report_to_settle(): void
    {
        $order = $this->checkedGuest();

        $this->actingAs($this->shop)->post("/admin/orders/{$order->id}/send")->assertRedirect('/');
        $this->post("/admin/orders/{$order->id}/not-received", ['arrived' => true])->assertRedirect('/');
        $this->assertNull($order->fresh()->sent_at);

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/not-received", ['arrived' => true])->assertRedirect('/');   // nothing was reported, so there is nothing to settle
    }
}
