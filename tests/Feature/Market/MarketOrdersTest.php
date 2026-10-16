<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\MarketOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14: orders go through us, the seller ships and is paid by the buyer, we count commission only on complete sales. */
class MarketOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $reviewer;

    private Sector $wood;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'name' => 'Rahim Uddin'])->fresh();
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => true]);
        $this->chair = Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'wholesale_price' => 800, 'min_qty' => 10, 'delivery_charge' => 100,
            'stock' => 50, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function details(array $over = []): array
    {
        return array_merge(['quantity' => 2, 'name' => 'Hasan Ali', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5, Road 3'], $over);
    }

    private function order(array $over = [], ?User $as = null): MarketOrder
    {
        $as && $this->actingAs($as);
        $this->post("/market/{$this->chair->id}/order", $this->details($over))->assertSessionHasNoErrors();

        return MarketOrder::latest('id')->firstOrFail();
    }

    private function buyer(): User
    {
        return User::factory()->create(['account_type' => AccountType::Public])->fresh();
    }

    // ------------------------------------------------------------------ ordering

    public function test_a_guest_orders_and_the_price_is_the_seller_price_plus_our_commission(): void
    {
        $order = $this->order();

        $this->assertSame(['placed', 'public', '1020.00', '1000.00', '40.00', '100.00', '2140.00'], [$order->status, $order->audience, (string) $order->unit_price, (string) $order->seller_price, (string) $order->commission, (string) $order->delivery_charge, (string) $order->total]);
        $this->assertNull($order->buyer_id);
        $this->assertSame(['Teak chair', 'foot'], [$order->title, $order->unit]);
        $this->assertSame('48.00', (string) $this->chair->fresh()->stock);
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'market_order_new')->count());
    }

    public function test_the_buyer_follows_it_from_a_private_link_and_sees_our_name_not_the_sellers(): void
    {
        $order = $this->order();

        $page = $this->get("/market/orders/{$order->public_token}");
        $page->assertOk()->assertInertia(fn (Assert $p) => $p->where('order.status', 'placed')->where('order.total', 2140)->where('order.can_cancel', true)->where('order.seller', config('brand.name')));
        $this->assertStringNotContainsString('Rahim', json_encode($page->viewData('page')['props']['order']));

        $this->get('/market/orders/'.str_repeat('z', 40))->assertRedirect('/');   // an unknown link: quietly home
    }

    public function test_a_trade_buyer_pays_the_trade_price_and_must_order_the_minimum(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']])->fresh();
        $this->actingAs($buyer);

        $this->post("/market/{$this->chair->id}/order", $this->details(['quantity' => 5]))->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());

        $order = $this->order(['quantity' => 10]);
        $this->assertSame(['wholesale', '816.00', '800.00', '160.00', '8260.00'], [$order->audience, (string) $order->unit_price, (string) $order->seller_price, (string) $order->commission, (string) $order->total]);
        $this->assertSame($buyer->id, $order->buyer_id);
    }

    public function test_a_buyer_may_not_buy_their_own_product(): void
    {
        $this->seller->forceFill(['categories' => ['wood']])->save();

        $this->actingAs($this->seller)->post("/market/{$this->chair->id}/order", $this->details())->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());
        $this->assertSame('50.00', (string) $this->chair->fresh()->stock);
    }

    public function test_a_seller_on_holiday_takes_no_new_orders_but_the_ones_made_carry_on(): void
    {
        $order = $this->order();
        $this->seller->forceFill(['seller_paused' => true])->save();

        $this->post("/market/{$this->chair->id}/order", $this->details())->assertSessionHasErrors('order');
        $this->assertSame(1, MarketOrder::count());

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/accept")->assertSessionHasNoErrors();
        $this->assertSame('accepted', $order->fresh()->status);
    }

    public function test_quantity_rules_follow_the_sector_and_the_stock(): void
    {
        $this->post("/market/{$this->chair->id}/order", $this->details(['quantity' => 0]))->assertSessionHasErrors('quantity');
        $this->post("/market/{$this->chair->id}/order", $this->details(['quantity' => 51]))->assertSessionHasErrors('order');   // more than the seller has
        $this->post("/market/{$this->chair->id}/order", $this->details(['quantity' => 2.5]))->assertSessionHasNoErrors();           // wood is sold by the foot, fractions fine

        $whole = Sector::create(['slug' => 'clothing', 'name' => 'Clothing', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->seller->forceFill(['categories' => ['wood', 'clothing']])->save();
        $shirt = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $whole->id, 'title' => 'Shirt', 'public_price' => 500, 'status' => 'published', 'commission_kind' => 'taka', 'commission_value' => 0, 'approved_at' => now()]);
        $this->post("/market/{$shirt->id}/order", $this->details(['quantity' => 1.5]))->assertSessionHasErrors('order');
        $this->post("/market/{$shirt->id}/order", $this->details(['quantity' => 3]))->assertSessionHasNoErrors();
    }

    public function test_something_not_for_this_buyer_cannot_be_ordered_and_a_closed_sale_cannot_either(): void
    {
        $tradeOnly = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => 'Bulk beams', 'wholesale_price' => 300, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 1, 'approved_at' => now()]);

        $this->post("/market/{$tradeOnly->id}/order", $this->details())->assertRedirect('/');
        $this->chair->update(['status' => 'paused']);
        $this->post("/market/{$this->chair->id}/order", $this->details())->assertRedirect('/');
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_the_buyers_details_are_checked(): void
    {
        $this->post("/market/{$this->chair->id}/order", $this->details(['phone' => '99']))->assertSessionHasErrors('phone');
        $this->post("/market/{$this->chair->id}/order", $this->details(['district' => 'Nowhere']))->assertSessionHasErrors('district');
        $this->post("/market/{$this->chair->id}/order", $this->details(['address' => '']))->assertSessionHasErrors('address');
        $this->post("/market/{$this->chair->id}/order", $this->details(['phone' => '+880 1711-000000']))->assertSessionHasNoErrors();
        $this->assertSame('01711000000', MarketOrder::firstOrFail()->ship_to['phone']);
    }

    public function test_staff_and_the_owner_cannot_order(): void
    {
        $this->actingAs($this->reviewer)->post("/market/{$this->chair->id}/order", $this->details())->assertRedirect('/');
        $this->assertSame(0, MarketOrder::count());
    }

    // ------------------------------------------------------------------ the seller sees no name and fading numbers

    public function test_the_seller_never_sees_the_buyers_name_and_the_numbers_are_open_only_while_it_is_on_its_way(): void
    {
        $order = $this->order();
        $orders = app(MarketOrders::class);

        $open = $orders->forSeller($order);
        $this->assertSame('01711000000', $open['phone']);
        $this->assertStringContainsString('House 5, Road 3', $open['address']);
        $this->assertStringNotContainsString('Hasan', json_encode($open));

        foreach (['delivered', 'cancelled', 'not_received'] as $closed) {
            $order->update(['status' => $closed]);
            $view = $orders->forSeller($order->fresh());
            $this->assertSame(['***', '***'], [$view['phone'], $view['address']], $closed);
            $this->assertStringNotContainsString('Hasan', json_encode($view));
            $this->assertStringNotContainsString('01711000000', json_encode($view));
        }
    }

    public function test_what_the_seller_is_paid_never_shows_our_commission(): void
    {
        $order = $this->order();

        $view = app(MarketOrders::class)->forSeller($order);
        $this->assertSame(2100.0, $view['you_get']);   // 2 x 1000 and the delivery charge they set
        foreach (['commission', 'unit_price', '1020', '2140'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($view), "leaked {$secret}");
        }
    }

    public function test_the_seller_page_lists_their_orders_marks_them_seen_and_the_menu_counts_the_unseen(): void
    {
        $this->order();
        $this->order(['quantity' => 3]);

        $this->actingAs($this->seller)->get('/wholesale/sell/orders')->assertOk()->assertInertia(fn (Assert $p) => $p->has('orders', 2)->where('orders.0.unseen', true)->where('auth.sellerOrders', 2));
        $this->get('/wholesale/sell/orders')->assertInertia(fn (Assert $p) => $p->where('orders.0.unseen', false)->where('auth.sellerOrders', 0));
    }

    public function test_only_the_seller_of_an_order_can_answer_it(): void
    {
        $order = $this->order();
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($other)->post("/wholesale/sell/orders/{$order->id}/accept")->assertSessionHasErrors('order');
        $this->post("/wholesale/sell/orders/{$order->id}/cancel", ['reason' => 'Not mine'])->assertRedirect('/');
        $this->assertSame('placed', $order->fresh()->status);

        $this->actingAs($this->buyer())->post("/wholesale/sell/orders/{$order->id}/accept")->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->get('/wholesale/sell/orders')->assertRedirect('/');
    }

    // ------------------------------------------------------------------ the journey

    public function test_accept_send_receive_and_the_commission_is_ours_and_the_buyer_hears_from_us(): void
    {
        $buyer = $this->buyer();
        $order = $this->order([], $buyer);

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/accept")->assertSessionHasNoErrors();
        $this->post("/wholesale/sell/orders/{$order->id}/ship", ['tracking' => 'Pathao 12345'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(['shipped', 'Pathao 12345'], [$order->status, $order->tracking]);
        $this->assertSame(['market_order_accepted', 'market_order_sent'], Notice::where('user_id', $buyer->id)->orderBy('id')->pluck('kind')->all());
        $this->assertStringContainsString("/market/orders/{$order->public_token}", json_decode(Notice::where('user_id', $buyer->id)->firstOrFail()->body, true)['link']);
        $this->assertSame(0, app(MarketOrders::class)->owed()->count());   // not complete yet

        auth()->logout();
        $this->post("/market/orders/{$order->public_token}/received")->assertSessionHasNoErrors();

        $this->assertSame('delivered', $order->fresh()->status);
        $owed = app(MarketOrders::class)->owed();
        $this->assertSame([40.0, 2000.0, 1], [$owed[0]['commission'], $owed[0]['sales'], $owed[0]['orders']]);
    }

    public function test_it_can_be_sent_straight_away_and_a_sent_order_cannot_be_accepted_again(): void
    {
        $order = $this->order();

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/ship")->assertSessionHasNoErrors();
        $this->assertNotNull($order->fresh()->accepted_at);
        $this->post("/wholesale/sell/orders/{$order->id}/accept")->assertSessionHasErrors('order');
        $this->post("/wholesale/sell/orders/{$order->id}/ship")->assertSessionHasErrors('order');
    }

    public function test_not_received_is_not_a_complete_sale_and_so_no_commission(): void
    {
        $order = $this->order();
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/ship");

        auth()->logout();
        $this->post("/market/orders/{$order->public_token}/not-received")->assertSessionHasNoErrors();
        $this->assertSame('not_received', $order->fresh()->status);
        $this->assertSame(0, app(MarketOrders::class)->owed()->count());

        $this->post("/market/orders/{$order->public_token}/received")->assertSessionHasErrors('order');   // the buyer's word stands
        Carbon::setTestNow(now()->addDays(30));
        $this->assertSame(0, app(MarketOrders::class)->autoConfirm());
    }

    public function test_the_buyer_cancels_before_it_is_sent_and_what_was_held_goes_back(): void
    {
        $order = $this->order(['quantity' => 5]);
        $this->assertSame('45.00', (string) $this->chair->fresh()->stock);

        $this->post("/market/orders/{$order->public_token}/cancel", ['reason' => 'Changed my mind'])->assertSessionHasNoErrors();

        $this->assertSame(['cancelled', 'buyer'], [$order->fresh()->status, $order->fresh()->cancelled_by]);
        $this->assertSame('50.00', (string) $this->chair->fresh()->stock);
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'market_order_cancelled')->count());
        $this->assertSame(0, app(MarketOrders::class)->owed()->count());   // a cancelled order has no commission
    }

    public function test_a_sent_order_cannot_be_cancelled_by_either_side(): void
    {
        $order = $this->order();
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/ship");

        $this->post("/wholesale/sell/orders/{$order->id}/cancel", ['reason' => 'Out of wood'])->assertSessionHasErrors('order');
        auth()->logout();
        $this->post("/market/orders/{$order->public_token}/cancel")->assertSessionHasErrors('order');
        $this->assertSame('shipped', $order->fresh()->status);
    }

    public function test_the_seller_cancels_with_a_reason_and_the_buyer_is_told(): void
    {
        $buyer = $this->buyer();
        $order = $this->order([], $buyer);

        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/cancel", [])->assertSessionHasErrors('reason');
        $this->post("/wholesale/sell/orders/{$order->id}/cancel", ['reason' => 'Out of wood'])->assertSessionHasNoErrors();

        $this->assertSame(['cancelled', 'seller', 'Out of wood'], [$order->fresh()->status, $order->fresh()->cancelled_by, $order->fresh()->cancel_reason]);
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'market_order_cancelled')->count());
    }

    public function test_a_sent_order_nobody_answers_is_taken_as_received_after_a_week(): void
    {
        $order = $this->order();
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/ship");

        Carbon::setTestNow(now()->addDays(6));
        $this->assertSame(0, app(MarketOrders::class)->autoConfirm());
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(1, app(MarketOrders::class)->autoConfirm());
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_an_order_that_was_not_sent_cannot_be_confirmed_or_reported(): void
    {
        $order = $this->order();

        $this->post("/market/orders/{$order->public_token}/received")->assertSessionHasErrors('order');
        $this->post("/market/orders/{$order->public_token}/not-received")->assertSessionHasErrors('order');
        $this->assertSame('placed', $order->fresh()->status);
    }

    public function test_a_signed_in_buyer_sees_their_orders_and_nobody_elses(): void
    {
        $mine = $this->buyer();
        $this->order([], $mine);
        $other = $this->buyer();
        $this->order(['quantity' => 3], $other);

        $this->actingAs($mine)->get('/market-orders')->assertInertia(fn (Assert $p) => $p->has('orders', 1)->where('orders.0.title', 'Teak chair'));
        auth()->logout();
        $this->get('/market-orders')->assertRedirect();
    }

    // ------------------------------------------------------------------ what we are owed

    public function test_we_see_who_owes_how_much_and_how_many_of_each_product_sold_for_a_month(): void
    {
        $other = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => 'Teak table', 'public_price' => 2000, 'status' => 'published', 'commission_kind' => 'taka', 'commission_value' => 100, 'approved_at' => now()]);

        foreach ([[$this->chair, 2], [$this->chair, 3], [$other, 1]] as [$listing, $qty]) {
            $this->post("/market/{$listing->id}/order", $this->details(['quantity' => $qty]));
        }
        foreach (MarketOrder::all() as $o) {
            $o->update(['status' => 'delivered', 'delivered_at' => now()]);
        }
        MarketOrder::where('title', 'Teak table')->update(['delivered_at' => now()->subMonth()]);   // last month's

        $this->actingAs($this->reviewer)->get('/admin/market/commissions?month=2026-10')->assertInertia(fn (Assert $p) => $p->has('rows', 1)
            ->where('rows.0.seller', 'Rahim Timber')->where('rows.0.orders', 2)->where('rows.0.commission', 100)->where('rows.0.sales', 5000)
            ->where('rows.0.products.0', ['title' => 'Teak chair', 'sold' => 5, 'commission' => 100]));

        $this->get('/admin/market/commissions?month=2026-09')->assertInertia(fn (Assert $p) => $p->where('rows.0.commission', 100)->where('rows.0.products.0.title', 'Teak table'));
    }

    public function test_the_commissions_page_lists_orders_reported_not_received_and_is_for_staff_who_manage_customers(): void
    {
        $order = $this->order();
        $this->actingAs($this->seller)->post("/wholesale/sell/orders/{$order->id}/ship");
        auth()->logout();
        $this->post("/market/orders/{$order->public_token}/not-received");

        $this->actingAs($this->reviewer)->get('/admin/market/commissions')->assertInertia(fn (Assert $p) => $p->has('problems', 1)->where('problems.0.seller', 'Rahim Timber'));

        $counterOnly = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);
        $this->actingAs($counterOnly)->get('/admin/market/commissions')->assertRedirect('/');
    }

    public function test_a_listing_with_orders_on_the_way_cannot_be_deleted_only_paused(): void
    {
        $order = $this->order();
        $this->actingAs($this->seller);

        $this->delete("/wholesale/sell/{$this->chair->id}")->assertSessionHasErrors('listing');
        $this->assertNotNull($this->chair->fresh());
        $this->put("/wholesale/sell/{$this->chair->id}/pause", ['paused' => true])->assertSessionHasNoErrors();

        // Once the order is done the listing can go, and the order keeps its record.
        $order->update(['status' => 'delivered']);
        $this->delete("/wholesale/sell/{$this->chair->id}")->assertSessionHasNoErrors();
        $this->assertSame('Teak chair', $order->fresh()->title);
        $this->assertNull($order->fresh()->listing_id);
    }
}
