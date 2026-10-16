<?php

namespace Tests\Feature\Sales;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 20: delivery zones can be turned off outside a set area; the buyer can still collect from the shop. */
class DeliveryZoneTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['brand.address' => 'Shop 4, Mirpur']);
        Setting::write('delivery.shop_district', 'Dhaka');
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]]]);
        $stock->setMasterSync(true);
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
    }

    private function order(string $district, string $mode = 'delivery')
    {
        return $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => $district, 'area' => 'Centre', 'address' => 'House 5', 'delivery_mode' => $mode]);
    }

    public function test_by_default_we_deliver_outside_the_district_too(): void
    {
        $this->order('Sylhet')->assertSessionHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    public function test_switched_off_an_order_to_another_district_is_refused_and_nothing_is_made(): void
    {
        Setting::write('delivery.outside_enabled', false);

        $this->order('Sylhet')->assertSessionHasErrors('order');

        $this->assertSame(0, Invoice::count());
    }

    public function test_switched_off_our_own_district_and_collecting_still_work(): void
    {
        Setting::write('delivery.outside_enabled', false);

        $this->order('Dhaka')->assertSessionHasNoErrors();
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->order('Sylhet', 'collect')->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
    }

    public function test_the_checkout_says_so_before_the_buyer_tries(): void
    {
        Setting::write('delivery.outside_enabled', false);

        $this->getJson('/checkout/quote?mode=delivery&district=Sylhet&area=Centre')->assertOk()->assertJsonPath('source', 'closed')->assertJsonPath('options', []);
        $this->getJson('/checkout/quote?mode=delivery&district=Dhaka&area=Centre')->assertOk()->assertJsonPath('source', 'flat');
    }

    public function test_changing_the_address_to_a_closed_district_is_refused(): void
    {
        $this->order('Dhaka')->assertSessionHasNoErrors();
        $order = Invoice::firstOrFail();
        Setting::write('delivery.outside_enabled', false);

        $this->put("/orders/{$order->public_token}/address", ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Sylhet', 'area' => 'Centre', 'address' => 'House 9'])->assertSessionHasErrors('order');

        $this->assertSame('Dhaka', $order->fresh()->ship_to['district']);
    }
}
