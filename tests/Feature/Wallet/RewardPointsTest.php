<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Models\AquaToken;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\RewardPoint;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wallet\RewardPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Reward points (spec ch 27 extras): earned on what a buyer buys, turned into an Aqua Token, taken back when the purchase is cancelled or returned. */
class RewardPointsTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private RewardPoints $points;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->points = app(RewardPoints::class);
    }

    private function on(int $per100 = 2): void
    {
        Setting::write('points.per_100', $per100);
    }

    private function memo(array $over = []): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'kind' => 'paikari', 'channel' => 'counter', 'status' => 'final', 'customer_id' => $this->buyer->id, 'number' => 'M2026-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'total' => 1050, 'delivery_charge' => 50, 'paid_amount' => 1050, 'finalised_at' => now(), 'completed_at' => now(), 'opened_at' => now(),
        ], $over));
    }

    public function test_it_is_off_until_the_owner_sets_points_for_every_100_taka(): void
    {
        $this->memo();

        $this->assertFalse($this->points->enabled());
        $this->assertSame(0, $this->points->balance($this->buyer));
        $this->assertSame(0, RewardPoint::count());
    }

    public function test_a_finished_memo_earns_points_once_and_delivery_is_not_counted(): void
    {
        $this->on(2);
        $memo = $this->memo(); // 1,000 of goods and 50 of delivery

        $this->assertSame(20, $this->points->balance($this->buyer));

        $memo->update(['note' => 'touched again']); // saving the memo again does not pay twice
        $this->points->earnOnInvoice($memo);
        $this->assertSame(20, $this->points->balance($this->buyer));
        $this->assertSame(1, RewardPoint::where('kind', 'earn')->count());
    }

    public function test_a_memo_with_no_customer_and_a_tiny_one_earn_nothing(): void
    {
        $this->on(2);
        $this->memo(['customer_id' => null]);
        $this->memo(['total' => 90, 'delivery_charge' => 50]); // 40 taka of goods is under one point

        $this->assertSame(0, RewardPoint::count());
    }

    public function test_a_delivered_market_order_earns_and_a_cancelled_one_takes_it_back(): void
    {
        $this->on(1);
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler]);
        $order = MarketOrder::create([
            'public_token' => str_repeat('a', 40), 'seller_id' => $seller->id, 'buyer_id' => $this->buyer->id, 'title' => 'Chair', 'unit' => 'piece', 'quantity' => 1, 'unit_price' => 2000,
            'seller_price' => 1960, 'commission' => 40, 'audience' => 'public', 'ship_to' => [], 'delivery_charge' => 100, 'total' => 2100, 'status' => 'shipped',
        ]);

        $order->update(['status' => 'delivered']);
        $this->assertSame(20, $this->points->balance($this->buyer));

        $order->update(['status' => 'returned']);
        $this->assertSame(0, $this->points->balance($this->buyer));
        $this->assertSame([20, -20], RewardPoint::orderBy('id')->pluck('points')->all());
    }

    public function test_a_cancelled_memo_takes_its_points_back_once(): void
    {
        $this->on(2);
        $memo = $this->memo();

        $memo->update(['status' => 'cancelled']);
        $this->assertSame(0, $this->points->balance($this->buyer));
        $memo->update(['note' => 'again']);
        $this->points->reverseInvoice($memo);
        $this->assertSame(0, $this->points->balance($this->buyer));
        $this->assertSame(1, RewardPoint::where('kind', 'reverse')->count());
    }

    public function test_goods_that_come_back_take_back_points_for_that_money_but_never_more_than_the_buyer_has(): void
    {
        $this->on(2);
        $memo = $this->memo(); // 20 points
        $return = SaleReturn::create(['invoice_id' => $memo->id, 'customer_id' => $this->buyer->id, 'kind' => 'paikari', 'resolution' => 'credit', 'reason' => 'damaged', 'status' => 'pending', 'amount' => 500]);

        $return->update(['status' => 'approved']);
        $this->assertSame(10, $this->points->balance($this->buyer));

        $other = SaleReturn::create(['invoice_id' => $memo->id, 'customer_id' => $this->buyer->id, 'kind' => 'paikari', 'resolution' => 'credit', 'reason' => 'damaged', 'status' => 'pending', 'amount' => 5000]);
        $other->update(['status' => 'approved']);
        $this->assertSame(0, $this->points->balance($this->buyer), 'points cannot go below nothing');
    }

    public function test_points_turn_into_a_token_worth_what_the_owner_set(): void
    {
        $this->on(2);
        Setting::write('points.worth', 10);
        $this->memo(['total' => 15050]); // 15,000 of goods = 300 points

        $token = $this->points->convert($this->buyer, 250);

        $this->assertSame(25.0, (float) $token->amount);
        $this->assertSame($this->buyer->id, $token->user_id);
        $this->assertSame(50, $this->points->balance($this->buyer));
        $this->assertSame([300, -250], RewardPoint::orderBy('id')->pluck('points')->all());
        $this->assertSame($token->id, RewardPoint::where('kind', 'spend')->firstOrFail()->token_id);
    }

    public function test_you_cannot_spend_points_you_do_not_have_or_below_the_least(): void
    {
        $this->on(2);
        $this->memo(); // 20 points
        Setting::write('points.min_redeem', 10);

        $this->actingAs($this->buyer)->post('/wallet/points', ['points' => 21])->assertSessionHasErrors('points');
        $this->post('/wallet/points', ['points' => 5])->assertSessionHasErrors('points');
        $this->post('/wallet/points', ['points' => 0])->assertSessionHasErrors('points');
        $this->assertSame(0, AquaToken::count());
        $this->assertSame(20, $this->points->balance($this->buyer));

        $this->post('/wallet/points', ['points' => 10])->assertSessionHasNoErrors();
        $this->assertSame(1, AquaToken::count()); // 10 points at 10 taka per 100 is a 1 taka token
    }

    public function test_the_programme_being_off_stops_turning_points_into_tokens(): void
    {
        $this->on(2);
        $this->memo();
        Setting::write('points.per_100', 0);

        $this->actingAs($this->buyer)->post('/wallet/points', ['points' => 20])->assertSessionHasErrors('points');
        $this->assertSame(0, AquaToken::count());
    }

    public function test_the_wallet_shows_the_balance_and_what_it_can_become(): void
    {
        $this->on(2);
        $this->memo();

        $this->actingAs($this->buyer)->get('/wallet')->assertInertia(fn (Assert $p) => $p->where('points.on', true)->where('points.balance', 20)->where('points.per100', 2)->where('points.history.0.points', 20));

        Setting::write('points.per_100', 0);
        $this->get('/wallet')->assertInertia(fn (Assert $p) => $p->where('points.on', false));
    }

    public function test_a_guest_cannot_use_the_wallet(): void
    {
        $this->post('/wallet/points', ['points' => 5])->assertRedirect('/login');
    }
}
