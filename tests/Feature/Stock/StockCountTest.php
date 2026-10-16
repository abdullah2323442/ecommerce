<?php

namespace Tests\Feature\Stock;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\StockLot;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockCountService;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockCountTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private StockCountService $counts;

    private User $admin;

    private Medicine $napa;

    private Medicine $seclo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->counts = app(StockCountService::class);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square']);
        $source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        $this->napa = $this->med($company, 'Napa');
        $this->seclo = $this->med(Company::create(['name' => 'Eskayef']), 'Seclo');

        foreach ([$this->napa, $this->seclo] as $m) {
            $this->stock->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $m->id, 'units' => 10, 'shelf' => $m->brand_name === 'Napa' ? 'A1' : 'B2']]]);
        }
    }

    private function med(Company $company, string $brand): Medicine
    {
        return Medicine::create(['company_id' => $company->id, 'brand_name' => $brand, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 100, 'mrp_piece' => 1, 'is_active' => true]);
    }

    public function test_a_count_covers_the_whole_shop_a_company_or_a_shelf(): void
    {
        $this->assertCount(2, $this->counts->start(['scope' => 'all'], $this->admin)->lines);
        $this->assertCount(1, $this->counts->start(['scope' => 'company', 'company_id' => $this->napa->company_id], $this->admin)->lines);
        $this->assertCount(1, $this->counts->start(['scope' => 'shelf', 'shelf' => 'B2'], $this->admin)->lines);
    }

    public function test_the_review_shows_system_against_counted_with_the_taka_difference(): void
    {
        $count = $this->counts->start(['scope' => 'all'], $this->admin);
        $this->counts->record($count, $this->napa, 8); // system 10, found 8: two boxes short at 80 each

        $row = $this->counts->review($count)->firstWhere('medicine_id', $this->napa->id);

        $this->assertSame(-2.0, $row['difference']);
        $this->assertSame(-160.0, $row['difference_value']);

        $pending = $this->counts->review($count)->firstWhere('medicine_id', $this->seclo->id);
        $this->assertNull($pending['difference'], 'not counted yet is pending, not a shortage');
    }

    public function test_sales_during_the_count_are_adjusted_for(): void
    {
        $count = $this->counts->start(['scope' => 'all'], $this->admin);
        $this->stock->sell($this->napa, 3, 95, StockService::SALE_OFFER); // sold after the count started
        $this->counts->record($count, $this->napa, 7); // 10 - 3 sold = 7 expected, 7 counted

        $row = $this->counts->review($count)->firstWhere('medicine_id', $this->napa->id);

        $this->assertSame(7.0, $row['expected']);
        $this->assertSame(0.0, $row['difference']);
    }

    public function test_scanning_the_same_item_again_adds_one_not_a_new_line(): void
    {
        $count = $this->counts->start(['scope' => 'all'], $this->admin);

        $this->counts->scan($count, $this->napa);
        $this->counts->scan($count, $this->napa);
        $line = $this->counts->scan($count, $this->napa);

        $this->assertSame('3.0000', (string) $line->counted_units);
        $this->assertSame(2, $count->lines()->count());
    }

    public function test_only_the_admin_approves_and_approval_adjusts_stock_as_a_count_not_a_sale(): void
    {
        $count = $this->counts->start(['scope' => 'all'], $this->admin);
        $this->counts->record($count, $this->napa, 8);
        $this->counts->record($count, $this->seclo, 12);

        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        try {
            $this->counts->approve($count, $staff);
            $this->fail('Staff must not approve a count.');
        } catch (StockException) {
            $this->assertSame(10.0, $this->stock->onHand($this->napa));
        }

        $done = $this->counts->approve($count, $this->admin);

        $this->assertSame('approved', $done->status);
        $this->assertSame(8.0, $this->stock->onHand($this->napa));
        $this->assertSame(12.0, $this->stock->onHand($this->seclo));
        $this->assertSame('0.00', (string) $done->difference_value, 'two boxes short and two over, both at 80, net out');
        $this->assertDatabaseHas('stock_movements', ['medicine_id' => $this->napa->id, 'type' => 'count', 'units' => -2]);
        $this->assertDatabaseMissing('stock_movements', ['type' => 'sale']);
    }

    public function test_a_closed_count_cannot_be_changed_and_a_paused_one_can_resume(): void
    {
        $count = $this->counts->start(['scope' => 'all'], $this->admin);

        $this->counts->pause($count);
        $this->assertSame('paused', $count->fresh()->status);
        $this->counts->record($count->fresh(), $this->napa, 9); // still open for counting while paused
        $this->counts->pause($count->fresh());
        $this->assertSame('open', $count->fresh()->status);

        $this->counts->cancel($count);
        $this->expectException(StockException::class);
        $this->counts->record($count->fresh(), $this->napa, 5);
    }

    public function test_nothing_changes_until_approval(): void
    {
        $count = $this->counts->start(['scope' => 'all'], $this->admin);
        $this->counts->record($count, $this->napa, 1);

        $this->assertSame(10.0, $this->stock->onHand($this->napa));
        $this->assertSame(2, StockLot::where('units_remaining', '>', 0)->count());
    }
}
