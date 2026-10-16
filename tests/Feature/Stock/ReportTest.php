<?php

namespace Tests\Feature\Stock;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\ReportService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private ReportService $reports;

    private User $admin;

    private Medicine $napa;

    private StockSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->reports = app(ReportService::class);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_box' => 100, 'mrp_piece' => 1, 'is_active' => true,
        ]);
    }

    private function buy(float $units): void
    {
        $this->stock->receive($this->source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => $units]]]);
    }

    public function test_low_stock_lists_medicines_at_or_under_their_level_emptiest_first(): void
    {
        $this->assertCount(0, $this->reports->lowStock(), 'no level set, no alert');

        $this->napa->update(['reorder_level' => 5]);
        $this->assertSame(0.0, $this->reports->lowStock()->first()['on_hand'], 'a level with no stock at all is the most urgent');

        $this->buy(10);
        $this->assertCount(0, $this->reports->lowStock());

        $this->stock->sell($this->napa, 6, 95, StockService::SALE_OFFER);
        $this->assertSame(4.0, $this->reports->lowStock()->first()['on_hand']);
    }

    public function test_what_finished_today_is_what_sold_out_today(): void
    {
        $this->buy(3);
        $this->assertCount(0, $this->reports->finishedToday());

        $this->stock->sell($this->napa, 3, 95, StockService::SALE_OFFER);
        $this->assertSame('Napa', $this->reports->finishedToday()->first()['brand_name']);

        $this->assertCount(0, $this->reports->finishedToday(now()->addDay()), 'tomorrow it is no longer news');
    }

    public function test_gross_profit_uses_the_cost_of_the_lot_each_sale_came_from(): void
    {
        $this->buy(10); // cost 80
        $this->stock->sell($this->napa, 4, 95, StockService::SALE_OFFER);

        $gross = $this->reports->gross(now());

        $this->assertSame(380.0, $gross['revenue']);
        $this->assertSame(320.0, $gross['cost']);
        $this->assertSame(60.0, $gross['profit']);
        $this->assertSame(0.0, $gross['loss_sales_value']);
    }

    public function test_a_forced_loss_sale_shows_in_the_loss_total(): void
    {
        $this->buy(10);
        $this->stock->sell($this->napa, 2, 70, StockService::SALE_OFFER, $this->admin); // 10 below cost each

        $gross = $this->reports->gross(now());

        $this->assertSame(-20.0, $gross['profit']);
        $this->assertSame(20.0, $gross['loss_sales_value']);
    }

    public function test_recurring_expenses_repeat_every_month_and_one_offs_count_once(): void
    {
        $now = Carbon::parse('2026-10-15');
        Expense::create(['spent_on' => '2026-08-01', 'category' => 'rent', 'amount' => 5000, 'recurring' => true]);
        Expense::create(['spent_on' => '2026-10-03', 'category' => 'transport', 'amount' => 300]);
        Expense::create(['spent_on' => '2026-09-20', 'category' => 'packaging', 'amount' => 700]);
        Expense::create(['spent_on' => '2026-06-01', 'category' => 'internet', 'amount' => 800, 'recurring' => true, 'recurring_until' => '2026-09-30']);

        $oct = $this->reports->net($now);
        $this->assertSame(5300.0, $oct['expenses'], 'rent repeats, transport is in October, packaging was September, internet ended');
        $this->assertSame(['rent' => 5000.0, 'transport' => 300.0], $oct['by_category']);

        $sep = $this->reports->net(Carbon::parse('2026-09-15'));
        $this->assertSame(6500.0, $sep['expenses'], 'rent 5000, packaging 700 and the internet bill still running');

        $before = $this->reports->net(Carbon::parse('2026-07-15'));
        $this->assertSame(800.0, $before['expenses'], 'rent had not started yet');
    }

    public function test_net_profit_is_gross_less_expenses(): void
    {
        $this->buy(10);
        $this->stock->sell($this->napa, 4, 95, StockService::SALE_OFFER); // gross 60
        Expense::create(['spent_on' => now()->toDateString(), 'category' => 'rent', 'amount' => 100]);

        $net = $this->reports->net(now());

        $this->assertSame(60.0, $net['gross']);
        $this->assertSame(-40.0, $net['net']);
    }

    public function test_the_trend_runs_oldest_to_newest(): void
    {
        $trend = $this->reports->trend(3, Carbon::parse('2026-10-15'));

        $this->assertSame(['2026-08', '2026-09', '2026-10'], $trend->pluck('month')->all());
    }

    public function test_reports_and_expenses_are_admin_only_and_validated(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        $this->actingAs($staff)->get('/admin/stock/reports')->assertRedirect('/');
        $this->actingAs($staff)->post('/admin/stock/expenses', ['spent_on' => now()->toDateString(), 'category' => 'rent', 'amount' => 10])->assertRedirect('/');
        $this->assertDatabaseCount('expenses', 0);

        $this->actingAs($this->admin)->get('/admin/stock/reports')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/stock/reports')->has('trend', 12));

        $this->actingAs($this->admin)->post('/admin/stock/expenses', ['spent_on' => now()->toDateString(), 'category' => 'rent', 'amount' => -5])->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post('/admin/stock/expenses', ['spent_on' => now()->toDateString(), 'category' => 'rent', 'amount' => 5000, 'recurring' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('expenses', ['category' => 'rent', 'recurring' => true]);

        $this->actingAs($this->admin)->delete('/admin/stock/expenses/'.Expense::first()->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_a_reorder_level_can_be_set_and_cleared(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/reorder-level", ['level' => 12])->assertSessionHasNoErrors();
        $this->assertSame('12.00', (string) $this->napa->fresh()->reorder_level);

        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/reorder-level", ['level' => null]);
        $this->assertNull($this->napa->fresh()->reorder_level);
    }
}
