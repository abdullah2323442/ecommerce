<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\CustomerFlags;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PriceEditAndFlagTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private User $admin;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [ // cost 240 a box
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 100]],
        ]);
        $stock->setMasterSync(true);
    }

    private function placed(int $boxes = 1): Invoice
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => $boxes]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_staff_can_change_a_price_above_cost_and_the_total_follows(): void
    {
        $order = $this->placed(2);
        $line = $order->lines()->first();

        $this->actingAs($this->staff)->put("/admin/orders/{$order->id}/lines/{$line->id}/price", ['price' => 260])->assertSessionHasNoErrors();

        $line->refresh();
        $this->assertSame('260.00', (string) $line->unit_price);
        $this->assertSame('manual', $line->rate_layer);
        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");
        $this->assertSame('520.00', (string) $order->fresh()->total);
    }

    public function test_staff_cannot_price_below_cost_and_the_message_does_not_say_the_cost(): void
    {
        $order = $this->placed();
        $line = $order->lines()->first();

        $response = $this->actingAs($this->staff)->put("/admin/orders/{$order->id}/lines/{$line->id}/price", ['price' => 200]);
        $response->assertSessionHasErrors('order');
        $this->assertStringNotContainsString('240', json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame('300.00', (string) $line->fresh()->unit_price, 'the shop price is untouched');
    }

    public function test_the_admin_may_go_below_cost(): void
    {
        $order = $this->placed();
        $line = $order->lines()->first();

        $this->actingAs($this->admin)->put("/admin/orders/{$order->id}/lines/{$line->id}/price", ['price' => 200])->assertSessionHasNoErrors();
        $this->assertSame('200.00', (string) $line->fresh()->unit_price);
    }

    public function test_a_price_cannot_change_after_the_check_is_done(): void
    {
        $order = $this->placed();
        $line = $order->lines()->first();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");

        $this->put("/admin/orders/{$order->id}/lines/{$line->id}/price", ['price' => 300])->assertSessionHasErrors('order');
    }

    public function test_a_shop_is_flagged_after_three_late_cancellations_but_free_cancels_do_not_count(): void
    {
        $flags = app(CustomerFlags::class);

        $early = $this->placed();
        $this->actingAs($this->shop)->post("/paikari/orders/{$early->id}/cancel"); // before any check: free
        $this->assertSame(0, $flags->lateCancellations($this->shop));

        for ($i = 0; $i < 3; $i++) {
            $order = $this->placed();
            $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'full']);
            $this->post("/admin/orders/{$order->id}/complete");
            $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel");
            $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/cancel-decision", ['approve' => true]);
        }

        $this->assertSame(3, $flags->lateCancellations($this->shop));
        $this->assertTrue($flags->isFlagged($this->shop));

        $order = $this->placed();
        $this->actingAs($this->staff)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.flagged', true));
    }

    public function test_the_limit_is_a_setting(): void
    {
        Setting::write('flags.cancel_limit', 1);
        $order = $this->placed();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");
        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel");
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/cancel-decision", ['approve' => true]);

        $this->assertTrue(app(CustomerFlags::class)->isFlagged($this->shop));
    }

    public function test_the_shop_never_sees_that_it_is_flagged(): void
    {
        Setting::write('flags.cancel_limit', 1);
        $order = $this->placed();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");
        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel");
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/cancel-decision", ['approve' => true]);

        $html = $this->get('/paikari')->getContent();
        $this->assertStringNotContainsString('flagged', strtolower($html));
        $this->assertStringNotContainsString('late_cancellations', $html);
    }
}
