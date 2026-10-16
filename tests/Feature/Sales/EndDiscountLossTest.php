<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec 6.2: staff can never sell at a loss, and that includes the one discount at the bottom of a retail sale. */
class EndDiscountLossTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $admin;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Ana']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        // A box costs 240 (bought at 20% off 300). The staff limit is raised so the loss rule, not the limit, is what is tested.
        Setting::write('counter.staff_discount_limit', 50);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]]]);
        $stock->setMasterSync(true);
    }

    private function sale(User $by, float $boxes = 1): Invoice
    {
        $svc = app(InvoiceService::class);
        $draft = $svc->open('retail', $by);
        $svc->addLine($draft, $this->napa, 'box', $boxes, $by);

        return $draft->fresh();
    }

    public function test_staff_cannot_set_an_end_discount_that_sells_below_cost(): void
    {
        $draft = $this->sale($this->staff);

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 25])->assertSessionHasErrors('invoice');

        $this->assertSame('0.00', (string) $draft->fresh()->end_discount_percent, 'still no discount');
    }

    public function test_the_admin_is_told_when_staff_try(): void
    {
        $draft = $this->sale($this->staff);

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 25]);

        $note = Notice::where('user_id', $this->admin->id)->where('kind', 'staff_attempt')->firstOrFail();
        $this->assertStringContainsString('Ana', $note->body);
        $this->assertStringContainsString('Napa', $note->body);
    }

    public function test_a_discount_that_still_leaves_a_profit_goes_through(): void
    {
        $draft = $this->sale($this->staff);

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 15])->assertSessionHasNoErrors();   // 255 a box, cost 240

        $this->assertSame('15.00', (string) $draft->fresh()->end_discount_percent);
    }

    public function test_the_line_just_below_cost_is_the_edge(): void
    {
        $draft = $this->sale($this->staff);

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 20])->assertSessionHasNoErrors();   // exactly 240, no loss
        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 20.5])->assertSessionHasErrors('invoice');
    }

    public function test_the_admin_may_go_below_cost(): void
    {
        $draft = $this->sale($this->admin);

        $this->actingAs($this->admin)->put("/admin/counter/{$draft->id}/discount", ['percent' => 25])->assertSessionHasNoErrors();

        $this->assertSame('25.00', (string) $draft->fresh()->end_discount_percent);
    }

    public function test_one_line_that_would_lose_is_enough_to_refuse_it(): void
    {
        $other = Medicine::create([
            'company_id' => $this->napa->company_id, 'brand_name' => 'Cheap', 'strength' => '1', 'form' => 'tablet', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10,
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        app(StockService::class)->receive(StockSource::create(['name' => 'Deep', 'type' => 'company', 'default_percent' => 40]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $other->id, 'units' => 5]]]);
        $draft = $this->sale($this->staff);
        app(InvoiceService::class)->addLine($draft, $other, 'box', 1, $this->staff);

        // Cheap cost 180, so 25% (225) is fine for it, but not for Napa (cost 240).
        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 25])->assertSessionHasErrors('invoice');
    }

    public function test_a_line_with_its_own_price_is_not_counted_again(): void
    {
        $draft = $this->sale($this->admin);
        $line = $draft->lines()->firstOrFail();
        $line->update(['special_percent' => 30, 'unit_price' => 210]);   // the admin already priced this line

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 10])->assertSessionHasNoErrors();
    }

    public function test_taking_the_discount_off_always_works(): void
    {
        $draft = $this->sale($this->staff);
        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 10]);

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 0])->assertSessionHasNoErrors();
        $this->assertSame('0.00', (string) $draft->fresh()->end_discount_percent);
    }

    public function test_the_service_alone_raises_the_same_error(): void
    {
        $draft = $this->sale($this->staff);

        $this->expectException(StockException::class);
        app(InvoiceService::class)->setEndDiscount($draft, 30, $this->staff);
    }
}
