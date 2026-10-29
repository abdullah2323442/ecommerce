<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\OfferProduct;
use App\Models\StockLot;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\OfferProducts;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfferProductTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    private Medicine $napa;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 10])->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 1000, 'mrp_strip' => 333, 'mrp_piece' => 33, 'is_active' => true,
        ]);
        $this->stock = app(StockService::class);
        $this->receive(10, 14); // cost 860
        $this->receive(10, 25); // cost 750: this is the lot that goes on offer
    }

    private function receive(int $boxes, float $percent): void
    {
        $this->stock->receive(StockSource::create(['name' => 'S'.$percent, 'type' => 'company', 'default_percent' => $percent]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => $boxes]],
        ]);
    }

    private function offer(array $over = []): OfferProduct
    {
        $this->actingAs($this->admin)->post('/admin/offer-products', $over + [
            'medicine_id' => $this->napa->id, 'percent' => 20, 'min_boxes' => 5, 'step_percent' => 1, 'visible_to' => ['paikari'], 'stock_mode' => 'in_stock',
        ])->assertSessionHasNoErrors();

        return OfferProduct::latest('id')->firstOrFail();
    }

    public function test_only_the_higher_discount_lot_is_on_offer(): void
    {
        $offer = $this->offer();

        $this->assertEqualsWithDelta(10.0, app(OfferProducts::class)->available($offer), 1e-6, '20 boxes in all, only the 25% lot is on offer');
        $this->assertSame('25.000', (string) $offer->lot_percent);
    }

    public function test_the_price_rises_with_boxes_above_the_minimum(): void
    {
        $offer = $this->offer();
        $svc = app(OfferProducts::class);

        $this->assertSame(800.0, $svc->boxPrice($offer->fresh('medicine'), 5));
        $this->assertSame(790.0, $svc->boxPrice($offer->fresh('medicine'), 6), '1% extra at 6 boxes');
        $this->assertSame(760.0, $svc->boxPrice($offer->fresh('medicine'), 9));
    }

    public function test_each_group_can_have_its_own_rate_and_the_common_rate_fills_the_rest(): void
    {
        $offer = $this->offer(['visible_to' => ['paikari', 'wholesale'], 'viewer_percent' => ['paikari' => 22, 'wholesale' => '']]);
        $svc = app(OfferProducts::class);
        $o = $offer->fresh('medicine');

        $this->assertSame(780.0, $svc->boxPrice($o, 5, 'paikari'), 'its own rate');
        $this->assertSame(800.0, $svc->boxPrice($o, 5, 'wholesale'), 'no own rate: the common one');
        $this->assertSame(800.0, $svc->boxPrice($o, 5), 'nobody named: the common one');
    }

    public function test_a_shop_is_priced_at_its_groups_rate_on_the_order(): void
    {
        $offer = $this->offer(['viewer_percent' => ['paikari' => 22]]);

        $this->actingAs($this->shop)->post("/paikari/offers/{$offer->id}/add", ['boxes' => 5])->assertSessionHasNoErrors();
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);

        $this->assertSame('780.00', (string) Invoice::firstOrFail()->lines()->first()->unit_price);
    }

    public function test_the_deepest_group_rate_must_still_leave_the_margin(): void
    {
        $this->actingAs($this->admin)->post('/admin/offer-products', [
            'medicine_id' => $this->napa->id, 'percent' => 20, 'min_boxes' => 5, 'step_percent' => 1, 'visible_to' => ['paikari', 'wholesale'], 'stock_mode' => 'in_stock',
            'viewer_percent' => ['wholesale' => 24],
        ])->assertSessionHasErrors('offer');
        $this->assertSame(0, OfferProduct::count());
    }

    public function test_a_shop_orders_boxes_from_the_offer_lot_only_when_staff_check_it(): void
    {
        $offer = $this->offer();
        $this->actingAs($this->shop)->post("/paikari/offers/{$offer->id}/add", ['boxes' => 6])->assertSessionHasNoErrors();
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);
        $order = Invoice::firstOrFail();
        $line = $order->lines()->first();

        $this->assertSame('790.00', (string) $line->unit_price);
        $this->assertSame($offer->id, $line->offer_product_id);
        $this->assertEqualsWithDelta(20.0, $this->stock->onHand($this->napa), 1e-6, 'nothing is taken until the check');

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full'])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(4.0, (float) StockLot::where('purchase_percent', '25')->sum('units_remaining'), 1e-6, 'six boxes came off the 25% lot');
        $this->assertEqualsWithDelta(10.0, (float) StockLot::where('purchase_percent', '14')->sum('units_remaining'), 1e-6, 'the 14% lot is untouched');
    }

    public function test_it_deducts_even_when_master_sync_is_off(): void
    {
        $offer = $this->offer();
        $this->assertFalse($this->stock->masterSyncEnabled());

        $inv = app(InvoiceService::class);
        $draft = $inv->open('paikari', $this->staff, $this->shop);
        $inv->addOfferLine($draft, $offer->fresh('medicine'), 5, $this->staff);

        $this->assertEqualsWithDelta(15.0, $this->stock->onHand($this->napa), 1e-6);
    }

    public function test_an_order_can_never_be_more_than_the_offer_and_the_message_hides_the_number(): void
    {
        $offer = $this->offer();

        $response = $this->actingAs($this->shop)->post("/paikari/offers/{$offer->id}/add", ['boxes' => 11]);
        $response->assertSessionHasErrors('order');
        $this->assertStringNotContainsString('10', json_encode(session('errors')->getBag('default')->all()));
        $this->assertSame(0, InvoiceLine::count());
    }

    public function test_under_the_minimum_it_is_an_ordinary_line_at_the_normal_rate(): void
    {
        $offer = $this->offer();

        $this->actingAs($this->shop)->post("/paikari/offers/{$offer->id}/add", ['boxes' => 3])->assertSessionHasNoErrors();
        $line = Invoice::firstOrFail()->lines()->first();

        $this->assertNull($line->offer_product_id);
        $this->assertSame('900.00', (string) $line->unit_price, 'the shop tier rate, 10% off 1000');
    }

    public function test_the_offer_is_never_worse_than_the_shops_own_rate(): void
    {
        $offer = $this->offer(['percent' => 5, 'min_boxes' => 1, 'step_percent' => 0]);
        $this->actingAs($this->shop)->post("/paikari/offers/{$offer->id}/add", ['boxes' => 2]);

        $this->assertSame('900.00', (string) Invoice::firstOrFail()->lines()->first()->unit_price, 'tier 10% beats the 5% offer');
    }

    public function test_changing_the_quantity_reprices_and_dropping_under_the_minimum_returns_to_normal(): void
    {
        $offer = $this->offer();
        $inv = app(InvoiceService::class);
        $draft = $inv->open('paikari', $this->staff, $this->shop);
        $line = $inv->addOfferLine($draft, $offer->fresh('medicine'), 5, $this->staff);

        $line = $inv->setQuantity($line, 8, $this->staff);
        $this->assertSame('770.00', (string) $line->unit_price);
        $this->assertEqualsWithDelta(12.0, $this->stock->onHand($this->napa), 1e-6);

        $line = $inv->setQuantity($line, 2, $this->staff);
        $this->assertNull($line->offer_product_id);
        $this->assertSame('900.00', (string) $line->unit_price);
    }

    public function test_only_visible_offers_show_to_a_shop_and_staff_see_every_one(): void
    {
        $this->offer(['visible_to' => ['public']]);

        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('offers', []));
        $this->actingAs($this->shop)->post('/paikari/offers/'.OfferProduct::first()->id.'/add', ['boxes' => 5])->assertRedirect('/');

        $draft = app(InvoiceService::class)->open('paikari', $this->staff, $this->shop);
        $this->actingAs($this->staff)->get("/admin/counter/{$draft->id}/search?q=Nap")->assertJsonPath('results.0.offer.min_boxes', 5);
    }

    public function test_no_offers_at_retail(): void
    {
        $offer = $this->offer();
        $retail = app(InvoiceService::class)->open('retail', $this->staff);

        $this->actingAs($this->staff)->get("/admin/counter/{$retail->id}/search?q=Nap")->assertJsonPath('results.0.offer', null);
        $this->post("/admin/counter/{$retail->id}/offers/{$offer->id}", ['boxes' => 5])->assertRedirect('/');
    }

    public function test_stock_shows_as_in_stock_or_exact_as_chosen_and_the_search_pops_the_offer_under_the_normal_item(): void
    {
        $this->offer();
        $this->actingAs($this->shop)->get('/paikari/search?q=Napa')->assertJsonPath('results.0.offer.stock', null)->assertJsonPath('results.0.offer.state', 'live');

        OfferProduct::query()->update(['stock_mode' => 'exact']);
        $this->get('/paikari/search?q=Napa')->assertJsonPath('results.0.offer.stock', 10);
    }

    public function test_when_the_offer_stock_runs_out_it_greys_for_ninety_minutes_then_goes(): void
    {
        Carbon::setTestNow('2026-10-12 10:00');
        $offer = $this->offer();
        $this->stock->sell($this->napa, 10, 700, StockService::SALE_OFFER, $this->admin, null, app(OfferProducts::class)->lotIds($offer));

        $this->actingAs($this->shop)->get('/paikari/search?q=Napa')->assertJsonPath('results.0.offer.state', 'sold_out');
        $this->post("/paikari/offers/{$offer->id}/add", ['boxes' => 5])->assertSessionHasErrors('order');

        Carbon::setTestNow('2026-10-12 11:00');
        $this->get('/paikari/search?q=Napa')->assertJsonPath('results.0.offer.state', 'sold_out');

        Carbon::setTestNow('2026-10-12 11:40');
        $this->get('/paikari/search?q=Napa')->assertJsonPath('results.0.offer', null);
        Carbon::setTestNow();
    }

    public function test_new_qualifying_stock_inside_the_month_brings_it_back_and_a_cancelled_draft_restores_it(): void
    {
        $offer = $this->offer();
        $inv = app(InvoiceService::class);
        $draft = $inv->open('paikari', $this->staff, $this->shop);
        $inv->addOfferLine($draft, $offer->fresh('medicine'), 10, $this->staff);
        $this->assertSame('sold_out', app(OfferProducts::class)->state($offer->fresh()));

        $inv->cancel($draft, $this->staff);
        $this->assertSame('live', app(OfferProducts::class)->state($offer->fresh()), 'goods returned to stock, listing is normal again');

        $inv->addOfferLine($inv->open('paikari', $this->staff, $this->shop), $offer->fresh('medicine'), 10, $this->staff);
        $this->assertSame('sold_out', app(OfferProducts::class)->state($offer->fresh()));
        $this->receive(6, 26);
        $this->assertSame('live', app(OfferProducts::class)->state($offer->fresh()));
    }

    public function test_the_admin_is_warned_when_the_offer_is_nearly_gone(): void
    {
        $offer = $this->offer();
        $this->stock->sell($this->napa, 9, 800, StockService::SALE_OFFER, $this->admin, null, app(OfferProducts::class)->lotIds($offer));

        $this->actingAs($this->admin)->get('/admin/offers')->assertInertia(fn (Assert $p) => $p->where('low.0.name', 'Napa')->where('low.0.left', 1));
    }

    public function test_the_rate_must_keep_the_minimum_margin_and_the_medicine_must_be_boxed(): void
    {
        $this->actingAs($this->admin)->post('/admin/offer-products', [
            'medicine_id' => $this->napa->id, 'percent' => 26, 'min_boxes' => 5, 'visible_to' => ['paikari'], 'stock_mode' => 'in_stock',
        ])->assertSessionHasErrors('offer'); // 740 is under 750 plus 3%

        $loose = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Syrup', 'form' => 'syrup', 'packaging_type' => 'pcs', 'mrp_piece' => 100, 'is_active' => true]);
        $this->post('/admin/offer-products', ['medicine_id' => $loose->id, 'percent' => 10, 'min_boxes' => 1, 'visible_to' => ['paikari'], 'stock_mode' => 'in_stock'])->assertSessionHasErrors('offer');
        $this->assertSame(0, OfferProduct::count());
    }

    public function test_only_the_admin_manages_offers(): void
    {
        $this->actingAs($this->staff)->post('/admin/offer-products', ['medicine_id' => $this->napa->id, 'percent' => 20, 'min_boxes' => 5, 'visible_to' => ['paikari'], 'stock_mode' => 'in_stock'])->assertRedirect('/');
        $this->assertSame(0, OfferProduct::count());
    }

    public function test_the_shop_never_sees_the_lot_cost_or_the_purchase_percent(): void
    {
        $this->offer();
        $json = $this->actingAs($this->shop)->get('/paikari/search?q=Napa')->getContent();
        $html = $this->get('/paikari')->getContent();

        foreach ([$json, $html] as $body) {
            $this->assertStringNotContainsString('lot_percent', $body);
            $this->assertStringNotContainsString('purchase_percent', $body);
            $this->assertStringNotContainsString('unit_cost', $body);
        }
    }

    public function test_the_owner_is_warned_once_when_an_offer_runs_low_and_again_after_new_stock(): void
    {
        $offer = $this->offer();   // 10 boxes on offer
        $svc = app(OfferProducts::class);

        Setting::write('offers.alert_boxes', 2);
        $this->assertSame(0, $svc->alertLow(), '10 boxes left is not low.');

        Setting::write('offers.alert_boxes', 12);
        $this->assertSame(1, $svc->alertLow());
        $this->assertSame(0, $svc->alertLow(), 'Told once, not every five minutes.');
        $this->assertDatabaseHas('notices', ['user_id' => $this->admin->id, 'kind' => 'offer_low']);
        $this->assertDatabaseMissing('notices', ['user_id' => $this->shop->id, 'kind' => 'offer_low']);
        $this->assertStringContainsString('Napa', Notice::where('kind', 'offer_low')->firstOrFail()->body);
        $this->assertNotNull($offer->fresh()->low_alerted_at);

        // The offer is healthy again (the level is below what is left): the warning is armed again.
        Setting::write('offers.alert_boxes', 3);
        $svc->alertLow();
        $this->assertNull($offer->fresh()->low_alerted_at);

        Setting::write('offers.alert_boxes', 12);
        $this->assertSame(1, $svc->alertLow(), 'Low again: warned again.');
    }

    public function test_the_alert_command_runs_and_reports(): void
    {
        $this->offer();
        Setting::write('offers.alert_boxes', 12);

        $this->artisan('offers:alert')->expectsOutputToContain('Warned about 1')->assertExitCode(0);
        $this->artisan('offers:alert')->expectsOutputToContain('No offer needs a warning')->assertExitCode(0);
    }

    public function test_the_owner_dashboard_shows_an_offer_that_is_nearly_sold_out(): void
    {
        $this->offer();
        $titles = fn () => collect($this->actingAs($this->admin)->get('/dashboard')->viewData('page')['props']['alerts'])->pluck('title');

        Setting::write('offers.alert_boxes', 2);
        $this->assertFalse($titles()->contains('An offer is nearly sold out: {names}'));

        Setting::write('offers.alert_boxes', 12);
        $this->assertTrue($titles()->contains('An offer is nearly sold out: {names}'));
    }
}
