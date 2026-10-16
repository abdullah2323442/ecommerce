<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnlineOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 5]],
        ]);
        $stock->setMasterSync(true);
    }

    private function stock(): float
    {
        return app(StockService::class)->onHand($this->napa);
    }

    private function placed(float $boxes = 3): Invoice
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => $boxes])->assertSessionHasNoErrors();
        $this->post('/paikari/order', ['delivery_mode' => 'collect'])->assertSessionHasNoErrors();

        return Invoice::firstOrFail();
    }

    public function test_only_a_shop_account_reaches_the_portal(): void
    {
        $this->get('/paikari')->assertRedirect();
        foreach ([AccountType::Public, AccountType::Wholesaler, AccountType::Staff, AccountType::Admin] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get('/paikari')->assertRedirect('/');
        }
        $this->actingAs($this->shop)->get('/paikari')->assertOk();
    }

    public function test_search_shows_the_shops_own_rate_and_never_stock(): void
    {
        $json = $this->actingAs($this->shop)->get('/paikari/search?q=Napa')->assertOk()->assertJsonPath('results.0.units.0.price', 270)->getContent();

        $this->assertStringNotContainsStringIgnoringCase('stock', $json);
        $this->assertStringNotContainsStringIgnoringCase('units_remaining', $json);
        $this->assertStringNotContainsStringIgnoringCase('cost', $json);
    }

    public function test_a_cart_and_a_placed_order_take_no_stock_until_staff_check(): void
    {
        $order = $this->placed(3);

        $this->assertSame('placed', $order->status);
        $this->assertEqualsWithDelta(5.0, $this->stock(), 1e-6);
        $this->assertSame('pending', $order->lines()->first()->verify_status);
    }

    public function test_staff_check_full_partial_and_not_available(): void
    {
        $order = $this->placed(3);
        $line = $order->lines()->first();
        $this->actingAs($this->staff);

        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasErrors('order'); // a line is still unchecked

        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'partial', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(3.0, $this->stock(), 1e-6);

        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(2.0, $this->stock(), 1e-6, 're-checking puts the first check back first');

        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('verified', $order->status);
        $this->assertSame('810.00', (string) $order->total);
    }

    public function test_a_partial_must_be_between_zero_and_the_request(): void
    {
        $order = $this->placed(3);
        $line = $order->lines()->first();
        $this->actingAs($this->staff);

        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'partial', 'quantity' => 3])->assertSessionHasErrors('order');
    }

    public function test_the_buyer_sees_the_check_but_no_stock_figures(): void
    {
        $order = $this->placed(9);
        $line = $order->lines()->first();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'partial', 'quantity' => 4]);

        $html = $this->actingAs($this->shop)->get("/paikari/orders/{$order->id}")->assertOk()->getContent();
        $this->assertStringContainsString('&quot;check&quot;:&quot;partial&quot;', $html);
        $this->assertStringNotContainsStringIgnoringCase('units_remaining', $html);
        $this->assertStringNotContainsStringIgnoringCase('unit_cost', $html);
    }

    public function test_nothing_available_cancels_the_order(): void
    {
        $order = $this->placed(3);
        $line = $order->lines()->first();
        $this->actingAs($this->staff);
        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'unavailable']);
        $this->post("/admin/orders/{$order->id}/complete");

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEqualsWithDelta(5.0, $this->stock(), 1e-6);
    }

    public function test_cancelling_a_checked_order_puts_the_stock_back(): void
    {
        $order = $this->placed(3);
        $line = $order->lines()->first();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);
        $this->assertEqualsWithDelta(2.0, $this->stock(), 1e-6);

        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/cancel")->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(2.0, $this->stock(), 1e-6, 'only a request: staff have not agreed yet');

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/cancel-decision", ['approve' => true]);
        $this->assertEqualsWithDelta(5.0, $this->stock(), 1e-6);
    }

    public function test_another_shop_cannot_open_the_order(): void
    {
        $order = $this->placed(1);
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->get("/paikari/orders/{$order->id}")->assertRedirect('/');
        $this->post("/paikari/orders/{$order->id}/cancel")->assertRedirect('/');
    }

    public function test_a_checked_order_is_finished_at_the_counter_with_a_memo_number(): void
    {
        $order = $this->placed(2);
        $line = $order->lines()->first();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");

        $this->post("/admin/counter/{$order->id}/finalise", ['payment_method' => 'due'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('final', $order->status);
        $this->assertSame('M'.now()->year.'-000001', $order->number);
        $this->assertSame('540.00', (string) $order->total);
    }
}
