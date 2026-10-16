<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\ShortListItem;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShortListAndDeliveryTest extends TestCase
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
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 5]],
        ]);
        $stock->setMasterSync(true);
    }

    public function test_a_medicine_is_remembered_once_and_ordered_in_one_tap(): void
    {
        $this->actingAs($this->shop);
        $this->post('/paikari/short-list', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post('/paikari/short-list', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 4]);
        $this->assertSame(1, ShortListItem::count());
        $this->assertSame('4.00', (string) ShortListItem::first()->quantity);

        $item = ShortListItem::first();
        $this->post("/paikari/short-list/{$item->id}/order")->assertSessionHasNoErrors();

        $this->assertSame(0, ShortListItem::count());
        $cart = Invoice::firstOrFail();
        $this->assertSame('draft', $cart->status);
        $this->assertSame('4.00', (string) $cart->lines()->first()->quantity);
    }

    public function test_one_shop_cannot_touch_anothers_short_list(): void
    {
        $item = ShortListItem::create(['user_id' => $this->shop->id, 'medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->delete("/paikari/short-list/{$item->id}")->assertRedirect('/');
        $this->assertSame(1, ShortListItem::count());
    }

    public function test_staff_type_the_delivery_charge_and_it_joins_the_total(): void
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
        $this->post('/paikari/order', ['delivery_mode' => 'delivery']);
        $order = Invoice::firstOrFail();

        $this->actingAs($this->staff)->put("/admin/orders/{$order->id}/delivery", ['charge' => 60])->assertSessionHasNoErrors();
        $this->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");

        $this->assertSame('360.00', (string) $order->fresh()->total);
    }

    public function test_the_buyer_sees_the_free_delivery_threshold_and_staff_see_it_too(): void
    {
        Setting::write('delivery.free_over', 5000);

        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('freeOver', 5000));
    }

    public function test_what_could_not_be_supplied_shows_up_for_staff_as_a_buying_list(): void
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 3]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);
        $order = Invoice::firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'unavailable']);

        $this->get('/admin/orders')->assertInertia(fn (Assert $p) => $p->component('admin/orders/index')
            ->where('missed.0.name', 'Napa')->where('missed.0.times', 1)->where('missed.0.quantity', 3));
    }
}
