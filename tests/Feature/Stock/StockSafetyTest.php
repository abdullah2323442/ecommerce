<?php

namespace Tests\Feature\Stock;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\OfferProduct;
use App\Models\Role;
use App\Models\StockEntryDraft;
use App\Models\StockLot;
use App\Models\StockMismatch;
use App\Models\StockMovement;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 6.7 and 6.8: report a mismatch, warn about scattered lots, keep a half-typed entry, guard pack size and cost changes. */
class StockSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Medicine $napa;

    private StockSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Robiul']);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        app(StockService::class)->receive($this->source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 60]]]);
    }

    private function lot(): StockLot
    {
        return StockLot::where('medicine_id', $this->napa->id)->firstOrFail();
    }

    // ------------------------------------------------------------ report mismatch

    public function test_staff_report_a_mismatch_and_the_admin_hears_but_staff_learn_no_number(): void
    {
        $this->actingAs($this->staff)->post('/admin/stock/mismatches', ['medicine_id' => $this->napa->id, 'found' => 50, 'note' => 'Shelf B'])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $report = StockMismatch::firstOrFail();
        $this->assertSame('50.0000', (string) $report->found);
        $this->assertSame('60.0000', (string) $report->system_units);
        $this->assertSame($this->staff->id, $report->reported_by);
        $this->assertSame(1, Notice::where('user_id', $this->admin->id)->where('kind', 'stock_mismatch')->count());
        $this->assertStringNotContainsString('60', (string) session('status'));
    }

    public function test_only_counter_staff_and_the_admin_can_report_and_only_stock_staff_read_the_list(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->post('/admin/stock/mismatches', ['medicine_id' => $this->napa->id, 'found' => 5])->assertRedirect('/');
        $this->assertSame(0, StockMismatch::count());

        $cashier = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->actingAs($cashier)->get('/admin/stock/mismatches')->assertRedirect('/');
    }

    public function test_the_report_is_checked_and_two_disagreeing_reports_are_flagged(): void
    {
        $this->actingAs($this->staff)->post('/admin/stock/mismatches', ['medicine_id' => $this->napa->id, 'found' => -1])->assertSessionHasErrors('found');
        $this->post('/admin/stock/mismatches', ['medicine_id' => 999999, 'found' => 3])->assertSessionHasErrors('medicine_id');

        $other = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->actingAs($this->staff)->post('/admin/stock/mismatches', ['medicine_id' => $this->napa->id, 'found' => 50]);
        $this->actingAs($other)->post('/admin/stock/mismatches', ['medicine_id' => $this->napa->id, 'found' => 55]);

        $this->actingAs($this->admin)->get('/admin/stock/mismatches')->assertInertia(fn (Assert $p) => $p->has('reports', 2)->where('reports.0.clash', true));
        $this->get('/admin/stock')->assertInertia(fn (Assert $p) => $p->where('openMismatches', 2));
    }

    public function test_the_admin_fixes_the_number_which_is_not_a_loss_and_closes_the_reports(): void
    {
        $this->actingAs($this->staff)->post('/admin/stock/mismatches', ['medicine_id' => $this->napa->id, 'found' => 50]);
        $report = StockMismatch::firstOrFail();

        $this->actingAs($this->admin)->post("/admin/stock/mismatches/{$report->id}", ['action' => 'fix'])->assertSessionHasNoErrors();

        $this->assertSame(50.0, app(StockService::class)->onHand($this->napa));
        $this->assertSame('fixed', $report->fresh()->status);
        $this->assertFalse(StockMovement::where('type', 'correction')->where('is_loss', true)->exists());
        $this->post("/admin/stock/mismatches/{$report->id}", ['action' => 'fix'])->assertSessionHasErrors('action');
    }

    public function test_the_admin_can_dismiss_a_report_and_stock_stays(): void
    {
        $this->actingAs($this->staff)->post('/admin/stock/mismatches', ['medicine_id' => $this->napa->id, 'found' => 50]);
        $report = StockMismatch::firstOrFail();

        $this->actingAs($this->admin)->post("/admin/stock/mismatches/{$report->id}", ['action' => 'dismiss']);

        $this->assertSame(60.0, app(StockService::class)->onHand($this->napa));
        $this->assertSame('dismissed', $report->fresh()->status);
    }

    // ------------------------------------------------------------ scattered lots

    public function test_many_lots_and_small_old_lots_are_warned_about_on_the_stock_pages(): void
    {
        $stock = app(StockService::class);
        foreach ([1, 2, 3] as $i) {
            $stock->receive($this->source, ['invoice_date' => now()->subDays(200)->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 1]]]);
        }

        $this->actingAs($this->admin)->get('/admin/stock')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.lots', 4)->where('rows.data.0.small_old', 3)->where('rows.data.0.fragmented', true));
        $this->get("/admin/stock/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->where('lotsLeft', 4)->where('manyLots', 4)
            ->where('lots', fn ($lots) => collect($lots)->where('small_old', true)->count() === 3));
    }

    public function test_one_healthy_lot_gives_no_warning(): void
    {
        $this->actingAs($this->admin)->get('/admin/stock')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.fragmented', false));
    }

    // ------------------------------------------------------------ draft entry

    public function test_a_half_typed_purchase_is_kept_adds_no_stock_and_comes_back(): void
    {
        $payload = ['source_id' => (string) $this->source->id, 'invoice_number' => 'INV-9', 'invoice_date' => now()->toDateString(), 'note' => '', 'lines' => [['medicine' => ['id' => $this->napa->id], 'units' => '5']]];

        $this->actingAs($this->admin)->put('/admin/stock/draft', ['payload' => $payload])->assertSessionHasNoErrors();
        $this->put('/admin/stock/draft', ['payload' => $payload + ['note' => 'again']]);

        $this->assertSame(1, StockEntryDraft::count());
        $this->assertSame(60.0, app(StockService::class)->onHand($this->napa));
        $this->get('/admin/stock/receive')->assertInertia(fn (Assert $p) => $p->where('draft.payload.invoice_number', 'INV-9'));

        // Another person does not get it.
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Admin]))->get('/admin/stock/receive')->assertInertia(fn (Assert $p) => $p->where('draft', null));
    }

    public function test_saving_the_purchase_removes_the_draft_and_it_can_be_thrown_away(): void
    {
        $this->actingAs($this->admin)->put('/admin/stock/draft', ['payload' => ['invoice_number' => 'X', 'lines' => []]]);
        $this->post('/admin/stock/receive', ['source_id' => $this->source->id, 'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 2]]])->assertSessionHasNoErrors();
        $this->assertSame(0, StockEntryDraft::count());

        $this->put('/admin/stock/draft', ['payload' => ['invoice_number' => 'Y', 'lines' => []]]);
        $this->delete('/admin/stock/draft');
        $this->assertSame(0, StockEntryDraft::count());
    }

    public function test_staff_without_stock_access_cannot_use_the_draft(): void
    {
        $cashier = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->actingAs($cashier)->put('/admin/stock/draft', ['payload' => ['lines' => []]])->assertRedirect('/');
        $this->assertSame(0, StockEntryDraft::count());
    }

    // ------------------------------------------------------------ pack size and MRP

    public function test_changing_the_pack_size_asks_first_then_is_recorded(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['strips_per_box' => 4])->assertSessionHasErrors('strips_per_box');
        $this->assertSame(3, $this->napa->fresh()->strips_per_box);

        $this->put("/admin/stock/medicines/{$this->napa->id}", ['strips_per_box' => 4, 'confirm_units' => true])->assertSessionHasNoErrors();
        $this->assertSame(4, $this->napa->fresh()->strips_per_box);

        $log = ActivityLog::where('action', 'medicine.units_changed')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(['strips_per_box' => 3, 'pieces_per_strip' => 10], $log->changes['before']);
        $this->get("/admin/stock/medicines/{$this->napa->id}/edit")->assertInertia(fn (Assert $p) => $p->has('history', 1));
    }

    public function test_a_new_printed_price_leaves_a_mark_for_a_month_only(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 330])->assertSessionHasNoErrors();

        $this->assertSame('300.00', (string) $this->napa->fresh()->mrp_before);
        $this->get("/admin/stock/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->where('mrpMark.was', 300));

        $this->napa->forceFill(['mrp_changed_at' => now()->subDays(40)])->save();
        $this->get("/admin/stock/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->where('mrpMark', null));
    }

    public function test_saving_the_same_values_changes_nothing_and_staff_without_access_cannot_edit(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 300, 'strips_per_box' => 3])->assertSessionHas('status');
        $this->assertNull($this->napa->fresh()->mrp_changed_at);
        $this->assertSame(0, ActivityLog::whereIn('action', ['medicine.units_changed', 'medicine.mrp_changed'])->count());

        $cashier = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->actingAs($cashier)->put("/admin/stock/medicines/{$this->napa->id}", ['mrp_box' => 1])->assertRedirect('/');
        $this->assertSame('300.00', (string) $this->napa->fresh()->mrp_box);
    }

    // ------------------------------------------------------------ purchase price correction

    public function test_a_lot_price_can_be_corrected_without_a_warning_when_nothing_loses(): void
    {
        $lot = $this->lot();   // 20% off 300 = 240 a box
        $this->assertSame('240.0000', (string) $lot->unit_cost);
        $sale = StockMovement::create(['medicine_id' => $this->napa->id, 'stock_lot_id' => $lot->id, 'type' => 'sale', 'units' => -1, 'unit_cost' => 240, 'unit_price' => 270, 'is_loss' => false]);

        // 10% off 300 is 270: a sale at 270 is not below cost.
        $this->actingAs($this->admin)->put("/admin/stock/lots/{$lot->id}/cost", ['percent' => 10])->assertSessionHasNoErrors();

        $this->assertSame('270.0000', (string) $lot->fresh()->unit_cost);
        $this->assertFalse($sale->fresh()->is_loss);
    }

    public function test_a_cost_that_puts_an_offer_or_a_sale_at_a_loss_needs_a_yes(): void
    {
        $lot = $this->lot();
        OfferProduct::create(['medicine_id' => $this->napa->id, 'percent' => 15, 'lot_percent' => 20, 'visible_to' => ['paikari'], 'ends_on' => now()->addWeek(), 'is_on' => true, 'created_by' => $this->admin->id]);   // sells at 255
        $sale = StockMovement::create(['medicine_id' => $this->napa->id, 'stock_lot_id' => $lot->id, 'type' => 'sale', 'units' => -1, 'unit_cost' => 240, 'unit_price' => 250, 'is_loss' => false]);

        $this->actingAs($this->admin)->getJson("/admin/stock/lots/{$lot->id}/cost?percent=5")   // cost 285
            ->assertOk()->assertJson(['unit_cost' => 285, 'offers_at_loss' => 1, 'sales_at_loss' => 1, 'loss' => true]);

        $this->put("/admin/stock/lots/{$lot->id}/cost", ['percent' => 5])->assertSessionHasErrors('percent');
        $this->assertSame('240.0000', (string) $lot->fresh()->unit_cost);

        $this->put("/admin/stock/lots/{$lot->id}/cost", ['percent' => 5, 'confirm_loss' => true])->assertSessionHasNoErrors();
        $this->assertSame('285.0000', (string) $lot->fresh()->unit_cost);
        $this->assertSame('5.000', (string) $lot->fresh()->purchase_percent);
        $this->assertSame('285.0000', (string) $sale->fresh()->unit_cost);
        $this->assertTrue($sale->fresh()->is_loss);
    }

    public function test_a_cheaper_cost_needs_no_confirmation_and_clears_a_loss_mark(): void
    {
        $lot = $this->lot();
        $sale = StockMovement::create(['medicine_id' => $this->napa->id, 'stock_lot_id' => $lot->id, 'type' => 'sale', 'units' => -1, 'unit_cost' => 285, 'unit_price' => 250, 'is_loss' => true]);

        $this->actingAs($this->admin)->put("/admin/stock/lots/{$lot->id}/cost", ['percent' => 25])->assertSessionHasNoErrors();

        $this->assertSame('225.0000', (string) $lot->fresh()->unit_cost);
        $this->assertFalse($sale->fresh()->is_loss);
    }

    public function test_only_the_admin_not_a_sub_admin_corrects_a_cost_and_the_percent_is_checked(): void
    {
        $lot = $this->lot();
        $sub = User::factory()->create(['account_type' => AccountType::Staff]);
        $sub->forceFill(['role_id' => Role::where('name', 'Sub-admin')->value('id')])->save();

        $this->actingAs($sub)->put("/admin/stock/lots/{$lot->id}/cost", ['percent' => 30])->assertSessionHasErrors('percent');
        $this->assertSame('240.0000', (string) $lot->fresh()->unit_cost);

        $this->actingAs($this->admin)->put("/admin/stock/lots/{$lot->id}/cost", ['percent' => 100])->assertSessionHasErrors('percent');
        $this->put("/admin/stock/lots/{$lot->id}/cost", ['percent' => -2])->assertSessionHasErrors('percent');
    }
}
