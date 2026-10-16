<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketCase;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\SellerPenalty;
use App\Models\User;
use App\Services\Market\Marketplace;
use App\Services\Market\Penalties;
use App\Services\Market\SellerTrust;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14: gradual trust for new sellers, and the penalty steps the owner can take. */
class PenaltiesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $reviewer;

    private Sector $wood;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber'])->fresh();
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);
        $this->chair = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $status = 'delivered'): MarketOrder
    {
        return MarketOrder::create([
            'public_token' => str()->random(40), 'listing_id' => $this->chair->id, 'seller_id' => $this->seller->id, 'title' => 'Teak chair', 'unit' => 'foot', 'quantity' => 1, 'unit_price' => 1020,
            'seller_price' => 1000, 'commission' => 20, 'delivery_charge' => 0, 'total' => 1020, 'audience' => 'public', 'ship_to' => ['name' => 'X', 'phone' => '01711000000', 'address' => 'Y'],
            'status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null, 'not_received_at' => $status === 'not_received' ? now() : null,
        ]);
    }

    private function dear(): array
    {
        return ['sector_id' => $this->wood->id, 'title' => 'Teak wardrobe', 'public_price' => 6200];
    }

    // ------------------------------------------------------------------ gradual trust

    public function test_a_new_seller_cannot_list_a_dear_product(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessage('5000');

        app(Marketplace::class)->submit($this->seller, $this->dear());
    }

    public function test_the_limit_counts_both_prices(): void
    {
        $this->expectException(StockException::class);

        app(Marketplace::class)->submit($this->seller, ['sector_id' => $this->wood->id, 'title' => 'Bulk', 'public_price' => 100, 'wholesale_price' => 9000]);
    }

    public function test_a_cheap_product_is_fine_for_a_new_seller(): void
    {
        $listing = app(Marketplace::class)->submit($this->seller, ['sector_id' => $this->wood->id, 'title' => 'Stool', 'public_price' => 5000]);

        $this->assertSame('pending', $listing->status);
    }

    public function test_clean_orders_lift_the_limit(): void
    {
        $trust = app(SellerTrust::class);
        foreach (range(1, 9) as $i) {
            $this->order();
        }
        $this->assertFalse($trust->trusted($this->seller));
        $this->assertSame(9, $trust->clean($this->seller));

        $this->order();

        $this->assertTrue($trust->trusted($this->seller));
        $this->assertNull($trust->ceiling($this->seller));
        $this->assertSame('pending', app(Marketplace::class)->submit($this->seller, $this->dear())->status);
    }

    public function test_an_order_with_a_complaint_is_not_clean(): void
    {
        $trust = app(SellerTrust::class);
        $bad = $this->order();
        $this->order();
        MarketCase::create(['market_order_id' => $bad->id, 'seller_id' => $this->seller->id, 'kind' => 'complaint', 'reason' => 'Loose leg', 'status' => 'solved']);

        $this->assertSame(1, $trust->clean($this->seller));
    }

    public function test_the_owner_can_trust_a_seller_early_and_take_it_back(): void
    {
        $this->actingAs($this->reviewer)->put("/admin/market/sellers/{$this->seller->id}/trust", ['trusted' => true])->assertSessionHasNoErrors();
        $this->assertTrue(app(SellerTrust::class)->trusted($this->seller->fresh()));

        $this->actingAs($this->reviewer)->put("/admin/market/sellers/{$this->seller->id}/trust", ['trusted' => false]);
        $this->assertFalse(app(SellerTrust::class)->trusted($this->seller->fresh()));
    }

    public function test_the_limit_is_a_setting(): void
    {
        \App\Models\Setting::write('market.trust_limit', 8000);

        $this->assertSame('pending', app(Marketplace::class)->submit($this->seller, $this->dear())->status);
    }

    public function test_the_seller_is_told_where_they_stand(): void
    {
        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('trust.trusted', false)->where('trust.limit', 5000)->where('trust.needed', 10));
    }

    // ------------------------------------------------------------------ steps

    public function test_a_warning_is_a_message_and_a_record(): void
    {
        $p = app(Penalties::class)->issue($this->seller, $this->reviewer, 'warning', 'No leaflets in the package');

        $this->assertSame('warning', $p->step);
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'market_penalty')->count());
        $this->assertFalse($this->seller->fresh()->sellingStopped());
    }

    public function test_taking_a_listing_down_hides_it_and_tells_the_seller_why(): void
    {
        app(Penalties::class)->issue($this->seller, $this->reviewer, 'listing_removed', 'Copied from another shop', $this->chair);

        $this->assertSame(['rejected', 'Copied from another shop'], [$this->chair->fresh()->status, $this->chair->fresh()->review_note]);
        $this->assertCount(0, Listing::shown()->get());
    }

    public function test_taking_a_listing_down_needs_one_of_their_own(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);
        $theirs = Listing::create(['seller_id' => $other->id, 'sector_id' => $this->wood->id, 'title' => 'Theirs', 'public_price' => 100, 'status' => 'published']);

        $this->expectException(StockException::class);
        app(Penalties::class)->issue($this->seller, $this->reviewer, 'listing_removed', 'Copied', $theirs);
    }

    public function test_a_suspension_switches_selling_off_until_we_lift_it(): void
    {
        $penalties = app(Penalties::class);
        $penalties->issue($this->seller, $this->reviewer, 'suspended', 'Said it was sent when it was not');

        $seller = $this->seller->fresh();
        $this->assertTrue($seller->sellingStopped());
        $this->assertFalse($seller->statementStopped());
        $this->assertFalse(app(Marketplace::class)->canSell($seller));
        $this->assertCount(0, Listing::shown()->get());
        $this->assertCount(1, $penalties->active($seller));

        $penalties->lift($seller);

        $this->assertFalse($this->seller->fresh()->sellingStopped());
        $this->assertCount(0, $penalties->active($this->seller));
        $this->assertCount(1, $penalties->history($this->seller));   // it stays on record
    }

    public function test_answering_a_statement_does_not_undo_a_suspension(): void
    {
        app(Penalties::class)->issue($this->seller, $this->reviewer, 'suspended', 'Repeated problems');

        app(\App\Services\Market\Statements::class)->liftIfClear($this->seller);

        $this->assertTrue($this->seller->fresh()->sellingStopped());
    }

    public function test_a_hold_is_recorded_and_cannot_be_put_on_twice(): void
    {
        $penalties = app(Penalties::class);
        $penalties->issue($this->seller, $this->reviewer, 'withheld', 'Did not refund a return');

        $this->assertNotNull($this->seller->fresh()->payout_withheld_at);

        $this->expectException(StockException::class);
        $penalties->issue($this->seller, $this->reviewer, 'withheld', 'Again');
    }

    public function test_a_reason_is_needed(): void
    {
        $this->expectException(StockException::class);
        app(Penalties::class)->issue($this->seller, $this->reviewer, 'warning', ' ');
    }

    public function test_the_next_step_follows_the_ones_taken(): void
    {
        $penalties = app(Penalties::class);
        $this->assertSame('warning', $penalties->next($this->seller));

        $penalties->issue($this->seller, $this->reviewer, 'warning', 'First');
        $this->assertSame('listing_removed', $penalties->next($this->seller));

        $penalties->issue($this->seller, $this->reviewer, 'suspended', 'Second');
        $this->assertSame('listing_removed', $penalties->next($this->seller));   // the first step not yet taken
    }

    public function test_repeated_not_received_puts_a_seller_on_the_watch_list(): void
    {
        $penalties = app(Penalties::class);
        $this->order('not_received');
        $this->order('not_received');
        $this->assertCount(0, $penalties->watch());

        $this->order('not_received');

        $this->assertSame([$this->seller->id, 3], [$penalties->watch()->first()['seller_id'], $penalties->watch()->first()['missing']]);
    }

    // ------------------------------------------------------------------ pages

    public function test_we_take_a_step_from_the_page_and_the_seller_sees_why(): void
    {
        $this->actingAs($this->reviewer)->post("/admin/market/sellers/{$this->seller->id}/penalty", ['step' => 'suspended', 'reason' => 'Repeated not received'])->assertSessionHasNoErrors();
        $this->assertTrue($this->seller->fresh()->sellingStopped());

        $this->actingAs($this->seller->fresh())->get('/wholesale/sell/statements')->assertInertia(fn (Assert $p) => $p->component('market/statements')
            ->where('penalties.0.reason', 'Repeated not received')->where('stopped', false));

        $this->actingAs($this->reviewer)->put("/admin/market/sellers/{$this->seller->id}/penalty/lift")->assertSessionHasNoErrors();
        $this->assertFalse($this->seller->fresh()->sellingStopped());
    }

    public function test_the_page_lists_every_seller_with_trust_rank_and_history(): void
    {
        app(Penalties::class)->issue($this->seller, $this->reviewer, 'warning', 'Careful');

        $this->actingAs($this->reviewer)->get('/admin/market/cases')->assertInertia(fn (Assert $p) => $p->component('admin/market-cases')
            ->has('standing', 1)->where('standing.0.trust.trusted', false)->where('standing.0.next', 'listing_removed')->where('standing.0.history.0.reason', 'Careful')
            ->where('steps', SellerPenalty::STEPS));
    }

    public function test_only_those_who_manage_customers_may_take_steps(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->post("/admin/market/sellers/{$this->seller->id}/penalty", ['step' => 'warning', 'reason' => 'Nope'])->assertRedirect('/');
        $this->actingAs($this->seller)->put("/admin/market/sellers/{$this->seller->id}/trust", ['trusted' => true])->assertRedirect('/');

        $this->assertSame(0, SellerPenalty::count());
        $this->assertNull($this->seller->fresh()->seller_trusted_at);
    }

    public function test_a_step_cannot_be_taken_against_someone_who_is_not_a_seller(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($this->reviewer)->post("/admin/market/sellers/{$buyer->id}/penalty", ['step' => 'warning', 'reason' => 'Nope'])->assertRedirect('/');
        $this->assertSame(0, SellerPenalty::count());
    }
}
