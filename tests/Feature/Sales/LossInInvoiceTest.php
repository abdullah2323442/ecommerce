<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 6.2 "Loss inside an invoice": a few lines at a loss do not block the whole discount; those lines ask for a lower percent of their own. */
class LossInInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    private Medicine $napa;   // bought at 20% off: costs 240 a box

    private Medicine $seclo;  // bought at 10% off: costs 270 a box

    protected function setUp(): void
    {
        parent::setUp();
        Setting::write('counter.staff_discount_limit', 50);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 5])->id]);

        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make('Napa');
        $this->seclo = $make('Seclo');

        $stock = app(StockService::class);
        $source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        $stock->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 50, 'company_percent' => 20], ['medicine_id' => $this->seclo->id, 'units' => 50, 'company_percent' => 10]]]);
        $stock->setMasterSync(true);
    }

    private function draft(): Invoice
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'paikari', 'customer_id' => $this->shop->id])->assertSessionHasNoErrors();
        $draft = Invoice::latest('id')->firstOrFail();
        foreach ([$this->napa, $this->seclo] as $m) {
            $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $m->id, 'unit' => 'box', 'quantity' => 2])->assertSessionHasNoErrors();
        }

        return $draft;
    }

    private function line(Invoice $draft, Medicine $m)
    {
        return $draft->lines()->where('medicine_id', $m->id)->firstOrFail();
    }

    /** The price the stock record of this line was made at. */
    private function soldAt(Invoice $draft, Medicine $m): array
    {
        return StockMovement::where('invoice_line_id', $this->line($draft, $m)->id)->where('type', 'sale')->where('reversed', false)->pluck('unit_price')->map(fn ($p) => (float) $p)->unique()->values()->all();
    }

    public function test_the_lines_that_would_lose_wait_and_the_rest_get_the_invoice_percent(): void
    {
        $draft = $this->draft();   // the shop's own 5%: 285 a box each

        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15])->assertSessionHasNoErrors();

        $napa = $this->line($draft, $this->napa);
        $this->assertSame('255.00', (string) $napa->unit_price);   // 15% off 300, above the 240 it cost
        $this->assertFalse($napa->held_for_loss);

        $seclo = $this->line($draft, $this->seclo);
        $this->assertTrue($seclo->held_for_loss);                  // 255 is below the 270 it cost
        $this->assertSame('285.00', (string) $seclo->unit_price);  // kept the price it had
    }

    public function test_the_screen_is_told_which_lines_wait(): void
    {
        $draft = $this->draft();
        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15]);

        $this->get("/admin/counter/{$draft->id}")->assertInertia(fn (Assert $p) => $p->where('invoice.lines_detail', fn ($lines) => collect($lines)->firstWhere('name', 'Seclo')['held'] === true && collect($lines)->firstWhere('name', 'Napa')['held'] === false));
    }

    public function test_a_lower_percent_of_its_own_fixes_the_held_line(): void
    {
        $draft = $this->draft();
        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15]);
        $seclo = $this->line($draft, $this->seclo);

        $this->put("/admin/counter/{$draft->id}/lines/{$seclo->id}/percent", ['percent' => 8])->assertSessionHasNoErrors();

        $seclo = $seclo->fresh();
        $this->assertSame('276.00', (string) $seclo->unit_price);
        $this->assertFalse($seclo->held_for_loss);
        $this->assertTrue($seclo->special_percent !== null);
        $this->assertSame([276.0], $this->soldAt($draft, $this->seclo));
    }

    public function test_staff_cannot_give_a_line_a_percent_that_loses_and_the_admin_is_told(): void
    {
        $draft = $this->draft();
        $seclo = $this->line($draft, $this->seclo);

        $this->put("/admin/counter/{$draft->id}/lines/{$seclo->id}/percent", ['percent' => 20])->assertSessionHasErrors('invoice');
        $this->assertSame('285.00', (string) $seclo->fresh()->unit_price);
        $this->assertSame(1, Notice::where('user_id', $this->admin->id)->where('kind', 'staff_attempt')->count());
    }

    public function test_a_percent_above_the_staff_limit_still_needs_the_admin(): void
    {
        Setting::write('counter.staff_discount_limit', 5);
        $draft = $this->draft();
        $napa = $this->line($draft, $this->napa);

        $this->put("/admin/counter/{$draft->id}/lines/{$napa->id}/percent", ['percent' => 12])->assertSessionHasErrors('invoice');
    }

    public function test_the_admin_may_sell_at_a_loss_and_it_is_counted_as_one(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->admin)->put("/admin/counter/{$draft->id}/discount", ['percent' => 15])->assertSessionHasNoErrors();

        $seclo = $this->line($draft, $this->seclo);
        $this->assertFalse($seclo->held_for_loss);
        $this->assertSame('255.00', (string) $seclo->unit_price);
        $this->assertTrue(StockMovement::where('invoice_line_id', $seclo->id)->where('type', 'sale')->where('reversed', false)->where('is_loss', true)->exists());
    }

    public function test_the_stock_record_follows_the_new_price_so_the_profit_is_right(): void
    {
        $draft = $this->draft();
        $this->assertSame([285.0], $this->soldAt($draft, $this->napa));

        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15]);

        $this->assertSame([255.0], $this->soldAt($draft, $this->napa));
        $this->assertSame([285.0], $this->soldAt($draft, $this->seclo));   // the held line was not touched
        $this->assertSame(48.0, app(StockService::class)->onHand($this->napa));   // 50 bought, 2 on the draft, none twice
    }

    public function test_stock_is_not_taken_twice_when_the_percent_changes_again(): void
    {
        $draft = $this->draft();
        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15]);
        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 10]);
        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15]);

        $this->assertSame(48.0, app(StockService::class)->onHand($this->napa));   // 50 bought, 2 on the draft
        $this->assertSame(48.0, app(StockService::class)->onHand($this->seclo));
    }

    public function test_a_line_that_is_fine_again_is_released_when_the_percent_is_lowered(): void
    {
        $draft = $this->draft();
        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15]);
        $this->assertTrue($this->line($draft, $this->seclo)->held_for_loss);

        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 8]);   // 276, above the 270 cost

        $seclo = $this->line($draft, $this->seclo);
        $this->assertFalse($seclo->held_for_loss);
        $this->assertSame('276.00', (string) $seclo->unit_price);
    }

    public function test_a_draft_with_a_held_line_can_still_be_finished(): void
    {
        $draft = $this->draft();
        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 15]);

        $this->post("/admin/counter/{$draft->id}/finalise")->assertSessionHasNoErrors();

        $this->assertSame('final', $draft->fresh()->status);
    }

    public function test_a_percent_outside_zero_to_a_hundred_and_other_invoices_lines_are_refused(): void
    {
        $draft = $this->draft();
        $other = $this->draft();
        $napa = $this->line($draft, $this->napa);

        $this->put("/admin/counter/{$draft->id}/lines/{$napa->id}/percent", ['percent' => 100])->assertSessionHasErrors('percent');
        // A line of another invoice is not reachable through this one.
        $this->put("/admin/counter/{$other->id}/lines/{$napa->id}/percent", ['percent' => 20]);
        $this->assertSame('285.00', (string) $napa->fresh()->unit_price);
    }
}
