<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesReports;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    private Medicine $napa;

    private Medicine $seclo;

    private Medicine $idle;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Robiul']);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Pharmacy', 'area' => 'Mirpur', 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 10])->id]);
        $sq = Company::create(['name' => 'Square']);
        $bx = Company::create(['name' => 'Beximco']);
        $make = fn (Company $c, string $n) => Medicine::create(['company_id' => $c->id, 'brand_name' => $n, 'strength' => '10 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true]);
        $this->napa = $make($sq, 'Napa');
        $this->seclo = $make($bx, 'Seclo');
        $this->idle = $make($sq, 'Idle');
        $this->invoices = app(InvoiceService::class);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [ // cost 240 a box
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 100], ['medicine_id' => $this->seclo->id, 'units' => 100], ['medicine_id' => $this->idle->id, 'units' => 10]],
        ]);
        $stock->setMasterSync(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sell(Medicine $m, int $boxes, bool $shop = true, string $method = 'cash'): void
    {
        $d = $this->invoices->open($shop ? 'paikari' : 'retail', $this->staff, $shop ? $this->shop : null);
        $this->invoices->addLine($d, $m, 'box', $boxes, $this->staff);
        $this->invoices->finalise($d, $this->staff, $method);
    }

    public function test_per_medicine_units_revenue_and_profit(): void
    {
        $this->sell($this->napa, 10); // shop pays 270, cost 240
        $this->sell($this->seclo, 2, false); // retail pays 300

        $rows = app(SalesReports::class)->perMedicine(now()->startOfMonth(), now()->endOfMonth())->keyBy('name');

        $this->assertSame(10.0, $rows['Napa']['units']);
        $this->assertSame(2700.0, $rows['Napa']['revenue']);
        $this->assertSame(300.0, $rows['Napa']['profit']);
        $this->assertSame(120.0, $rows['Seclo']['profit']);
    }

    public function test_boxes_per_day_ideal_level_and_the_order_to_place(): void
    {
        $this->sell($this->napa, 20); // 20 boxes over 20 days so far this month
        $r = app(SalesReports::class)->ordering(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20')->endOfDay())->firstWhere('name', 'Napa');

        $this->assertSame(1.0, $r['per_day']);
        $this->assertSame(10, $r['ideal_level'], '7 lead days plus 3 cushion');
        $this->assertSame(80.0, $r['on_hand']);
        $this->assertSame(0, $r['order']);
        $this->assertSame('less', $r['advice'], '80 boxes at one a day is more than 60 days of stock');
    }

    public function test_buy_more_when_it_will_run_out(): void
    {
        $this->sell($this->idle, 9); // 10 on hand, 9 sold: 1 left, 0.45 a day
        $r = app(SalesReports::class)->ordering(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-20')->endOfDay())->firstWhere('name', 'Idle');

        $this->assertSame('more', $r['advice']);
        $this->assertGreaterThan(0, $r['order']);
    }

    public function test_company_and_customer_rankings_and_what_siam_made_from_each(): void
    {
        $this->sell($this->napa, 10);
        $this->sell($this->seclo, 2, false);
        $svc = app(SalesReports::class);

        $companies = $svc->companies(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame('Square', $companies->first()['company']);

        $c = $svc->customers(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame('Rahim Pharmacy', $c->first()['name']);
        $this->assertSame(2700.0, $c->first()['total']);
        $this->assertSame(300.0, $c->first()['profit']);
        $this->assertSame(1, $c->count(), 'retail has no customer to rank');
    }

    public function test_month_to_month_and_the_same_month_last_year(): void
    {
        $this->sell($this->napa, 10);
        Carbon::setTestNow('2026-09-10 12:00');
        $this->sell($this->napa, 4);
        Carbon::setTestNow('2026-10-20 12:00');

        $this->actingAs($this->admin)->get('/admin/reports?month=2026-10')->assertInertia(fn (Assert $p) => $p
            ->where('totals.sales', 2700)->where('totals.memos', 1)->where('previous.sales', 1080)->where('lastYear.memos', 0));
    }

    public function test_the_day_by_day_breakdown_shows_where_the_money_is(): void
    {
        $this->sell($this->napa, 2); // 540 cash
        Carbon::setTestNow('2026-10-21 09:00');
        $this->sell($this->napa, 1, true, 'due'); // 270 on credit
        Carbon::setTestNow('2026-10-21 12:00');

        $days = app(SalesReports::class)->days(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay());
        $this->assertSame(['2026-10-20', '2026-10-21'], $days->pluck('day')->all());
        $this->assertSame(540.0, $days[0]['cash']);
        $this->assertSame(270.0, $days[1]['due']);
        $this->assertSame(60.0, $days[0]['profit']);
    }

    public function test_cancelled_and_returned_goods_do_not_count_twice(): void
    {
        $d = $this->invoices->open('paikari', $this->staff, $this->shop);
        $this->invoices->addLine($d, $this->napa, 'box', 5, $this->staff);
        $inv = $this->invoices->finalise($d, $this->staff);
        $this->actingAs($this->admin)->post("/admin/counter/{$inv->id}/correct"); // the old memo is cancelled

        $rows = app(SalesReports::class)->perMedicine(now()->startOfMonth(), now()->endOfMonth());
        $this->assertSame(0, $rows->count(), 'the cancelled memo is out, and the new draft is not finished');
    }

    public function test_sector_split_and_the_nightly_summary(): void
    {
        $this->sell($this->napa, 2);
        $this->sell($this->seclo, 1, false);
        $svc = app(SalesReports::class);

        $this->assertSame(['paikari', 'retail'], $svc->bySector(now()->startOfMonth(), now()->endOfMonth())->pluck('kind')->all());
        $n = $svc->nightly();
        $this->assertSame(2, $n['memos']);
        $this->assertSame(840.0, $n['sales']);
        $this->assertSame(840.0, $n['cash_expected']);
    }

    public function test_slow_stock_and_inactive_customers_are_listed(): void
    {
        $this->sell($this->napa, 1);
        Carbon::setTestNow('2026-12-01 12:00');
        $svc = app(SalesReports::class);

        $this->assertContains('Idle', $svc->notMoving(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')->endOfDay())->pluck('name')->all());
        $this->assertSame('Rahim Pharmacy', $svc->inactive(30)->first()['name']);
    }

    public function test_the_export_is_a_csv_with_one_row_per_sale_line(): void
    {
        $this->sell($this->napa, 2);
        $res = $this->actingAs($this->admin)->get('/admin/reports/export?month=2026-10')->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Napa', $csv);
        $this->assertStringContainsString('Robiul', $csv);
        $this->assertCount(2, array_filter(explode("\n", $csv)), 'a header and one line');
    }

    public function test_only_the_admin_sees_reports_because_they_carry_profit(): void
    {
        $this->actingAs($this->staff)->get('/admin/reports')->assertRedirect('/');
        $this->get('/admin/reports/export')->assertRedirect('/');
        $this->actingAs($this->shop)->get('/admin/reports')->assertRedirect('/');
    }
}
