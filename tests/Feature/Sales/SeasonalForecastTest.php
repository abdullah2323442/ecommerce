<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\SalesReports;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 6.9: the seasonal forecast, "last year this sold a lot at this time". */
class SeasonalForecastTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $admin;

    private Medicine $cough;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-11-20 10:00:00');
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square']);
        $mk = fn (string $n) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $n, 'strength' => '100 ml', 'form' => 'syrup', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10,
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->cough = $mk('Cough syrup');
        $this->napa = $mk('Napa');
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->cough->id, 'units' => 200], ['medicine_id' => $this->napa->id, 'units' => 200]],
        ]);
        $stock->setMasterSync(true);
    }

    private function sell(Medicine $m, float $boxes): void
    {
        $svc = app(InvoiceService::class);
        $draft = $svc->open('retail', $this->staff);
        $svc->addLine($draft, $m, 'box', $boxes, $this->staff);
        $svc->finalise($draft, $this->staff);
    }

    /** This time last year: cough syrup sold a lot, Napa the same all year. Then it is a year later. */
    private function lastYearThenNow(float $coughLastYear = 30, float $coughNow = 0): void
    {
        $this->sell($this->cough, $coughLastYear);
        $this->sell($this->napa, 15);
        Carbon::setTestNow('2026-11-20 10:00:00');
        if ($coughNow > 0) {
            Carbon::setTestNow('2026-11-10 10:00:00');
            $this->sell($this->cough, $coughNow);
            $this->sell($this->napa, 15);
            Carbon::setTestNow('2026-11-20 10:00:00');
        } else {
            Carbon::setTestNow('2026-11-10 10:00:00');
            $this->sell($this->napa, 15);
            Carbon::setTestNow('2026-11-20 10:00:00');
        }
    }

    public function test_a_medicine_that_sold_a_lot_then_and_little_now_is_flagged(): void
    {
        $this->lastYearThenNow();

        $rows = app(SalesReports::class)->seasonal();

        $this->assertCount(1, $rows, 'Napa sells the same all year, so it is not news');
        $this->assertSame('Cough syrup', $rows[0]['name']);
        $this->assertSame(30.0, $rows[0]['units']);
        $this->assertSame(1.0, $rows[0]['then_per_day']);
        $this->assertSame(0.0, $rows[0]['now_per_day']);
        $this->assertNull($rows[0]['rise'], 'nothing sold now, so there is no ratio');
    }

    public function test_how_many_to_have_is_last_years_pace_for_the_lead_time_and_cushion_less_what_is_on_hand(): void
    {
        $this->lastYearThenNow();

        $r = app(SalesReports::class)->seasonal()[0];

        // 1 a day for 7 + 3 days = 10; 200 - 30 are still on the shelf, so nothing to order.
        $this->assertSame(170.0, $r['on_hand']);
        $this->assertSame(0, $r['order']);
    }

    public function test_it_says_how_many_to_order_when_the_shelf_is_nearly_bare(): void
    {
        $this->lastYearThenNow(190);

        $r = app(SalesReports::class)->seasonal()->firstWhere('name', 'Cough syrup');

        // 190 in 30 days is 6.33 a day; for 10 days that is 63.3; only 10 are left, so 54 more.
        $this->assertSame(10.0, $r['on_hand']);
        $this->assertSame(54, $r['order']);
    }

    public function test_a_medicine_already_selling_as_much_is_not_flagged(): void
    {
        $this->lastYearThenNow(30, 30);

        $this->assertCount(0, app(SalesReports::class)->seasonal());
    }

    public function test_a_little_is_not_a_season(): void
    {
        $this->lastYearThenNow(3);

        $this->assertCount(0, app(SalesReports::class)->seasonal(), 'under five units last year says nothing');
    }

    public function test_sales_outside_the_window_last_year_do_not_count(): void
    {
        Carbon::setTestNow('2025-08-01 10:00:00');
        $this->sell($this->cough, 40);
        Carbon::setTestNow('2026-11-20 10:00:00');

        $this->assertCount(0, app(SalesReports::class)->seasonal());
    }

    public function test_the_report_page_carries_it(): void
    {
        $this->lastYearThenNow();

        $this->actingAs($this->admin)->get('/admin/reports?month=2026-11')->assertInertia(fn (Assert $p) => $p->has('season', 1)->where('season.0.name', 'Cough syrup'));
    }

    public function test_staff_without_reports_do_not_see_it(): void
    {
        $this->actingAs($this->staff)->get('/admin/reports')->assertRedirect('/');
    }
}
