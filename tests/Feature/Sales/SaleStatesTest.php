<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\Dispatching;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesReports;
use App\Services\Sales\TierUpgrade;
use App\Services\Sales\VatReport;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Spec 6.0: a draft sale (goods not yet with the buyer) and a complete sale are kept apart; reports, VAT and the tier month count only complete ones. */
class SaleStatesTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $shop;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-29 10:00:00');
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 0])->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 50]]]);
        $stock->setMasterSync(true);
    }

    private function counterSale(float $boxes = 1): Invoice
    {
        $svc = app(InvoiceService::class);
        $d = $svc->open('retail', $this->staff);
        $svc->addLine($d, $this->napa, 'box', $boxes, $this->staff);

        return $svc->finalise($d, $this->staff);
    }

    /** An online order from a shop, checked and finished at the counter. */
    private function onlineOrder(string $mode): Invoice
    {
        $svc = app(InvoiceService::class);
        $o = $svc->open('paikari', null, $this->shop, 'online');
        $svc->addLine($o, $this->napa, 'box', 2, $this->staff);
        $o->update(['delivery_mode' => $mode]);
        $svc->place($o);
        foreach ($o->lines()->get() as $l) {
            $svc->verifyLine($l, 'full', null, $this->staff);
        }
        $svc->completeVerification($o->fresh(), $this->staff);

        return $svc->finalise($o->fresh(), $this->staff);
    }

    private function sales(): float
    {
        return app(SalesReports::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30'))['sales'];
    }

    public function test_a_counter_sale_is_complete_at_once(): void
    {
        $sale = $this->counterSale();

        $this->assertNotNull($sale->completed_at);
        $this->assertEquals(300.0, $this->sales());
    }

    public function test_an_order_to_send_is_a_draft_sale_until_it_arrives_and_is_not_in_the_reports(): void
    {
        $order = $this->onlineOrder('send');
        app(Dispatching::class)->send($order, $this->staff);

        $this->assertSame('final', $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
        $this->assertEquals(0.0, $this->sales(), 'goods not yet with the buyer: not a sale in the reports');
    }

    public function test_it_becomes_a_complete_sale_when_the_buyer_gets_it_and_counts_on_that_day(): void
    {
        $order = $this->onlineOrder('send');
        app(Dispatching::class)->send($order, $this->staff);

        Carbon::setTestNow('2026-11-02 12:00:00');
        app(Dispatching::class)->received($order->fresh());

        $this->assertNotNull($order->fresh()->completed_at);
        $this->assertEquals(0.0, app(SalesReports::class)->totals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['sales'], 'not in October, when it only left the shop');
        $this->assertEquals(600.0, app(SalesReports::class)->totals(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'))['sales'], 'in November, the day it arrived');
    }

    public function test_an_order_to_collect_is_complete_when_the_counter_finishes_it(): void
    {
        $order = $this->onlineOrder('collect');

        $this->assertNotNull($order->completed_at);
        $this->assertEquals(600.0, $this->sales());
    }

    public function test_a_confirmation_that_comes_by_itself_after_days_completes_it_too(): void
    {
        $order = $this->onlineOrder('send');
        app(Dispatching::class)->send($order, $this->staff);

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->assertSame(1, app(Dispatching::class)->autoConfirm());

        $this->assertNotNull($order->fresh()->completed_at);
    }

    public function test_the_vat_report_and_the_shops_tier_month_count_only_complete_sales(): void
    {
        $order = $this->onlineOrder('send');
        app(Dispatching::class)->send($order, $this->staff);

        $this->assertSame(0, count(app(VatReport::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['memos']));
        $this->assertEquals(0.0, app(TierUpgrade::class)->monthTotal($this->shop));

        app(Dispatching::class)->received($order->fresh());

        $this->assertSame(1, count(app(VatReport::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))['memos']));
        $this->assertEquals(600.0, app(TierUpgrade::class)->monthTotal($this->shop));
    }

    public function test_a_buyer_who_says_it_did_not_arrive_leaves_it_out_of_the_reports(): void
    {
        $order = $this->onlineOrder('send');
        app(Dispatching::class)->send($order, $this->staff);
        app(Dispatching::class)->notReceived($order->fresh());

        $this->assertNull($order->fresh()->completed_at);
        $this->assertEquals(0.0, $this->sales());
    }

    public function test_the_best_customers_list_leaves_out_a_draft_sale(): void
    {
        $order = $this->onlineOrder('send');
        app(Dispatching::class)->send($order, $this->staff);

        $this->assertCount(0, app(SalesReports::class)->customers(Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30')));
    }
}
