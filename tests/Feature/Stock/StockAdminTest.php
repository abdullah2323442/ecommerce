<?php

namespace Tests\Feature\Stock;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Medicine $medicine;

    private StockSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        // Changes to prices and accounts ask for the password again (spec ch 26); these tests have just confirmed it.
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square']);
        $this->medicine = Medicine::create([
            'company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_box' => 100, 'mrp_piece' => 1, 'is_active' => true,
        ]);
        $this->source = StockSource::create(['name' => 'Square direct', 'type' => 'company', 'default_percent' => 12]);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'source_id' => $this->source->id,
            'invoice_number' => 'INV-1',
            'invoice_date' => now()->toDateString(),
            'lines' => [['medicine_id' => $this->medicine->id, 'units' => 10]],
        ], $override);
    }

    public function test_only_the_admin_reaches_stock_everyone_else_is_sent_home(): void
    {
        $this->get('/admin/stock')->assertRedirect('/login');

        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Wholesaler, AccountType::Staff] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get('/admin/stock')->assertRedirect('/');
        }

        $this->actingAs($this->admin)->get('/admin/stock')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/stock/index'));
    }

    public function test_nobody_but_the_admin_can_write_stock(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        $this->actingAs($staff)->post('/admin/stock/receive', $this->payload())->assertRedirect('/');
        $this->actingAs($staff)->post('/admin/stock/master-sync', ['on' => true, 'confirm' => true])->assertRedirect('/');
        $this->withSession(['auth.password_confirmed_at' => time()])->actingAs($staff)->post("/admin/stock/medicines/{$this->medicine->id}/correct", ['on_hand' => 1, 'reason' => 'lost'])->assertRedirect('/');

        $this->assertSame(0, StockLot::count());
        $this->assertNull(Setting::find('stock.master_sync'));
    }

    public function test_receiving_stock_saves_the_lot_at_a_locked_taka_cost(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload())->assertRedirect('/admin/stock');

        $lot = StockLot::first();
        $this->assertSame('88.0000', (string) $lot->unit_cost);
        $this->assertSame('10.0000', (string) $lot->units_remaining);
        $this->assertDatabaseHas('activity_logs', ['action' => 'stock.received']);
    }

    public function test_a_repeated_invoice_is_refused_until_confirmed(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload());

        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload())->assertSessionHasErrors('invoice_number');
        $this->assertSame(1, StockLot::count());

        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload(['confirm_duplicate' => true]))->assertSessionHasNoErrors();
        $this->assertSame(2, StockLot::count());
    }

    public function test_a_line_with_no_percent_anywhere_does_not_save(): void
    {
        $this->source->update(['default_percent' => null]);

        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload())->assertSessionHasErrors('lines');
        $this->assertSame(0, StockLot::count());
    }

    public function test_future_dates_and_nonsense_quantities_are_rejected(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload(['invoice_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors('invoice_date');
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload(['lines' => [['medicine_id' => $this->medicine->id, 'units' => -2]]]))->assertSessionHasErrors('lines.0.units');
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload(['lines' => [['medicine_id' => 999999, 'units' => 1]]]))->assertSessionHasErrors('lines.0.medicine_id');
    }

    public function test_the_quote_returns_the_resolved_percent_for_the_live_preview(): void
    {
        $this->actingAs($this->admin)->getJson("/admin/stock/quote?source_id={$this->source->id}&medicine_id={$this->medicine->id}")
            ->assertOk()->assertJson(['percent' => 12, 'mrp_unit' => 100, 'unit' => 'box']);
    }

    public function test_a_company_rate_overrides_the_source_default_for_new_stock(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/rates/company/{$this->medicine->company_id}", ['percent' => 20])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload());

        $this->assertSame('80.0000', (string) StockLot::first()->unit_cost);

        $this->actingAs($this->admin)->put("/admin/stock/rates/company/{$this->medicine->company_id}", ['percent' => null]);
        $this->assertDatabaseCount('company_purchase_rates', 0);
    }

    public function test_master_sync_needs_a_confirmation_to_turn_on_and_can_be_turned_off(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/master-sync', ['on' => true])->assertSessionHasErrors('on');
        $this->assertFalse((bool) Setting::read('stock.master_sync', false));

        $this->actingAs($this->admin)->post('/admin/stock/master-sync', ['on' => true, 'confirm' => true])->assertSessionHasNoErrors();
        $this->assertTrue((bool) Setting::read('stock.master_sync'));

        $this->actingAs($this->admin)->post('/admin/stock/master-sync', ['on' => false]);
        $this->assertFalse((bool) Setting::read('stock.master_sync'));
    }

    public function test_a_correction_needs_a_valid_reason(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload());

        $this->withSession(['auth.password_confirmed_at' => time()])->actingAs($this->admin)->post("/admin/stock/medicines/{$this->medicine->id}/correct", ['on_hand' => 8, 'reason' => 'because'])->assertSessionHasErrors('reason');
        $this->withSession(['auth.password_confirmed_at' => time()])->actingAs($this->admin)->post("/admin/stock/medicines/{$this->medicine->id}/correct", ['on_hand' => 8, 'reason' => 'stolen'])->assertSessionHasNoErrors();

        $this->assertSame('8.0000', (string) StockLot::first()->units_remaining);
        $this->assertDatabaseHas('activity_logs', ['action' => 'stock.corrected']);
    }

    public function test_the_stock_screen_lists_what_is_on_hand_with_its_value(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload());

        $this->actingAs($this->admin)->get('/admin/stock')->assertInertia(fn (Assert $page) => $page
            ->where('stockValue', 880)
            ->where('rows.total', 1)
            ->where('rows.data.0.brand_name', 'Napa'));
    }

    public function test_public_pages_never_carry_purchase_or_stock_data(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload());
        auth()->logout();

        $payload = json_encode($this->get("/medicines/{$this->medicine->id}")->viewData('page')['props']);

        foreach (['unit_cost', 'units_remaining', 'purchase_percent', 'stock_lots'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    // ---- LOS-1 / LOS-5: a loss-making manual rate is blocked when it is set

    public function test_a_manual_rate_below_cost_is_blocked_unless_the_admin_forces_it(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/receive', $this->payload()); // cost 88 per box, MRP 100
        $customer = User::factory()->create(['account_type' => AccountType::Paikari]);
        $rate = fn (array $extra = []) => $this->actingAs($this->admin)->put("/admin/customers/{$customer->id}/rates", array_merge(['medicine_id' => $this->medicine->id, 'discount_percent' => 20], $extra));

        $rate()->assertSessionHasErrors('loss');
        $this->assertDatabaseCount('customer_rates', 0);

        $rate(['discount_percent' => 10])->assertSessionHasNoErrors(); // 90 per box, still a profit
        $this->assertDatabaseCount('customer_rates', 1);

        $rate(['force_loss' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_rates', ['user_id' => $customer->id, 'discount_percent' => 20]);

        $log = ActivityLog::where('action', 'customer.manual_rate_set')->latest('id')->first();
        $this->assertTrue($log->changes['loss_forced']);
    }

    public function test_a_rate_is_never_blocked_for_a_medicine_with_no_stock_to_compare(): void
    {
        $customer = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($this->admin)->put("/admin/customers/{$customer->id}/rates", ['medicine_id' => $this->medicine->id, 'discount_percent' => 60])->assertSessionHasNoErrors();
    }
}
