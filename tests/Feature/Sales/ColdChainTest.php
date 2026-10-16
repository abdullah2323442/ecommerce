<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\DeliveryCharge;
use App\Services\Sales\Dispatching;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 11: a medicine that must stay cold never goes by courier. Own delivery or pickup only, packed with ice. */
class ColdChainTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $insulin;

    private Medicine $napa;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name, bool $cold) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'form' => 'injection', 'packaging_type' => 'bottle', 'mrp_piece' => 800, 'is_active' => true,
            'weight_g' => 200, 'is_cold_chain' => $cold,
        ]);
        $this->insulin = $make('Insulin', true);
        $this->napa = $make('Napa', false);

        Setting::write('delivery.shop_district', 'Dhaka');
        Setting::write('delivery.shop_area', 'Mirpur');
        $courier = Courier::create(['name' => 'Steadfast', 'cod_percent' => 1, 'is_active' => true]);
        foreach (['own', 'district', 'outside'] as $zone) {
            $courier->rates()->create(['zone' => $zone, 'first_kg' => 100, 'extra_kg' => 20]);
        }

        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->insulin->id, 'units' => 20], ['medicine_id' => $this->napa->id, 'units' => 20]],
        ]);
        $stock->setMasterSync(true);
    }

    private function items(array $pairs): Collection
    {
        return collect($pairs)->map(fn ($p) => ['medicine' => $p[0], 'unit' => 'piece', 'quantity' => $p[1]]);
    }

    private function quote(array $pairs, string $district, ?string $area = null, string $mode = 'delivery'): array
    {
        return app(DeliveryCharge::class)->quote($this->items($pairs), $mode, $district, $area, 800 * collect($pairs)->sum(fn ($p) => $p[1]));
    }

    private function add(Medicine $m, int $qty = 1): void
    {
        $this->post('/cart', ['medicine_id' => $m->id, 'unit' => 'piece', 'quantity' => $qty])->assertSessionHasNoErrors();
    }

    private function details(array $over = []): array
    {
        return array_merge(['name' => 'Hasan Ali', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Uttara', 'address' => 'House 5, Road 3', 'delivery_mode' => 'delivery'], $over);
    }

    // ------------------------------------------------------------------ the quote

    public function test_a_normal_order_still_goes_by_courier_by_weight(): void
    {
        $q = $this->quote([[$this->napa, 1]], 'Dhaka', 'Uttara');

        $this->assertSame('courier', $q['source']);
        $this->assertFalse($q['cold']);
    }

    public function test_a_cold_item_inside_our_area_goes_by_our_own_delivery_not_a_courier(): void
    {
        $q = $this->quote([[$this->insulin, 1], [$this->napa, 1]], 'Dhaka', 'Uttara');

        $this->assertSame('flat', $q['source']);
        $this->assertTrue($q['cold']);
        $this->assertNull($q['options'][0]['courier_id']);
    }

    public function test_a_cold_item_beyond_our_area_cannot_be_delivered_at_all(): void
    {
        $q = $this->quote([[$this->insulin, 1]], 'Sylhet');

        $this->assertSame('blocked', $q['source']);
        $this->assertSame([], $q['options']);
    }

    public function test_collecting_is_always_possible_for_a_cold_item(): void
    {
        $q = $this->quote([[$this->insulin, 1]], 'Sylhet', null, 'collect');

        $this->assertSame('none', $q['source']);
        $this->assertTrue($q['cold']);
    }

    // ------------------------------------------------------------------ the checkout

    public function test_the_checkout_quote_says_so_and_the_order_is_placed_without_a_courier(): void
    {
        $this->add($this->insulin);

        $this->getJson('/checkout/quote?district=Dhaka&area=Uttara&mode=delivery')->assertOk()->assertJson(['source' => 'flat', 'cold' => true]);

        $this->post('/checkout', $this->details())->assertSessionHasNoErrors();
        $order = Invoice::latest('id')->firstOrFail();
        $this->assertNull($order->courier_id);
        $this->assertSame('delivery', $order->delivery_mode);
    }

    public function test_an_order_to_an_address_only_a_courier_could_reach_is_refused_with_the_way_out(): void
    {
        $this->add($this->insulin);

        $this->getJson('/checkout/quote?district=Sylhet&area=Zindabazar&mode=delivery')->assertOk()->assertJson(['source' => 'blocked']);

        $this->post('/checkout', $this->details(['district' => 'Sylhet', 'area' => 'Zindabazar']))->assertSessionHasErrors('order');
        $this->assertSame(0, Invoice::count());
        $this->assertSame(20.0, app(StockService::class)->onHand($this->insulin));

        // Collecting from the shop works for the same cart.
        config(['brand.address' => 'Mirpur 10, Dhaka']);
        $this->post('/checkout', $this->details(['district' => 'Sylhet', 'area' => 'Zindabazar', 'delivery_mode' => 'collect']))->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::count());
    }

    public function test_moving_an_order_with_a_cold_item_out_of_our_area_is_refused(): void
    {
        $this->add($this->insulin);
        $this->post('/checkout', $this->details())->assertSessionHasNoErrors();
        $order = Invoice::latest('id')->firstOrFail();

        $this->put("/orders/{$order->public_token}/address", $this->details(['district' => 'Sylhet', 'area' => 'Zindabazar']))->assertSessionHasErrors();
        $this->assertSame('Dhaka', $order->fresh()->ship_to['district']);
    }

    // ------------------------------------------------------------------ the staff side

    public function test_staff_see_the_flag_on_the_line_and_the_memo_carries_it(): void
    {
        $this->add($this->insulin);
        $this->add($this->napa);
        $this->post('/checkout', $this->details());
        $order = Invoice::latest('id')->firstOrFail();

        $this->actingAs($this->staff)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p
            ->where('order.lines', fn ($lines) => collect($lines)->firstWhere('name', 'Insulin')['cold'] === true && collect($lines)->firstWhere('name', 'Napa')['cold'] === false));

        $this->get("/memo/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('memo.regular.lines', fn ($lines) => collect($lines)->firstWhere('name', 'Insulin')['cold'] === true));
    }

    public function test_a_cold_order_cannot_be_sent_by_courier_but_can_by_our_own_delivery(): void
    {
        $this->add($this->insulin);
        $this->post('/checkout', $this->details());
        $order = Invoice::latest('id')->firstOrFail();
        $order->update(['status' => 'verified']);

        // An order that somehow carries a courier (placed before the item was flagged).
        $order->update(['courier_id' => Courier::firstOrFail()->id]);
        try {
            app(Dispatching::class)->send($order->fresh(), $this->staff);
            $this->fail('A cold order went by courier.');
        } catch (StockException $e) {
            $this->assertStringContainsString('cold', $e->getMessage());
        }
        $this->assertNull($order->fresh()->sent_at);

        $order->update(['courier_id' => null]);
        $this->assertNotNull(app(Dispatching::class)->send($order->fresh(), $this->staff)->sent_at);
    }
}
