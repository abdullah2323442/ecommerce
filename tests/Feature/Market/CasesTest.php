<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketCase;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Market\MarketCases;
use App\Services\Market\MarketOrders;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14: complaints seen by both sides, returns straight to the seller, and returns on the seller's record. */
class CasesTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private User $reviewer;

    private Listing $chair;

    private MarketCases $cases;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00:00');
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber'])->fresh();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);
        $this->chair = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'stock' => 10, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
        $this->cases = app(MarketCases::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $status = 'delivered', string $deliveredOn = '2026-10-08 12:00:00'): MarketOrder
    {
        return MarketOrder::create([
            'public_token' => str()->random(40), 'listing_id' => $this->chair->id, 'seller_id' => $this->seller->id, 'buyer_id' => $this->buyer->id, 'title' => 'Teak chair', 'unit' => 'foot',
            'quantity' => 2, 'unit_price' => 1020, 'seller_price' => 1000, 'commission' => 40, 'delivery_charge' => 0, 'total' => 2040, 'audience' => 'public',
            'ship_to' => ['name' => 'X', 'phone' => '01711000000', 'address' => 'Y'], 'status' => $status, 'shipped_at' => now()->subDays(3),
            'delivered_at' => $status === 'delivered' ? Carbon::parse($deliveredOn) : null,
        ]);
    }

    // ------------------------------------------------------------------ opening

    public function test_a_complaint_tells_the_seller_and_gives_us_a_copy(): void
    {
        $order = $this->order();

        $case = $this->cases->open($order, 'complaint', 'One leg is loose');

        $this->assertSame(['open', 'complaint', $this->seller->id], [$case->status, $case->kind, $case->seller_id]);
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'market_case_new')->count());
        $this->assertSame(1, Notice::where('user_id', $this->reviewer->id)->where('kind', 'market_case')->count());
    }

    public function test_it_needs_a_reason_and_an_order_that_was_sent(): void
    {
        $this->expectException(StockException::class);
        $this->cases->open($this->order('placed'), 'complaint', 'Late');
    }

    public function test_a_reason_cannot_be_empty(): void
    {
        $this->expectException(StockException::class);
        $this->cases->open($this->order(), 'complaint', '  ');
    }

    public function test_a_return_is_only_open_for_a_few_days_after_it_arrived(): void
    {
        $late = $this->order('delivered', '2026-10-01 12:00:00');   // nine days ago, the window is seven
        $this->assertSame(['complaint' => true, 'return' => false, 'promo' => true], $this->cases->allowed($late));

        $this->expectException(StockException::class);
        $this->cases->open($late, 'return', 'Changed my mind');
    }

    public function test_the_window_is_a_setting(): void
    {
        Setting::write('market.return_days', 14);
        $order = $this->order('delivered', '2026-10-01 12:00:00');

        $this->assertTrue($this->cases->allowed($order)['return']);
    }

    public function test_the_seller_can_set_their_own_days_and_conditions_for_a_product(): void
    {
        $order = $this->order('delivered', '2026-10-07 12:00:00');   // three days ago
        $this->assertTrue($this->cases->allowed($order)['return'], 'our usual seven days');

        $this->chair->update(['return_days' => 2, 'return_note' => 'Unused, in its wrapping']);
        $this->assertFalse($this->cases->allowed($order->fresh())['return'], 'this seller gives two days');

        $this->chair->update(['return_days' => 30]);
        $this->assertTrue($this->cases->allowed($order->fresh())['return']);

        $this->actingAs($this->buyer)->get("/market/{$this->chair->id}")->assertInertia(fn ($p) => $p->where('listing.return_days', 30)->where('listing.return_note', 'Unused, in its wrapping'));
    }

    public function test_there_is_one_open_case_of_each_kind_at_a_time(): void
    {
        $order = $this->order();
        $this->cases->open($order, 'return', 'Wrong colour');

        $this->assertFalse($this->cases->allowed($order)['return']);
        $this->assertTrue($this->cases->allowed($order)['complaint']);
    }

    // ------------------------------------------------------------------ the seller answers

    public function test_the_seller_can_solve_it(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Loose leg');

        $done = $this->cases->answer($case->load('order'), $this->seller, 'solved', 'I sent a new leg');

        $this->assertSame(['solved', 'I sent a new leg'], [$done->status, $done->seller_note]);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'market_case_answered')->count());
    }

    public function test_taking_it_back_ends_the_sale_and_puts_it_back_on_the_shelf(): void
    {
        $order = $this->order();
        $case = $this->cases->open($order, 'return', 'Wrong size');

        $this->cases->answer($case->load('order'), $this->seller, 'return');

        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame('returned', $case->fresh()->status);
        $this->assertSame('12.00', (string) $this->chair->fresh()->stock);
        $this->assertSame(0, app(MarketOrders::class)->owed()->count());   // no commission on a sale that came back
    }

    public function test_a_seller_who_cannot_solve_it_brings_it_to_us(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Scratched');

        $done = $this->cases->answer($case->load('order'), $this->seller, 'cannot', 'The courier did it');

        $this->assertSame('escalated', $done->status);
        $this->assertNotNull($done->escalated_at);
    }

    public function test_a_solve_or_a_refusal_needs_a_few_words(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Scratched');

        $this->expectException(StockException::class);
        $this->cases->answer($case->load('order'), $this->seller, 'solved', '');
    }

    public function test_only_the_seller_it_is_about_may_answer(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Scratched');
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->expectException(StockException::class);
        $this->cases->answer($case->load('order'), $other, 'return');
    }

    public function test_it_cannot_be_answered_twice(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Scratched');
        $this->cases->answer($case->load('order'), $this->seller, 'solved', 'Fixed it');

        $this->expectException(StockException::class);
        $this->cases->answer($case->fresh()->load('order'), $this->seller, 'solved', 'Again');
    }

    // ------------------------------------------------------------------ us

    public function test_the_buyer_can_bring_it_to_us(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Scratched');
        $this->cases->answer($case->load('order'), $this->seller, 'solved', 'Fixed it');

        $this->assertSame('escalated', $this->cases->escalate($case->fresh()->load(['order', 'seller']))->status);
    }

    public function test_a_case_the_seller_ignores_comes_to_us(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Scratched');
        $fresh = $this->cases->open($this->order(), 'complaint', 'Cracked');

        Carbon::setTestNow('2026-10-21 09:00:00');   // eleven days on, the wait is ten
        MarketCase::whereKey($fresh->id)->update(['created_at' => now()->subDay()]);

        $this->assertSame(1, $this->cases->escalateSilent());
        $this->assertSame('escalated', $case->fresh()->status);
        $this->assertSame('open', $fresh->fresh()->status);
    }

    public function test_we_can_send_it_back_or_close_it(): void
    {
        $order = $this->order();
        $case = $this->cases->open($order, 'complaint', 'Not as shown');
        $this->cases->answer($case->load('order'), $this->seller, 'cannot', 'It is as shown');

        $this->cases->decide($case->fresh()->load(['order', 'seller']), $this->reviewer, 'return', 'The photo was misleading');

        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame(['returned', 'The photo was misleading'], [$case->fresh()->status, $case->fresh()->our_note]);
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'market_case_answered')->count());

        $other = $this->cases->open($this->order(), 'complaint', 'Late');
        $this->assertSame('closed', $this->cases->decide($other->load(['order', 'seller']), $this->reviewer, 'close', 'It came in time')->status);
    }

    public function test_a_settled_case_cannot_be_decided_again(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Late');
        $this->cases->decide($case->load(['order', 'seller']), $this->reviewer, 'close', 'Fine');

        $this->expectException(StockException::class);
        $this->cases->decide($case->fresh()->load(['order', 'seller']), $this->reviewer, 'close', 'Again');
    }

    // ------------------------------------------------------------------ the record

    public function test_returns_go_onto_the_seller_record_with_the_reasons(): void
    {
        $this->order();   // sold
        $back = $this->order();
        $case = $this->cases->open($back, 'return', 'Wrong size');
        $this->cases->answer($case->load('order'), $this->seller, 'return');
        $this->cases->open($this->order(), 'complaint', 'Scratched');

        $record = $this->cases->record($this->seller);

        $this->assertEquals([4, 2, 1, 1, ['Wrong size']], [$record['sold'], $record['returned'], $record['returned_orders'], $record['complaints'], $record['reasons']]);
    }

    public function test_the_serious_ones_show_against_each_seller(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Scratched');
        $this->cases->answer($case->load('order'), $this->seller, 'cannot', 'Not me');
        $this->cases->open($this->order(), 'return', 'Wrong size');

        $row = $this->cases->bySeller()->first();

        $this->assertSame([2, 1, 1, 1, 2], [$row['total'], $row['complaints'], $row['returns'], $row['serious'], $row['waiting']]);
    }

    // ------------------------------------------------------------------ the pages

    public function test_the_buyer_opens_and_brings_a_case_from_the_order_page(): void
    {
        $order = $this->order();

        $this->post("/market/orders/{$order->public_token}/case", ['kind' => 'return', 'reason' => 'Wrong size'])->assertSessionHasNoErrors();
        $case = MarketCase::firstOrFail();

        $this->get("/market/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->component('market/order')
            ->where('cases.0.reason', 'Wrong size')->where('order.can_return', false)->where('order.can_complain', true));

        $this->post("/market/orders/{$order->public_token}/cases/{$case->id}/escalate")->assertSessionHasNoErrors();
        $this->assertSame('escalated', $case->fresh()->status);
    }

    public function test_a_case_cannot_be_brought_through_another_order(): void
    {
        $mine = $this->order();
        $other = $this->order();
        $case = $this->cases->open($other, 'complaint', 'Scratched');

        $this->post("/market/orders/{$mine->public_token}/cases/{$case->id}/escalate")->assertRedirect('/');
        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_the_seller_answers_from_the_orders_page_and_sees_no_buyer_name(): void
    {
        $order = $this->order();
        $case = $this->cases->open($order, 'complaint', 'Loose leg');

        $this->actingAs($this->seller)->get('/wholesale/sell/orders')->assertInertia(fn (Assert $p) => $p->component('market/seller-orders')
            ->where('orders.0.cases.0.reason', 'Loose leg')->has('record')->missing('orders.0.cases.0.buyer_id'));

        $this->actingAs($this->seller)->post("/wholesale/sell/cases/{$case->id}/answer", ['action' => 'solved', 'note' => 'New leg sent'])->assertSessionHasNoErrors();
        $this->assertSame('solved', $case->fresh()->status);
    }

    public function test_another_seller_cannot_answer_it(): void
    {
        $case = $this->cases->open($this->order(), 'complaint', 'Loose leg');
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($other)->post("/wholesale/sell/cases/{$case->id}/answer", ['action' => 'return'])->assertSessionHasErrors('order');
        $this->assertSame('open', $case->fresh()->status);
    }

    public function test_we_see_the_cases_and_decide(): void
    {
        $order = $this->order();
        $case = $this->cases->open($order, 'complaint', 'Not as shown');
        $this->cases->answer($case->load('order'), $this->seller, 'cannot', 'It is as shown');

        $this->actingAs($this->reviewer)->get('/admin/market/cases')->assertInertia(fn (Assert $p) => $p->component('admin/market-cases')
            ->has('serious', 1)->where('serious.0.seller', 'Rahim Timber')->where('sellers.0.serious', 1));

        $this->actingAs($this->reviewer)->post("/admin/market/cases/{$case->id}/decide", ['decision' => 'close', 'note' => 'It is as shown'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $case->fresh()->status);
    }

    public function test_plain_staff_and_sellers_cannot_open_the_cases_page(): void
    {
        $this->actingAs($this->seller)->get('/admin/market/cases')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->get('/admin/market/cases')->assertRedirect('/');
    }

    public function test_a_card_in_the_package_is_reported_by_itself_and_nothing_is_sent_back(): void
    {
        $order = $this->order();

        $case = $this->cases->open($order, 'promo', 'There was a shop leaflet inside');

        $this->assertSame(['open', 'promo'], [$case->status, $case->kind]);
        $this->assertSame(1, Notice::where('user_id', $this->reviewer->id)->where('kind', 'market_case')->where('body', 'like', '%promotion material%')->count());
        $this->assertFalse($this->cases->allowed($order)['promo'], 'One open report at a time.');
        $this->assertSame(1, $this->cases->record($this->seller)['promos']);
        $this->assertSame(0, $this->cases->record($this->seller)['complaints'], 'It is counted on its own, not as an ordinary complaint.');

        try {
            $this->cases->answer($case, $this->seller, 'return');
            $this->fail('A leaflet cannot be sent back.');
        } catch (StockException) {
            $this->assertSame('delivered', $order->fresh()->status);
        }

        $closed = $this->cases->decide($case->fresh(), $this->reviewer, 'close', 'Seller warned');
        $this->assertSame('closed', $closed->status);
    }

    public function test_the_buyer_can_report_a_card_in_the_package_from_the_order_page(): void
    {
        $order = $this->order();

        $this->actingAs($this->buyer)->get('/market/orders/'.$order->public_token)
            ->assertInertia(fn (Assert $p) => $p->where('order.can_report_promo', true));
        $this->actingAs($this->buyer)->post('/market/orders/'.$order->public_token.'/case', ['kind' => 'promo', 'reason' => 'Page address on a card'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('market_cases', ['market_order_id' => $order->id, 'kind' => 'promo']);
    }
}
