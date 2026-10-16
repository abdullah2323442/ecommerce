<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\MarketStatement;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Market\Statements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14: the month-end statement, "Paid" or "Problem", and no reply means no selling. */
class StatementsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $reviewer;

    private Listing $chair;

    private Statements $statements;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->seller = $this->seller('Rahim Timber');
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);
        $this->chair = $this->listing($this->seller, $wood);
        $this->statements = app(Statements::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seller(string $name): User
    {
        return User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => $name])->fresh();
    }

    private function listing(User $seller, Sector $sector): Listing
    {
        return Listing::create(['seller_id' => $seller->id, 'sector_id' => $sector->id, 'title' => 'Chair of '.$seller->business_name, 'public_price' => 1000, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
    }

    /** A complete sale: 2 chairs, our commission 40. */
    private function sale(Listing $l, string $deliveredOn = '2026-09-15 12:00:00', string $status = 'delivered'): MarketOrder
    {
        return MarketOrder::create([
            'public_token' => str()->random(40), 'listing_id' => $l->id, 'seller_id' => $l->seller_id, 'title' => $l->title, 'unit' => 'foot', 'quantity' => 2, 'unit_price' => 1020, 'seller_price' => 1000,
            'commission' => 40, 'delivery_charge' => 0, 'total' => 2040, 'audience' => 'public', 'ship_to' => ['name' => 'X', 'phone' => '01711000000', 'address' => 'Y'], 'status' => $status,
            'delivered_at' => $status === 'delivered' ? Carbon::parse($deliveredOn) : null,
        ]);
    }

    // ------------------------------------------------------------------ sending

    public function test_the_statement_for_last_month_goes_to_each_seller_who_made_complete_sales(): void
    {
        $this->sale($this->chair);
        $this->sale($this->chair, '2026-09-28 10:00:00');
        $this->sale($this->chair, '2026-08-30 10:00:00');                     // another month
        $this->sale($this->chair, '2026-09-10 10:00:00', 'cancelled');        // not a sale
        $quiet = $this->seller('Quiet Seller');
        $this->sale($this->listing($quiet, Sector::first()), '2026-09-10 10:00:00', 'not_received');

        $this->assertSame(1, $this->statements->generate());

        $s = MarketStatement::firstOrFail();
        $this->assertSame([$this->seller->id, '2026-09', 2, '4000.00', '80.00', 'sent'], [$s->seller_id, $s->month, $s->orders, (string) $s->sales, (string) $s->commission, $s->status]);

        $notice = Notice::where('user_id', $this->seller->id)->where('kind', 'market_statement')->firstOrFail();
        $body = json_decode($notice->body, true);
        $this->assertEquals([80, 4000, '2026-09'], [$body['commission'], $body['sales'], $body['name']]);
    }

    public function test_sending_twice_does_not_send_twice(): void
    {
        $this->sale($this->chair);

        $this->assertSame(1, $this->statements->generate());
        $this->assertSame(0, $this->statements->generate());
        $this->assertSame(1, MarketStatement::count());
    }

    public function test_the_owner_can_send_one_for_a_month_by_hand(): void
    {
        $this->sale($this->chair, '2026-07-15 10:00:00');
        $owner = User::factory()->create(['account_type' => AccountType::Admin]);

        $this->actingAs($this->reviewer)->post('/admin/market/statements', ['month' => '2026-07'])->assertRedirect('/');
        $this->actingAs($owner)->post('/admin/market/statements', ['month' => '2026-07'])->assertSessionHasNoErrors();
        $this->assertSame('2026-07', MarketStatement::firstOrFail()->month);
        $this->post('/admin/market/statements', ['month' => 'July'])->assertSessionHasErrors('month');
    }

    // ------------------------------------------------------------------ the seller's answer

    public function test_the_seller_sees_the_statement_and_answers_paid(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        $s = MarketStatement::firstOrFail();

        $this->actingAs($this->seller)->get('/wholesale/sell/statements')->assertInertia(fn (Assert $p) => $p->has('statements', 1)->where('statements.0.commission', 40)->where('statements.0.status', 'sent'));
        $this->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'paid'])->assertSessionHasNoErrors();

        $this->assertSame('paid', $s->fresh()->status);
        $this->assertNotNull($s->fresh()->replied_at);
        $this->assertSame(1, Notice::where('user_id', $this->reviewer->id)->where('kind', 'statement_reply')->count());
    }

    public function test_a_problem_needs_the_reason_written(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        $s = MarketStatement::firstOrFail();
        $this->actingAs($this->seller);

        $this->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'problem'])->assertSessionHasErrors('statement');
        $this->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'problem', 'note' => 'ab'])->assertSessionHasErrors('statement');
        $this->assertSame('sent', $s->fresh()->status);

        $this->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'problem', 'note' => 'Two of those orders were returned to me.'])->assertSessionHasNoErrors();
        $this->assertSame(['problem', 'Two of those orders were returned to me.'], [$s->fresh()->status, $s->fresh()->seller_note]);
    }

    public function test_only_the_seller_can_answer_their_own_statement_and_only_with_paid_or_problem(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        $s = MarketStatement::firstOrFail();
        $other = $this->seller('Other');

        $this->actingAs($other)->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'paid'])->assertSessionHasErrors('statement');
        $this->actingAs($this->seller)->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'later'])->assertSessionHasErrors('answer');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'paid'])->assertRedirect('/');
        $this->assertSame('sent', $s->fresh()->status);

        $this->actingAs($this->seller)->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'paid']);
        $this->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'paid'])->assertSessionHasErrors('statement');   // answered already
    }

    // ------------------------------------------------------------------ our check

    public function test_we_check_a_paid_statement_or_send_it_back(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        $s = MarketStatement::firstOrFail();
        $this->actingAs($this->seller)->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'paid']);

        $this->actingAs($this->reviewer)->get('/admin/market/statements')->assertInertia(fn (Assert $p) => $p->has('waiting', 1)->where('waiting.0.seller', 'Rahim Timber'));

        $this->post("/admin/market/statements/{$s->id}/reopen", [])->assertSessionHasErrors('note');
        $this->post("/admin/market/statements/{$s->id}/reopen", ['note' => 'We have not received it.'])->assertSessionHasNoErrors();
        $this->assertSame(['sent', 'We have not received it.'], [$s->fresh()->status, $s->fresh()->our_note]);
        $this->assertSame(2, Notice::where('user_id', $this->seller->id)->where('kind', 'market_statement')->count());

        $this->actingAs($this->seller)->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'paid']);
        $this->actingAs($this->reviewer)->post("/admin/market/statements/{$s->id}/verify", ['note' => 'Received on the 5th.'])->assertSessionHasNoErrors();
        $this->assertSame(['verified', $this->reviewer->id], [$s->fresh()->status, $s->fresh()->decided_by]);
        $this->post("/admin/market/statements/{$s->id}/verify")->assertSessionHasErrors('statement');   // nothing left to check
    }

    public function test_only_staff_who_manage_customers_check_statements(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        $s = MarketStatement::firstOrFail();
        $counterOnly = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);

        $this->actingAs($this->seller)->post("/admin/market/statements/{$s->id}/verify")->assertRedirect('/');
        $this->actingAs($counterOnly)->get('/admin/market/statements')->assertRedirect('/');
        $this->assertSame('sent', $s->fresh()->status);
    }

    // ------------------------------------------------------------------ no reply: no selling

    public function test_a_statement_nobody_answers_switches_selling_off(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        $other = $this->seller('Other');   // no statement: untouched

        Carbon::setTestNow(now()->addDays(9));
        $this->assertSame(0, $this->statements->suspendSilent());

        Carbon::setTestNow(now()->addDays(2));   // 11 days
        $this->assertSame(1, $this->statements->suspendSilent());

        $this->assertSame('suspended', MarketStatement::firstOrFail()->status);
        $this->assertTrue($this->seller->fresh()->sellingStopped());
        $this->assertFalse($other->fresh()->sellingStopped());
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'market_suspended')->count());
        $this->assertSame(0, $this->statements->suspendSilent());   // not stopped twice
    }

    public function test_a_stopped_seller_shows_nothing_takes_no_orders_and_lists_nothing_new_but_the_account_still_works(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        Carbon::setTestNow(now()->addDays(11));
        $this->statements->suspendSilent();

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 0));
        $this->post("/market/{$this->chair->id}/order", ['quantity' => 1, 'name' => 'A B', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'X', 'address' => 'Y'])->assertRedirect('/');
        $this->assertSame(1, MarketOrder::count());

        $this->actingAs($this->seller->fresh())->get('/wholesale/sell/statements')->assertOk()->assertInertia(fn (Assert $p) => $p->where('stopped', true));
        $this->get('/wholesale')->assertOk();   // the buying side is not touched
        $this->post('/wholesale/sell', ['sector_id' => Sector::first()->id, 'title' => 'New chair', 'public_price' => 500])->assertRedirect('/');   // cannot list
    }

    public function test_answering_brings_selling_back_even_before_we_have_checked(): void
    {
        $this->sale($this->chair);
        $this->statements->generate();
        $s = MarketStatement::firstOrFail();
        Carbon::setTestNow(now()->addDays(11));
        $this->statements->suspendSilent();

        $this->actingAs($this->seller)->post("/wholesale/sell/statements/{$s->id}/reply", ['answer' => 'problem', 'note' => 'I disagree with the count.'])->assertSessionHasNoErrors();

        $this->assertFalse($this->seller->fresh()->sellingStopped());
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 1));
    }

    public function test_a_seller_with_two_months_waiting_stays_stopped_until_both_are_answered(): void
    {
        $this->sale($this->chair, '2026-08-15 12:00:00');
        $this->sale($this->chair, '2026-09-15 12:00:00');
        $this->statements->generate('2026-08');
        $this->statements->generate('2026-09');
        Carbon::setTestNow(now()->addDays(11));
        $this->statements->suspendSilent();
        [$aug, $sep] = [MarketStatement::where('month', '2026-08')->firstOrFail(), MarketStatement::where('month', '2026-09')->firstOrFail()];

        $this->actingAs($this->seller)->post("/wholesale/sell/statements/{$aug->id}/reply", ['answer' => 'paid']);
        $this->assertTrue($this->seller->fresh()->sellingStopped());
        $this->post("/wholesale/sell/statements/{$sep->id}/reply", ['answer' => 'paid']);
        $this->assertFalse($this->seller->fresh()->sellingStopped());
    }

    public function test_we_can_switch_selling_back_on_by_hand(): void
    {
        $this->seller->forceFill(['seller_suspended_at' => now()])->save();

        $this->actingAs($this->reviewer)->put("/admin/market/sellers/{$this->seller->id}/selling")->assertSessionHasNoErrors();
        $this->assertFalse($this->seller->fresh()->sellingStopped());
    }

    public function test_the_days_to_answer_are_a_setting(): void
    {
        Setting::write('market.reply_days', 3);
        $this->sale($this->chair);
        $this->statements->generate();

        Carbon::setTestNow(now()->addDays(4));
        $this->assertSame(1, $this->statements->suspendSilent());
    }
}
