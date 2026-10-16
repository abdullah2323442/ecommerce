<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 25 (dev note): the VAT fields exist from the start; VAT is worked out on memo lines only once it is switched on. */
class VatFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Company $company;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->company = Company::create(['name' => 'Square']);
        $this->napa = Medicine::create([
            'company_id' => $this->company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
    }

    private function line(float $qty = 2)
    {
        $svc = app(InvoiceService::class);

        return $svc->addLine($svc->open('retail', $this->staff), $this->napa, 'strip', $qty, $this->staff);
    }

    public function test_vat_is_off_by_default_so_nothing_is_worked_out(): void
    {
        $this->napa->forceFill(['vat_percent' => 15])->save();

        $line = $this->line();

        $this->assertSame('0.00', (string) $line->vat_percent);
        $this->assertSame('0.00', (string) $line->vat_amount);
    }

    public function test_when_it_is_on_a_line_takes_the_medicine_rate_and_records_the_vat_inside_its_price(): void
    {
        Setting::write('vat.enabled', true);
        $this->napa->forceFill(['vat_percent' => 15])->save();

        $line = $this->line(2);   // 2 strips at 100 = 200, which already includes 15% VAT

        $this->assertSame('15.00', (string) $line->vat_percent);
        $this->assertSame('26.09', (string) $line->vat_amount);
    }

    public function test_the_company_rate_is_used_when_the_medicine_has_none(): void
    {
        Setting::write('vat.enabled', true);
        $this->company->forceFill(['vat_percent' => 5])->save();

        $this->assertSame('9.52', (string) $this->line(2)->vat_amount);

        $this->napa->forceFill(['vat_percent' => 10])->save();
        $this->assertSame('18.18', (string) $this->line(2)->vat_amount);
    }

    public function test_a_change_of_quantity_works_the_vat_out_again(): void
    {
        Setting::write('vat.enabled', true);
        $this->napa->forceFill(['vat_percent' => 15])->save();
        $line = $this->line(2);

        $line = app(InvoiceService::class)->setQuantity($line, 4, $this->staff);

        $this->assertSame('52.17', (string) $line->vat_amount);
    }

    public function test_a_medicine_without_a_rate_has_no_vat_even_when_it_is_on(): void
    {
        Setting::write('vat.enabled', true);

        $this->assertSame('0.00', (string) $this->line()->vat_amount);
    }

    public function test_the_rate_stays_on_the_line_when_it_changes_later(): void
    {
        Setting::write('vat.enabled', true);
        $this->napa->forceFill(['vat_percent' => 15])->save();
        $line = $this->line(2);

        $this->napa->forceFill(['vat_percent' => 0])->save();
        $line = app(InvoiceService::class)->setQuantity($line, 3, $this->staff);

        $this->assertSame('15.00', (string) $line->vat_percent);
        $this->assertSame('39.13', (string) $line->vat_amount);
    }

    public function test_it_is_switched_on_from_the_settings_screen(): void
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);

        $this->actingAs($admin)->put('/admin/settings/tax', ['vat_enabled' => true])->assertSessionHasNoErrors();
        $this->assertTrue(Setting::read('vat.enabled'));

        $this->put('/admin/settings/tax', ['vat_enabled' => false])->assertSessionHasNoErrors();
        $this->assertFalse(Setting::read('vat.enabled'));
        $this->assertSame(2, ActivityLog::where('action', 'settings.changed')->count());
    }

    public function test_a_finished_memo_keeps_the_vat_on_its_lines(): void
    {
        Setting::write('vat.enabled', true);
        $this->napa->forceFill(['vat_percent' => 15])->save();
        $this->line(2);
        $invoice = Invoice::firstOrFail();

        app(InvoiceService::class)->finalise($invoice, $this->staff);

        $this->assertSame('26.09', (string) $invoice->lines()->firstOrFail()->vat_amount);
    }
}
