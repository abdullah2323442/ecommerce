<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\Notice;
use App\Models\QuantityStep;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 8: the counter keeps selling when the internet is down, and nothing is lost when it comes back. */
class OfflineCounterTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $admin;

    private Medicine $napa;      // 240 a box to us, 300 printed

    private Medicine $seclo;     // 285 a box to us: a thin margin

    private Medicine $morphine;  // controlled

    private Medicine $syrup;     // sold by the bottle only

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 15:00:00');
        Setting::write('counter.staff_discount_limit', 10);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);

        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name, array $over = []) => Medicine::create(array_merge([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ], $over));
        $this->napa = $make('Napa');
        $this->seclo = $make('Seclo');
        $this->morphine = $make('Morphine', ['is_controlled' => true]);
        $this->syrup = $make('Cough syrup', ['packaging_type' => 'bottle', 'form' => 'syrup', 'mrp_box' => null, 'mrp_strip' => null, 'mrp_piece' => 120]);

        $stock = app(StockService::class);
        $source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        $stock->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [
            ['medicine_id' => $this->napa->id, 'units' => 10, 'company_percent' => 20],
            ['medicine_id' => $this->seclo->id, 'units' => 10, 'company_percent' => 5],
            ['medicine_id' => $this->morphine->id, 'units' => 10, 'company_percent' => 20],
            ['medicine_id' => $this->syrup->id, 'units' => 10, 'company_percent' => 20],
        ]]);
        $stock->setMasterSync(true);

        MedicineBarcode::create(['medicine_id' => $this->napa->id, 'code' => '8901234567890']);
        QuantityStep::create(['medicine_id' => $this->napa->id, 'unit' => 'box', 'min_qty' => 5, 'kind' => 'percent', 'value' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(array $over = []): array
    {
        return array_merge(['ref' => 'T-A1-1', 'at' => '2026-10-12 14:30:00', 'lines' => [['id' => $this->napa->id, 'unit' => 'strip', 'qty' => 2]], 'discount' => 0, 'method' => 'cash'], $over);
    }

    private function sync(array $sales)
    {
        return $this->actingAs($this->staff)->postJson('/admin/counter/offline/sync', ['sales' => $sales]);
    }

    private function onHand(Medicine $m): float
    {
        return app(StockService::class)->onHand($m);
    }

    // ------------------------------------------------------------------ the copy for the device

    public function test_the_device_gets_the_list_with_prices_steps_and_barcodes(): void
    {
        $json = $this->actingAs($this->staff)->getJson('/admin/counter/offline/snapshot')->assertOk()->json();

        $napa = collect($json['medicines'])->firstWhere('id', $this->napa->id);
        $this->assertEquals(['Napa', 'Square', 300, 100, 10, 'box'], [$napa['n'], $napa['c'], $napa['mb'], $napa['ms'], $napa['mp'], $napa['p']]);
        $this->assertEquals([['box', 5, 'p', 5]], $napa['st']);
        $this->assertSame($this->napa->id, $json['codes']['8901234567890']);
        $this->assertEquals(10, $json['limit']);
        $this->assertSame(['name' => config('app.name')], array_intersect_key($json['shop'], ['name' => 1]));
        $this->assertContains('bkash', $json['methods']);
    }

    public function test_a_controlled_medicine_is_marked_so_the_device_will_not_sell_it(): void
    {
        $json = $this->actingAs($this->staff)->getJson('/admin/counter/offline/snapshot')->json();

        $this->assertSame(1, collect($json['medicines'])->firstWhere('id', $this->morphine->id)['ctl']);
        $this->assertArrayNotHasKey('ctl', collect($json['medicines'])->firstWhere('id', $this->napa->id));
    }

    public function test_loss_protection_travels_as_a_biggest_discount_only_where_the_margin_is_thin_and_never_the_cost_or_the_stock(): void
    {
        $response = $this->actingAs($this->staff)->getJson('/admin/counter/offline/snapshot')->assertOk();
        $json = $response->json();

        $seclo = collect($json['medicines'])->firstWhere('id', $this->seclo->id);
        $this->assertEquals(5, $seclo['ml']);                                                  // 285 on 300: 5% is the most
        $this->assertArrayNotHasKey('ml', collect($json['medicines'])->firstWhere('id', $this->napa->id));   // 20% margin, above the 10% limit: nothing to say

        $text = $response->getContent();
        foreach (['cost', 'stock', 'units_remaining', 'purchase', '"285'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_the_owner_has_no_limit_and_no_floors(): void
    {
        $json = $this->actingAs($this->admin)->getJson('/admin/counter/offline/snapshot')->json();

        $this->assertNull($json['limit']);
        $this->assertArrayNotHasKey('ml', collect($json['medicines'])->firstWhere('id', $this->seclo->id));
    }

    public function test_an_inactive_medicine_is_not_in_the_copy_and_only_counter_staff_may_ask(): void
    {
        $this->napa->update(['is_active' => false]);
        $ids = collect($this->actingAs($this->staff)->getJson('/admin/counter/offline/snapshot')->json('medicines'))->pluck('id');
        $this->assertFalse($ids->contains($this->napa->id));

        auth()->logout();
        $this->getJson('/admin/counter/offline/snapshot')->assertUnauthorized();
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->getJson('/admin/counter/offline/snapshot')->assertRedirect('/');
        $noCounter = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Orders only', 'permissions' => ['orders']])->id]);
        $this->actingAs($noCounter)->getJson('/admin/counter/offline/snapshot')->assertForbidden();
    }

    public function test_the_offline_screen_opens_for_counter_staff(): void
    {
        $this->actingAs($this->staff)->get('/admin/counter/offline')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/counter/offline'));
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->get('/admin/counter/offline')->assertRedirect('/');
    }

    // ------------------------------------------------------------------ sending the sales back

    public function test_a_sale_made_offline_becomes_a_finished_retail_memo_with_a_real_number(): void
    {
        $result = $this->sync([$this->sale()])->assertOk()->json('results.0');

        $this->assertSame(['synced', 'T-A1-1'], [$result['status'], $result['ref']]);
        $this->assertNotNull($result['number']);

        $invoice = Invoice::findOrFail($result['id']);
        $this->assertSame(['retail', 'counter', 'final', 'cash', 'T-A1-1'], [$invoice->kind, $invoice->channel, $invoice->status, $invoice->payment_method, $invoice->offline_ref]);
        $this->assertSame('200.00', (string) $invoice->total);            // 2 strips at 100
        $this->assertSame($this->staff->id, $invoice->finalised_by);
        $this->assertEqualsWithDelta(10 - 2 / 3, $this->onHand($this->napa), 0.001);
        $this->assertSame(0, Notice::where('kind', 'offline_conflict')->count());
    }

    public function test_the_sale_belongs_to_the_day_it_was_made_not_the_day_it_was_sent(): void
    {
        $this->sync([$this->sale(['at' => '2026-10-10 18:45:00'])]);

        $invoice = Invoice::firstOrFail();
        $this->assertSame('2026-10-10 18:45:00', $invoice->finalised_at->toDateTimeString());
        $this->assertSame('2026-10-10 18:45:00', $invoice->opened_at->toDateTimeString());
    }

    public function test_a_time_in_the_future_is_taken_as_now(): void
    {
        $this->sync([$this->sale(['at' => '2026-10-20 10:00:00'])]);

        $this->assertSame('2026-10-12 15:00:00', Invoice::firstOrFail()->finalised_at->toDateTimeString());
    }

    public function test_sending_the_same_sale_again_records_it_once(): void
    {
        $first = $this->sync([$this->sale()])->json('results.0');
        $again = $this->sync([$this->sale()])->json('results.0');

        $this->assertSame($first['number'], $again['number']);
        $this->assertSame(1, Invoice::count());
        $this->assertEqualsWithDelta(10 - 2 / 3, $this->onHand($this->napa), 0.001);   // stock was taken once
    }

    public function test_the_price_is_the_printed_price_with_the_quantity_step_and_the_discount_within_the_limit(): void
    {
        $result = $this->sync([$this->sale(['lines' => [['id' => $this->napa->id, 'unit' => 'box', 'qty' => 5]], 'discount' => 4, 'method' => 'bkash'])])->json('results.0');

        $invoice = Invoice::findOrFail($result['id']);
        $this->assertSame('285.00', (string) $invoice->lines()->firstOrFail()->unit_price);   // 5% step on 300
        $this->assertSame('4.00', (string) $invoice->end_discount_percent);
        $this->assertSame('1368.00', (string) $invoice->total);                                // 5 x 285 less 4%
        $this->assertSame('bkash', $invoice->payment_method);
    }

    public function test_staff_cannot_give_more_discount_than_their_limit_and_the_owner_is_told(): void
    {
        $result = $this->sync([$this->sale(['discount' => 30])])->json('results.0');

        $this->assertSame('10.00', (string) Invoice::findOrFail($result['id'])->end_discount_percent);
        $this->assertTrue($result['flags']['discount_reduced']);
        $this->assertSame(1, Notice::where('user_id', $this->admin->id)->where('kind', 'offline_conflict')->count());
    }

    public function test_a_thin_margin_medicine_cuts_the_discount_to_what_leaves_no_loss(): void
    {
        $result = $this->sync([$this->sale(['lines' => [['id' => $this->seclo->id, 'unit' => 'box', 'qty' => 1]], 'discount' => 9])])->json('results.0');

        $this->assertSame('5.00', (string) Invoice::findOrFail($result['id'])->end_discount_percent);
        $this->assertTrue($result['flags']['discount_reduced']);
    }

    public function test_the_owner_may_give_any_discount(): void
    {
        $result = $this->actingAs($this->admin)->postJson('/admin/counter/offline/sync', ['sales' => [$this->sale(['discount' => 30])]])->json('results.0');

        $this->assertSame('30.00', (string) Invoice::findOrFail($result['id'])->end_discount_percent);
        $this->assertFalse($result['flags']['discount_reduced']);
    }

    // ------------------------------------------------------------------ the conflicts

    public function test_a_sale_the_stock_says_could_not_have_happened_is_still_recorded_and_the_owner_is_told(): void
    {
        // Online orders took most of the boxes while the counter was offline.
        $result = $this->sync([$this->sale(['lines' => [['id' => $this->napa->id, 'unit' => 'box', 'qty' => 15]]])])->json('results.0');

        $this->assertSame('synced', $result['status']);
        $this->assertSame(['Napa'], $result['flags']['out_of_stock']);

        $invoice = Invoice::findOrFail($result['id']);
        $this->assertSame('final', $invoice->status);
        $this->assertTrue($invoice->lines()->firstOrFail()->is_outside);
        $this->assertEqualsWithDelta(10.0, $this->onHand($this->napa), 0.001);   // the stock was not pushed below zero

        $notice = Notice::where('user_id', $this->admin->id)->where('kind', 'offline_conflict')->firstOrFail();
        $this->assertStringContainsString('stock went below zero for Napa', $notice->body);
        $this->assertStringContainsString($invoice->number, $notice->body);
        $this->assertSame(['out_of_stock' => ['Napa'], 'loss' => [], 'discount_reduced' => false], $invoice->offline_flags);
    }

    public function test_a_price_that_would_be_a_loss_is_recorded_outside_the_stock_too(): void
    {
        // The cost went up while the counter was offline: now 310 a box against 300 printed.
        StockLot::where('medicine_id', $this->napa->id)->update(['unit_cost' => 310]);

        $result = $this->sync([$this->sale(['lines' => [['id' => $this->napa->id, 'unit' => 'box', 'qty' => 1]]])])->json('results.0');

        $this->assertSame(['Napa'], $result['flags']['loss']);
        $this->assertTrue(Invoice::findOrFail($result['id'])->lines()->firstOrFail()->is_outside);
    }

    public function test_a_controlled_medicine_is_refused_and_nothing_is_recorded(): void
    {
        $result = $this->sync([$this->sale(['lines' => [['id' => $this->morphine->id, 'unit' => 'box', 'qty' => 1]]])])->json('results.0');

        $this->assertSame('refused', $result['status']);
        $this->assertStringContainsString('controlled', $result['reason']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_an_unknown_medicine_or_a_unit_it_does_not_come_in_is_refused(): void
    {
        $unknown = $this->sync([$this->sale(['ref' => 'T-X-1', 'lines' => [['id' => 99999, 'unit' => 'box', 'qty' => 1]]])])->json('results.0');
        $this->assertSame('refused', $unknown['status']);

        $badUnit = $this->sync([$this->sale(['ref' => 'T-X-2', 'lines' => [['id' => $this->syrup->id, 'unit' => 'box', 'qty' => 1]]])])->json('results.0');
        $this->assertSame('refused', $badUnit['status']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_one_refused_sale_does_not_hold_back_the_others(): void
    {
        $results = $this->sync([
            $this->sale(['ref' => 'T-B-1']),
            $this->sale(['ref' => 'T-B-2', 'lines' => [['id' => $this->morphine->id, 'unit' => 'box', 'qty' => 1]]]),
            $this->sale(['ref' => 'T-B-3', 'lines' => [['id' => $this->syrup->id, 'unit' => 'piece', 'qty' => 2]]]),
        ])->json('results');

        $this->assertSame(['synced', 'refused', 'synced'], array_column($results, 'status'));
        $this->assertSame(2, Invoice::count());
        $this->assertSame(['T-B-1', 'T-B-3'], Invoice::orderBy('id')->pluck('offline_ref')->all());
    }

    // ------------------------------------------------------------------ who and what

    public function test_the_sales_sent_are_checked(): void
    {
        $this->actingAs($this->staff);
        $this->postJson('/admin/counter/offline/sync', ['sales' => []])->assertJsonValidationErrors('sales');
        $this->postJson('/admin/counter/offline/sync', ['sales' => [$this->sale(['method' => 'cheque'])]])->assertJsonValidationErrors('sales.0.method');
        $this->postJson('/admin/counter/offline/sync', ['sales' => [$this->sale(['lines' => []])]])->assertJsonValidationErrors('sales.0.lines');
        $this->postJson('/admin/counter/offline/sync', ['sales' => [$this->sale(['lines' => [['id' => $this->napa->id, 'unit' => 'crate', 'qty' => 1]]])]])->assertJsonValidationErrors('sales.0.lines.0.unit');
        $this->postJson('/admin/counter/offline/sync', ['sales' => [$this->sale(['lines' => [['id' => $this->napa->id, 'unit' => 'box', 'qty' => 0]]])]])->assertJsonValidationErrors('sales.0.lines.0.qty');
        $this->postJson('/admin/counter/offline/sync', ['sales' => array_fill(0, 51, $this->sale())])->assertJsonValidationErrors('sales');
        $this->assertSame(0, Invoice::count());
    }

    public function test_only_counter_staff_can_send_sales(): void
    {
        auth()->logout();
        $this->postJson('/admin/counter/offline/sync', ['sales' => [$this->sale()]])->assertUnauthorized();

        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->postJson('/admin/counter/offline/sync', ['sales' => [$this->sale()]])->assertRedirect('/');
        $this->assertSame(0, Invoice::count());
    }
}
