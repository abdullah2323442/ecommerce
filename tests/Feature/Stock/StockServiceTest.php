<?php

namespace Tests\Feature\Stock;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\CompanyPurchaseRate;
use App\Models\Medicine;
use App\Models\MedicinePurchaseRate;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\PurchasePricing;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private Company $company;

    private StockSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->company = Company::create(['name' => 'Square']);
        $this->source = StockSource::create(['name' => 'Square direct', 'type' => 'company', 'default_percent' => 12]);
    }

    private function med(string $brand = 'Napa', float $mrpBox = 100): Medicine
    {
        return Medicine::create([
            'company_id' => $this->company->id, 'brand_name' => $brand, 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_box' => $mrpBox, 'mrp_piece' => $mrpBox / 100, 'is_active' => true,
        ]);
    }

    private function receive(Medicine $m, array $line = [], array $extra = []): void
    {
        $this->stock->receive($this->source, array_merge([
            'invoice_date' => '2026-10-12',
            'lines' => [array_merge(['medicine_id' => $m->id, 'units' => 10], $line)],
        ], $extra));
    }

    private function admin(): User
    {
        return User::factory()->create(['account_type' => AccountType::Admin]);
    }

    // ---- STK-3 / STK-4: the spec's own worked examples

    public function test_discounts_cascade_and_are_never_added(): void
    {
        $this->assertSame(20.8, PurchasePricing::cascade(12, 10));
        $this->assertEqualsWithDelta(79.2, PurchasePricing::line(100, [12, 10], 1)['unit_cost'], 0.0001);
    }

    public function test_a_bonus_becomes_a_true_percent_not_ten_plus_two(): void
    {
        // 10 boxes paid at 100 - 20.8% = 792, plus 2 free = 12 received: 66 each, bought at 34% below MRP.
        $line = PurchasePricing::line(100, [12, 10], 10, 2);

        $this->assertEqualsWithDelta(66.0, $line['unit_cost'], 0.0001);
        $this->assertEqualsWithDelta(34.0, $line['percent'], 0.001);
        $this->assertSame(12.0, $line['received']);
    }

    // ---- STK-2: three layers, product wins over company wins over source

    public function test_the_product_rate_beats_the_company_rate_beats_the_source_default(): void
    {
        $m = $this->med();
        $pricing = app(PurchasePricing::class);

        $this->assertSame(12.0, $pricing->resolvePercent($this->source, $m));

        CompanyPurchaseRate::create(['company_id' => $this->company->id, 'percent' => 15]);
        $this->assertSame(15.0, $pricing->resolvePercent($this->source, $m));

        MedicinePurchaseRate::create(['medicine_id' => $m->id, 'percent' => 18]);
        $this->assertSame(18.0, $pricing->resolvePercent($this->source, $m));
    }

    // ---- receiving

    public function test_receiving_locks_the_taka_cost_and_adds_stock(): void
    {
        $m = $this->med();
        $this->receive($m, ['units' => 10]); // source default 12% -> 88 each

        $lot = StockLot::first();
        $this->assertEqualsWithDelta(88.0, (float) $lot->unit_cost, 0.0001);
        $this->assertSame(10.0, $this->stock->onHand($m));
        $this->assertSame(880.0, $this->stock->stockValue());

        // STK-6/7: the MRP can change, the locked cost cannot.
        $m->update(['mrp_box' => 130]);
        $this->assertEqualsWithDelta(88.0, (float) $lot->fresh()->unit_cost, 0.0001);
    }

    public function test_an_mpo_percent_cascades_on_top_of_the_company_percent(): void
    {
        $m = $this->med();
        $this->receive($m, ['mpo_percent' => 10]);

        $this->assertEqualsWithDelta(79.2, (float) StockLot::first()->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(20.8, (float) StockLot::first()->purchase_percent, 0.001);
    }

    public function test_a_line_with_no_purchase_percent_cannot_be_saved(): void
    {
        $this->source->update(['default_percent' => null]);

        $this->expectException(StockException::class);
        $this->receive($this->med());
    }

    public function test_the_same_invoice_twice_warns_unless_confirmed(): void
    {
        $m = $this->med();
        $this->receive($m, [], ['invoice_number' => 'INV-77']);

        try {
            $this->receive($m, [], ['invoice_number' => 'INV-77']);
            $this->fail('A repeated invoice number should warn.');
        } catch (StockException $e) {
            $this->assertStringContainsString('INV-77', $e->getMessage());
        }

        $this->stock->receive($this->source, ['invoice_number' => 'INV-77', 'invoice_date' => '2026-10-12', 'lines' => [['medicine_id' => $m->id, 'units' => 1]]], null, true);
        $this->assertSame(11.0, $this->stock->onHand($m));
    }

    // ---- lots: not FIFO, profit-aware

    public function test_the_lot_used_is_the_dearest_one_that_still_leaves_a_profit(): void
    {
        $m = $this->med();
        $this->receive($m, ['units' => 10, 'company_percent' => 14]); // cost 86
        $this->receive($m, ['units' => 20, 'company_percent' => 20]); // cost 80
        Setting::write('stock.master_sync', true);

        // Sold at 88 (12% off MRP): both lots profit, take the dearer one (86), keep the cheap lot for deeper discounts.
        $moves = $this->stock->sell($m, 1, 88, StockService::SALE_RETAIL);
        $this->assertEqualsWithDelta(86.0, (float) $moves->first()->unit_cost, 0.0001);

        // Sold at 82: only the 80 lot profits.
        $moves = $this->stock->sell($m, 1, 82, StockService::SALE_RETAIL);
        $this->assertEqualsWithDelta(80.0, (float) $moves->first()->unit_cost, 0.0001);
    }

    public function test_a_sale_can_span_two_lots(): void
    {
        $m = $this->med();
        $this->receive($m, ['units' => 2, 'company_percent' => 14]);
        $this->receive($m, ['units' => 3, 'company_percent' => 20]);

        $moves = $this->stock->sell($m, 4, 95, StockService::SALE_OFFER);

        $this->assertCount(2, $moves);
        $this->assertSame(1.0, $this->stock->onHand($m));
    }

    public function test_selling_more_than_is_in_stock_is_refused(): void
    {
        $m = $this->med();
        $this->receive($m, ['units' => 2]);

        $this->expectException(StockException::class);
        $this->stock->sell($m, 3, 95, StockService::SALE_OFFER);
    }

    // ---- LOS-3 / LOS-5: loss sales

    public function test_staff_can_never_sell_at_a_loss_but_the_admin_can_and_it_is_flagged(): void
    {
        $m = $this->med();
        $this->receive($m, ['units' => 5]); // cost 88

        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        try {
            $this->stock->sell($m, 1, 70, StockService::SALE_OFFER, $staff);
            $this->fail('Staff must not be able to sell at a loss.');
        } catch (StockException) {
            $this->assertSame(5.0, $this->stock->onHand($m), 'a refused sale takes nothing');
        }

        $moves = $this->stock->sell($m, 1, 70, StockService::SALE_OFFER, $this->admin());
        $this->assertTrue($moves->first()->is_loss);
    }

    public function test_a_loss_is_a_price_below_every_lot_and_needs_stock_to_judge(): void
    {
        $m = $this->med();
        $this->assertFalse($this->stock->isLoss($m, 1), 'no stock, no purchase price, nothing to lose');

        $this->receive($m, ['company_percent' => 14]);
        $this->receive($m, ['company_percent' => 20]);

        $this->assertTrue($this->stock->isLoss($m, 79));
        $this->assertFalse($this->stock->isLoss($m, 85), 'the 80 lot still profits at 85');
    }

    // ---- Master Stock Sync

    public function test_paikari_and_retail_sales_do_not_deduct_while_master_sync_is_off(): void
    {
        $m = $this->med();
        $this->receive($m, ['units' => 5]);

        $this->assertNull($this->stock->sell($m, 1, 95, StockService::SALE_RETAIL));
        $this->assertNull($this->stock->sell($m, 1, 95, StockService::SALE_PAIKARI));
        $this->assertSame(5.0, $this->stock->onHand($m));

        $this->stock->sell($m, 1, 95, StockService::SALE_OFFER);
        $this->assertSame(4.0, $this->stock->onHand($m), 'offers always deduct');

        $this->stock->setMasterSync(true);
        $this->stock->sell($m, 1, 95, StockService::SALE_RETAIL);
        $this->assertSame(3.0, $this->stock->onHand($m));
    }

    // ---- corrections

    public function test_only_the_admin_corrects_stock_and_it_needs_a_reason(): void
    {
        $m = $this->med();
        $this->receive($m, ['units' => 10]);

        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        try {
            $this->stock->correct($m, 5, 'lost', $staff);
            $this->fail('Staff must not correct stock.');
        } catch (StockException) {
            $this->assertSame(10.0, $this->stock->onHand($m));
        }

        $this->stock->correct($m, 7, 'broken', $this->admin());
        $this->assertSame(7.0, $this->stock->onHand($m));
        $this->assertDatabaseHas('stock_movements', ['medicine_id' => $m->id, 'type' => 'correction', 'reason' => 'broken', 'units' => -3]);
    }
}
