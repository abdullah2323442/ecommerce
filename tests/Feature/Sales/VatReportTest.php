<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\ReturnService;
use App\Services\Sales\VatReport;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 25: the monthly VAT (Mushak) report for the accountant, by rate, with returns taken out, a date range, and a file to download. */
class VatReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    private Medicine $napa;

    private Medicine $plain;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        Setting::write('vat.enabled', true);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Counter staff')->value('id')]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'business_name' => 'Rahim Pharmacy', 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 0])->id]);
        $company = Company::create(['name' => 'Square']);
        $mk = fn (string $name, ?float $vat) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10,
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true, 'vat_percent' => $vat,
        ]);
        $this->napa = $mk('Napa', 15);
        $this->plain = $mk('Plain', null);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20], ['medicine_id' => $this->plain->id, 'units' => 20]],
        ]);
        $stock->setMasterSync(true);
    }

    /** A finished sale: retail by default, or to the shop. */
    private function sale(Medicine $m, float $strips, string $kind = 'retail', ?float $endDiscount = null): Invoice
    {
        $svc = app(InvoiceService::class);
        $draft = $svc->open($kind, $this->staff, $kind === 'retail' ? null : $this->shop);
        $svc->addLine($draft, $m, 'strip', $strips, $this->staff);
        if ($endDiscount !== null) {
            $draft->update(['end_discount_percent' => $endDiscount]);
        }

        return $svc->finalise($draft, $this->staff);
    }

    private function report(string $from = '2026-10-01', string $to = '2026-10-31', ?string $kind = null): array
    {
        return app(VatReport::class)->build(Carbon::parse($from), Carbon::parse($to), $kind);
    }

    public function test_sales_are_grouped_by_rate_with_the_vat_inside_the_price(): void
    {
        $this->sale($this->napa, 2);     // 200 at 15%: VAT 26.09
        $this->sale($this->napa, 1);     // 100 at 15%: VAT 13.04
        $this->sale($this->plain, 3);    // 300, no VAT

        $r = $this->report();

        $this->assertCount(2, $r['rows']);
        $this->assertSame(15.0, $r['rows'][0]['rate']);
        $this->assertSame(2, $r['rows'][0]['memos']);
        $this->assertSame(300.0, $r['rows'][0]['sales']);
        $this->assertSame(39.13, $r['rows'][0]['vat']);
        $this->assertSame(0.0, $r['rows'][1]['rate']);
        $this->assertSame(300.0, $r['rows'][1]['sales']);
        $this->assertSame(0.0, $r['rows'][1]['vat']);
        $this->assertSame(600.0, $r['totals']['sales']);
        $this->assertSame(39.13, $r['totals']['net_vat']);
        $this->assertSame(560.87, $r['totals']['without_vat']);
        $this->assertCount(3, $r['memos']);
    }

    public function test_the_end_discount_lowers_the_sale_and_its_vat_together(): void
    {
        $this->sale($this->napa, 2, 'retail', 10);     // 200 less 10% = 180; the VAT in it is 26.09 less 10%

        $r = $this->report();

        $this->assertSame(180.0, $r['rows'][0]['sales']);
        $this->assertSame(23.48, $r['rows'][0]['vat']);
    }

    public function test_only_finished_memos_in_the_period_count(): void
    {
        $this->sale($this->napa, 2);
        $svc = app(InvoiceService::class);
        $draft = $svc->open('retail', $this->staff);
        $svc->addLine($draft, $this->napa, 'strip', 1, $this->staff);   // a draft: not a sale yet
        $cancelled = $svc->open('retail', $this->staff);
        $svc->addLine($cancelled, $this->napa, 'strip', 1, $this->staff);
        $svc->cancel($cancelled, $this->staff);

        $this->assertSame(200.0, $this->report()['totals']['sales']);
        $this->assertSame(0.0, $this->report('2026-09-01', '2026-09-30')['totals']['sales'], 'another month');
        $this->assertSame(200.0, $this->report('2026-10-14', '2026-10-14')['totals']['sales'], 'the day itself counts');
    }

    public function test_the_kind_of_sale_can_be_chosen(): void
    {
        $this->sale($this->napa, 2);
        $this->sale($this->napa, 1, 'paikari');

        $this->assertSame(300.0, $this->report()['totals']['sales']);
        $this->assertSame(200.0, $this->report(kind: 'retail')['totals']['sales']);
        $this->assertSame(100.0, $this->report(kind: 'paikari')['totals']['sales']);
        $this->assertSame(0.0, $this->report(kind: 'wholesale')['totals']['sales']);
    }

    public function test_an_approved_return_takes_its_sale_and_vat_back_out(): void
    {
        $inv = $this->sale($this->napa, 2, 'paikari');   // 200, VAT 26.09
        $svc = app(ReturnService::class);
        $return = $svc->request($inv, [['line_id' => $inv->lines()->first()->id, 'unit' => 'strip', 'quantity' => 1]], 'other', 'x', 'credit', $this->staff);
        $svc->approve($return, $this->admin);

        $r = $this->report();

        $this->assertSame(100.0, $r['rows'][0]['returned']);
        $this->assertSame(13.04, $r['rows'][0]['returned_vat']);
        $this->assertSame(100.0, $r['rows'][0]['net_sales']);
        $this->assertSame(13.05, $r['rows'][0]['net_vat']);
    }

    public function test_the_page_shows_the_report_and_the_default_is_this_month(): void
    {
        $this->sale($this->napa, 2);

        $this->actingAs($this->admin)->get('/admin/reports/vat')->assertInertia(fn (Assert $p) => $p->component('admin/reports/vat')
            ->where('from', '2026-10-01')->where('to', '2026-10-31')->where('enabled', true)->where('totals.net_vat', 26.09)->has('rows', 1)->has('memos', 1));
    }

    public function test_a_range_that_runs_backwards_or_too_long_is_put_right(): void
    {
        $this->actingAs($this->admin)->get('/admin/reports/vat?from=2026-10-20&to=2026-10-01')->assertInertia(fn (Assert $p) => $p->where('from', '2026-10-20')->where('to', '2026-10-20'));
        $this->actingAs($this->admin)->get('/admin/reports/vat?from=2020-01-01&to=2026-10-01')->assertInertia(fn (Assert $p) => $p->where('to', '2021-01-01'));
        $this->actingAs($this->admin)->get('/admin/reports/vat?from=nonsense')->assertInertia(fn (Assert $p) => $p->where('from', '2026-10-01'));
        $this->actingAs($this->admin)->get('/admin/reports/vat?kind=bogus')->assertInertia(fn (Assert $p) => $p->where('kind', null));
    }

    public function test_the_page_says_when_vat_is_off(): void
    {
        Setting::write('vat.enabled', false);

        $this->actingAs($this->admin)->get('/admin/reports/vat')->assertInertia(fn (Assert $p) => $p->where('enabled', false));
    }

    public function test_the_file_for_the_accountant_has_the_summary_and_every_memo(): void
    {
        $this->sale($this->napa, 2);
        $this->sale($this->plain, 1, 'paikari');

        $res = $this->actingAs($this->admin)->get('/admin/reports/vat/export?from=2026-10-01&to=2026-10-31');
        $body = $res->streamedContent();

        $res->assertOk()->assertHeader('content-disposition');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('"VAT report",2026-10-01,2026-10-31,all', $body);
        $this->assertStringContainsString('15,1,200,26.09', $body);
        $this->assertStringContainsString('Rahim Pharmacy', $body);
        $this->assertSame(2, substr_count($body, ',2026-10-14,'), 'one row for each memo');
    }

    public function test_only_people_who_see_sales_open_it(): void
    {
        $this->actingAs($this->staff)->get('/admin/reports/vat')->assertRedirect('/');
        $this->actingAs($this->staff)->get('/admin/reports/vat/export')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/admin/reports/vat')->assertRedirect('/');
        $this->get('/admin/reports/vat')->assertRedirect();
    }
}
