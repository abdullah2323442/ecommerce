<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 6.6: a draft that sits for a day or two is asked about, with two buttons: return to stock, or keep. */
class StaleDraftTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]]]);
        $stock->setMasterSync(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function draft(int $hoursIdle, string $channel = 'counter'): Invoice
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail'])->assertSessionHasNoErrors();
        $draft = Invoice::latest('id')->firstOrFail();
        $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 3])->assertSessionHasNoErrors();
        $draft->forceFill(['channel' => $channel, 'last_activity_at' => now()->subHours($hoursIdle), 'opened_at' => now()->subHours($hoursIdle)])->save();

        return $draft;
    }

    private function listed(Invoice $draft): ?array
    {
        $rows = $this->get('/admin/counter')->viewData('page')['props']['drafts'];

        return collect($rows)->firstWhere('id', $draft->id);
    }

    public function test_a_draft_untouched_for_a_day_is_marked_but_a_fresh_one_is_not(): void
    {
        $old = $this->draft(30);
        $fresh = $this->draft(2);

        $this->assertTrue($this->listed($old)['stale']);
        $this->assertFalse($this->listed($fresh)['stale']);
    }

    public function test_the_time_to_ask_is_a_setting(): void
    {
        $draft = $this->draft(30);
        Setting::write('drafts.stale_hours', 48);

        $this->assertFalse($this->listed($draft)['stale']);
    }

    public function test_keep_holds_the_goods_and_asks_again_after_two_days(): void
    {
        $draft = $this->draft(30);

        $this->post("/admin/counter/{$draft->id}/keep")->assertSessionHasNoErrors();
        $this->assertFalse($this->listed($draft)['stale']);
        $this->assertSame(7.0, app(StockService::class)->onHand($this->napa));   // still held

        Carbon::setTestNow(now()->addDay());
        $this->assertFalse($this->listed($draft)['stale']);   // a day on: still quiet

        Carbon::setTestNow(now()->addDays(2));
        $this->assertTrue($this->listed($draft)['stale']);    // asked again
    }

    public function test_return_to_stock_closes_the_draft_and_gives_the_goods_back(): void
    {
        $draft = $this->draft(30);
        $this->assertSame(7.0, app(StockService::class)->onHand($this->napa));

        $this->post("/admin/counter/{$draft->id}/cancel")->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->assertSame(10.0, app(StockService::class)->onHand($this->napa));
    }

    public function test_online_carts_and_finished_sales_are_never_asked_about(): void
    {
        $cart = $this->draft(30, 'online');
        $this->assertFalse($this->listed($cart)['stale']);

        $done = $this->draft(30);
        $this->post("/admin/counter/{$done->id}/finalise")->assertSessionHasNoErrors();
        $this->post("/admin/counter/{$done->id}/keep")->assertSessionHasErrors('invoice');
    }

    public function test_only_counter_staff_can_keep_a_draft(): void
    {
        $draft = $this->draft(30);
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($shop)->post("/admin/counter/{$draft->id}/keep")->assertRedirect('/');
        $this->assertNull($draft->fresh()->stale_snooze_until);
    }

    public function test_the_counter_page_carries_the_flag_for_the_screen(): void
    {
        $this->draft(30);

        $this->actingAs($this->staff)->get('/admin/counter')->assertInertia(fn (Assert $p) => $p->where('drafts.0.stale', true));
    }
}
