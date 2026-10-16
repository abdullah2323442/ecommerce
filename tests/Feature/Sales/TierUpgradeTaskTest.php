<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\TierUpgrade;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TierUpgradeTaskTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $admin;

    private User $staff;

    private Tier $b;

    private Tier $a;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->b = Tier::create(['name' => 'B', 'discount_percent' => 5]);
        $this->a = Tier::create(['name' => 'A', 'discount_percent' => 10, 'upgrade_target' => 2000]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $this->b->id]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 100]],
        ]);
        $stock->setMasterSync(true);
    }

    /** A finished counter sale to the shop: boxes at its tier price (285 for tier B). */
    private function sell(int $boxes): void
    {
        $inv = app(InvoiceService::class);
        $draft = $inv->open('paikari', $this->staff, $this->shop->fresh());
        $inv->addLine($draft, $this->napa, 'box', $boxes, $this->staff);
        $inv->finalise($draft, $this->staff);
    }

    public function test_the_window_is_the_last_days_of_the_month(): void
    {
        $u = new TierUpgrade;
        $this->assertTrue($u->inWindow(Carbon::parse('2026-10-29')));
        $this->assertTrue($u->inWindow(Carbon::parse('2026-10-31')));
        $this->assertFalse($u->inWindow(Carbon::parse('2026-10-28')));
        $this->assertFalse($u->inWindow(Carbon::parse('2026-10-01')));
    }

    public function test_the_task_is_shown_only_at_month_end_with_the_gap(): void
    {
        Carbon::setTestNow('2026-10-12 12:00');
        $this->sell(4); // 4 boxes at 285 = 1140

        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('tierTask', null));

        Carbon::setTestNow('2026-10-30 12:00');
        $this->actingAs($this->shop->fresh())->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('tierTask.tier', 'A')->where('tierTask.gap', 860));
        Carbon::setTestNow();
    }

    public function test_a_shop_that_met_the_target_comes_to_the_admin_who_upgrades_by_hand(): void
    {
        $this->sell(8); // 2280, over the 2000 target
        $this->assertSame($this->b->id, $this->shop->fresh()->tier_id, 'nothing is automatic');

        $this->actingAs($this->admin)->get('/admin/customers')->assertInertia(fn (Assert $p) => $p
            ->where('upgrades.0.name', $this->shop->name)->where('upgrades.0.next_tier', 'A'));

        $this->patch("/admin/customers/{$this->shop->id}/tier", ['tier_id' => $this->a->id])->assertSessionHasNoErrors();
        $this->assertSame($this->a->id, $this->shop->fresh()->tier_id);

        $notice = Notice::firstOrFail();
        $this->assertSame('tier_up', $notice->kind);
        $this->assertSame('A', json_decode($notice->body, true)['name']);
    }

    public function test_a_shop_below_the_target_is_not_a_candidate(): void
    {
        $this->sell(2);
        $this->actingAs($this->admin)->get('/admin/customers')->assertInertia(fn (Assert $p) => $p->where('upgrades', []));
    }

    public function test_staff_cannot_upgrade(): void
    {
        $this->sell(8);
        $this->actingAs($this->staff)->patch("/admin/customers/{$this->shop->id}/tier", ['tier_id' => $this->a->id])->assertRedirect('/');
        $this->assertSame($this->b->id, $this->shop->fresh()->tier_id);
    }

    public function test_the_shop_sees_the_congratulations_and_can_close_it(): void
    {
        Notice::create(['user_id' => $this->shop->id, 'kind' => 'tier_up', 'body' => 'A']);

        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('notices.0.body', 'A'));
        $this->post('/paikari/notices/'.Notice::first()->id.'/read')->assertSessionHasNoErrors();
        $this->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('notices', []));
    }

    public function test_one_shop_cannot_close_anothers_notice(): void
    {
        $n = Notice::create(['user_id' => $this->shop->id, 'kind' => 'tier_up', 'body' => 'A']);
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->post("/paikari/notices/{$n->id}/read")->assertRedirect('/');
        $this->assertNull($n->fresh()->read_at);
    }

    public function test_the_tier_target_is_saved_from_the_tiers_screen(): void
    {
        $this->actingAs($this->admin)->put("/admin/tiers/{$this->b->id}", ['name' => 'B', 'discount_percent' => 5, 'upgrade_target' => 500])->assertSessionHasNoErrors();
        $this->assertSame('500.00', (string) $this->b->fresh()->upgrade_target);
    }
}
