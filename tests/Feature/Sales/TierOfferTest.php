<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\TierOffer;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use App\Services\Sales\TierOffers;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TierOfferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $shop;

    private Medicine $napa;

    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $tier = Tier::create(['name' => 'B', 'discount_percent' => 10]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->stock = app(StockService::class);
        $this->receive(5, 25); // bought at 25%: cost 225 a box
    }

    private function receive(int $boxes, float $percent): void
    {
        $this->stock->receive(StockSource::create(['name' => 'S'.$percent.$boxes, 'type' => 'company', 'default_percent' => $percent]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => $boxes]],
        ]);
    }

    private function percent(User $u): float
    {
        return app(PriceResolver::class)->resolve($u->fresh(), $this->napa)->percent;
    }

    public function test_an_offer_gives_every_shop_that_rate_and_never_a_worse_one(): void
    {
        $this->assertSame(10.0, $this->percent($this->shop));

        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20])->assertSessionHasNoErrors();
        $this->assertSame(20.0, $this->percent($this->shop));
        $this->assertSame('offer', app(PriceResolver::class)->resolve($this->shop, $this->napa)->layer);

        $better = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 22])->id]);
        $this->assertSame(22.0, $this->percent($better), 'the tier is already better, so it keeps it');
    }

    public function test_a_manual_rate_stays_above_the_offer(): void
    {
        CustomerRate::create(['user_id' => $this->shop->id, 'medicine_id' => $this->napa->id, 'discount_percent' => 12]);
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);

        $this->assertSame(12.0, $this->percent($this->shop));
    }

    public function test_only_paikari_buyers_get_a_tier_offer(): void
    {
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);
        $public = User::factory()->create(['account_type' => AccountType::Public]);

        $this->assertSame(0.0, $this->percent($public));
    }

    public function test_it_pauses_when_the_stock_is_gone_and_returns_with_new_stock(): void
    {
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);
        $this->stock->setMasterSync(true);
        $this->stock->sell($this->napa, 5, 240, StockService::SALE_PAIKARI, $this->admin);

        $this->assertSame(10.0, $this->percent($this->shop), 'nothing left, so the normal rate');

        $this->receive(3, 25);
        $this->assertSame(20.0, $this->percent($this->shop), 'new stock inside the month picks the offer up again');
    }

    public function test_it_ends_with_the_month_and_then_asks_the_admin(): void
    {
        Carbon::setTestNow('2026-10-12 10:00');
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);
        $this->assertSame(20.0, $this->percent($this->shop));

        Carbon::setTestNow('2026-11-02 10:00');
        $this->assertSame(10.0, $this->percent($this->shop), 'the month is over');
        $this->actingAs($this->admin)->get('/admin/offers')->assertInertia(fn (Assert $p) => $p->where('asking.0.name', 'Napa'));

        $offer = TierOffer::firstOrFail();
        $this->post("/admin/offers/{$offer->id}/renew")->assertSessionHasNoErrors();
        $this->assertSame(20.0, $this->percent($this->shop));
        $this->get('/admin/offers')->assertInertia(fn (Assert $p) => $p->where('asking', []));
        Carbon::setTestNow();
    }

    public function test_the_admin_can_switch_it_off_and_leave_it_off(): void
    {
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);
        $offer = TierOffer::firstOrFail();

        $this->post("/admin/offers/{$offer->id}/off")->assertSessionHasNoErrors();
        $this->assertSame(10.0, $this->percent($this->shop));
    }

    public function test_a_rate_below_the_minimum_margin_is_refused_and_a_new_offer_replaces_the_old(): void
    {
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 26])->assertSessionHasErrors('offer'); // 222 is under 225 plus 3%
        $this->assertSame(0, TierOffer::count());

        $this->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);
        $this->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 18]);
        $this->assertSame(1, TierOffer::where('is_on', true)->count());
        $this->assertSame(18.0, $this->percent($this->shop));
    }

    public function test_suggestions_list_high_discount_lots_and_hide_ones_already_on_offer(): void
    {
        $this->receive(4, 14); // below the 20% threshold
        $this->actingAs($this->admin)->get('/admin/offers')->assertInertia(fn (Assert $p) => $p
            ->where('suggestions.0.name', 'Napa')->where('suggestions.0.purchase_percent', 25)->where('suggestions', fn ($s) => count($s) === 1));

        $this->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);
        $this->get('/admin/offers')->assertInertia(fn (Assert $p) => $p->where('suggestions', []));
    }

    public function test_staff_and_shops_cannot_reach_the_offer_screen(): void
    {
        foreach ([AccountType::Staff, AccountType::Paikari, AccountType::Public] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get('/admin/offers')->assertRedirect('/');
        }
        $this->actingAs($this->shop)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20])->assertRedirect('/');
        $this->assertSame(0, TierOffer::count());
    }

    public function test_the_shop_sees_the_offer_price_but_nothing_about_stock_or_offers(): void
    {
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);

        $json = $this->actingAs($this->shop)->get('/paikari/search?q=Napa')->assertJsonPath('results.0.units.0.price', 240)->getContent();
        $this->assertStringNotContainsStringIgnoringCase('stock', $json);
        $this->assertStringNotContainsStringIgnoringCase('cost', $json);
        $this->assertStringNotContainsString('225', $json);
    }

    public function test_the_service_reports_a_live_offer_only_with_stock(): void
    {
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20]);
        $this->assertNotNull(app(TierOffers::class)->liveFor($this->napa));
    }

    public function test_the_offer_can_be_given_its_own_last_day_and_stops_after_it(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20, 'ends_on' => '2026-10-15'])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-15', TierOffer::firstOrFail()->ends_on->toDateString());
        Carbon::setTestNow('2026-10-15 20:00:00');
        $this->assertSame(20.0, $this->percent($this->shop), 'the last day still counts');

        Carbon::setTestNow('2026-10-16 08:00:00');
        $this->assertSame(10.0, $this->percent($this->shop), 'the next day it is back to normal');
        Carbon::setTestNow();
    }

    public function test_without_a_last_day_the_offer_runs_to_the_end_of_the_month(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');

        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-31', TierOffer::firstOrFail()->ends_on->toDateString());
        Carbon::setTestNow();
    }

    public function test_a_last_day_in_the_past_is_refused(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');

        $this->actingAs($this->admin)->post('/admin/offers', ['medicine_id' => $this->napa->id, 'percent' => 20, 'ends_on' => '2026-10-01'])->assertSessionHasErrors('ends_on');
        $this->assertSame(0, TierOffer::count());
        Carbon::setTestNow();
    }
}
