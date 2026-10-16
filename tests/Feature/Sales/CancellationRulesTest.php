<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\CustomerFlags;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 4.4.9: cancel by yourself before picking, ask after picking, and packed-but-not-collected. */
class CancellationRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari]);
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

    private function placed(): Invoice
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 2]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);

        return Invoice::latest('id')->firstOrFail();
    }

    private function check(Invoice $order): void
    {
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'full']);
    }

    public function test_before_picking_the_shop_cancels_by_itself(): void
    {
        $order = $this->placed();

        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel")->assertRedirect('/paikari');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_after_picking_started_it_is_only_a_request_and_staff_see_it(): void
    {
        $order = $this->placed();
        $this->check($order);

        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->cancel_requested_at);

        $this->actingAs($this->staff)->get('/admin/orders')->assertInertia(fn (Assert $p) => $p->where('orders.0.cancel_requested', true));
        $this->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.cancel_requested', true));
    }

    public function test_staff_can_decline_and_the_order_carries_on(): void
    {
        $order = $this->placed();
        $this->check($order);
        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel");

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/cancel-decision", ['approve' => false])->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->cancel_requested_at);
        $this->assertSame('placed', $order->fresh()->status);
        $this->assertSame(0, app(CustomerFlags::class)->lateCancellations($this->shop));
    }

    public function test_approving_cancels_returns_stock_and_counts_against_the_shop(): void
    {
        $order = $this->placed();
        $this->check($order);
        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel");

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/cancel-decision", ['approve' => true]);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta(10.0, app(StockService::class)->onHand($this->napa), 1e-6);
        $this->assertSame(1, app(CustomerFlags::class)->lateCancellations($this->shop));
    }

    public function test_packed_but_not_collected_refunds_and_counts(): void
    {
        $order = $this->placed();
        $this->check($order);
        $this->post("/admin/orders/{$order->id}/complete");

        $this->post("/admin/orders/{$order->id}/not-collected")->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta(10.0, app(StockService::class)->onHand($this->napa), 1e-6);
        $this->assertSame(1, app(CustomerFlags::class)->lateCancellations($this->shop));
    }

    public function test_only_a_checked_order_can_be_marked_not_collected_and_only_by_staff(): void
    {
        $order = $this->placed();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/not-collected")->assertSessionHasErrors('order');
        $this->actingAs($this->shop)->post("/admin/orders/{$order->id}/not-collected")->assertRedirect('/');
        $this->post("/admin/orders/{$order->id}/cancel-decision", ['approve' => true])->assertRedirect('/');
    }
}
