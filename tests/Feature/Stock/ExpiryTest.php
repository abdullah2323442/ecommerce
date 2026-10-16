<?php

namespace Tests\Feature\Stock;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\ExpiryService;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpiryTest extends TestCase
{
    use RefreshDatabase;

    private StockService $stock;

    private ExpiryService $expiry;

    private Medicine $medicine;

    private StockSource $source;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
        $this->expiry = app(ExpiryService::class);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        $this->medicine = $this->med('Napa');
    }

    private function med(string $brand): Medicine
    {
        return Medicine::create([
            'company_id' => Company::firstOrCreate(['name' => 'Square'])->id, 'brand_name' => $brand, 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_box' => 100, 'mrp_piece' => 1, 'is_active' => true,
        ]);
    }

    private function buy(Medicine $m, float $units = 10, array $line = []): StockLot
    {
        $this->stock->receive($this->source, ['invoice_date' => now()->toDateString(), 'lines' => [array_merge(['medicine_id' => $m->id, 'units' => $units], $line)]]);

        return StockLot::where('medicine_id', $m->id)->latest('id')->first();
    }

    /** Pretend the purchase happened long ago. */
    private function age(Medicine $m, int $months): void
    {
        StockMovement::where('medicine_id', $m->id)->update(['created_at' => now()->subMonths($months)]);
    }

    public function test_goods_nothing_has_touched_for_six_months_need_checking_fresh_goods_do_not(): void
    {
        $this->buy($this->medicine);
        $this->assertCount(0, $this->expiry->needsChecking());

        $this->age($this->medicine, 7);
        $rows = $this->expiry->needsChecking();

        $this->assertCount(1, $rows);
        $this->assertSame('stagnant', $rows->first()['reason']);
        $this->assertSame(800.0, $this->expiry->moneyAtRisk(), '10 boxes at 80');
    }

    public function test_more_than_a_year_of_stock_at_the_current_pace_needs_checking_even_if_it_still_moves(): void
    {
        $this->buy($this->medicine, 20);
        $this->stock->setMasterSync(true);
        // 1 box in the last 6 months is about 1/6 a month: 19 boxes is far more than 12 months of stock.
        $this->stock->sell($this->medicine, 1, 95, StockService::SALE_RETAIL);

        $row = $this->expiry->needsChecking()->first();

        $this->assertSame('too_much', $row['reason']);
        $this->assertGreaterThan(12, $row['months_of_stock']);
    }

    public function test_a_checked_lot_is_left_alone_until_the_window_passes_again(): void
    {
        $this->buy($this->medicine);
        $this->age($this->medicine, 8);

        $this->expiry->markChecked($this->medicine->id);
        $this->assertCount(0, $this->expiry->needsChecking(), 'checked recently, so not asked again yet');

        $this->assertCount(1, $this->expiry->needsChecking(now()->addMonths(7)), 'a lot with no date is asked about again after the window');
    }

    public function test_a_lot_with_a_date_is_judged_by_the_date_not_the_six_month_rule(): void
    {
        $this->buy($this->medicine, 5, ['expiry_date' => now()->addMonths(2)->toDateString()]);
        $this->age($this->medicine, 9);

        $this->assertCount(0, $this->expiry->needsChecking());

        $near = $this->expiry->nearExpiry();
        $this->assertCount(1, $near);
        $this->assertEqualsWithDelta(80.0, $near->first()['safe_price'], 0.001, 'the lowest safe offer price is the lot cost');
        $this->assertEqualsWithDelta(20.0, $near->first()['safe_percent_off'], 0.1);
    }

    public function test_a_date_far_away_is_not_near_expiry(): void
    {
        $this->buy($this->medicine, 5, ['expiry_date' => now()->addYear()->toDateString()]);

        $this->assertCount(0, $this->expiry->nearExpiry());
    }

    public function test_expiring_a_lot_removes_it_from_stock_and_files_it_under_its_company(): void
    {
        $lot = $this->buy($this->medicine, 6);

        $this->expiry->expire($lot, $this->admin);

        $this->assertSame(0.0, $this->stock->onHand($this->medicine));
        $this->assertDatabaseHas('stock_movements', ['medicine_id' => $this->medicine->id, 'type' => 'expired', 'units' => -6]);

        $group = $this->expiry->expiredByCompany()->first();
        $this->assertSame('Square', $group['company']);
        $this->assertSame(6.0, $group['units']);
        $this->assertSame(480.0, $group['value']);
        $this->assertSame('pending', $group['batches']->first()['status']);
        $this->assertSame(0.0, $group['lost']);
    }

    public function test_goods_the_company_will_not_take_back_count_as_a_loss(): void
    {
        $lot = $this->expiry->expire($this->buy($this->medicine, 6), $this->admin);

        $this->expiry->setReturnStatus($lot, 'returned');
        $this->assertSame(0.0, $this->expiry->expiredByCompany()->first()['lost']);

        $this->expiry->setReturnStatus($lot, 'not_taken');
        $this->assertSame(480.0, $this->expiry->expiredByCompany()->first()['lost']);
    }

    public function test_staff_cannot_expire_stock_and_a_live_lot_has_no_return_status(): void
    {
        $lot = $this->buy($this->medicine);
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        try {
            $this->expiry->expire($lot, $staff);
            $this->fail('Only the admin may expire stock.');
        } catch (StockException) {
            $this->assertSame(10.0, $this->stock->onHand($this->medicine));
        }

        $this->expectException(StockException::class);
        $this->expiry->setReturnStatus($lot, 'returned');
    }

    public function test_the_thresholds_can_be_changed(): void
    {
        $this->actingAs($this->admin)->post('/admin/stock/expiry/settings', ['stagnant_months' => 3, 'months_of_stock_limit' => 6, 'near_expiry_months' => 4])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->expiry->setting('stagnant_months'));

        $this->buy($this->medicine);
        $this->age($this->medicine, 4);
        $this->assertCount(1, $this->expiry->needsChecking(), 'four months is now stagnant');
    }

    public function test_the_expiry_screen_is_admin_only_and_actions_work(): void
    {
        $lot = $this->buy($this->medicine);
        $paikari = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($paikari)->get('/admin/stock/expiry')->assertRedirect('/');
        $this->actingAs($paikari)->post("/admin/stock/lots/{$lot->id}/expire")->assertRedirect('/');
        $this->assertSame(10.0, $this->stock->onHand($this->medicine));

        $this->actingAs($this->admin)->get('/admin/stock/expiry')->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/stock/expiry')->has('needsChecking')->has('expired'));
        $this->actingAs($this->admin)->post("/admin/stock/lots/{$lot->id}/expire")->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/admin/stock/lots/{$lot->id}/return-status", ['status' => 'returned'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put("/admin/stock/lots/{$lot->id}/return-status", ['status' => 'whatever'])->assertSessionHasErrors('status');
        $this->assertSame('returned', $lot->fresh()->return_status);
    }
}
