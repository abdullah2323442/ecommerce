<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Models\AquaToken;
use App\Models\Company;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Medicine;
use App\Models\Sector;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Market\MarketOrders;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use App\Services\Wallet\Tokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 24: the wallet in two parts, the Aqua Token at two levels, and the rules that keep them from piling up. */
class TokensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    private User $buyer;

    private User $seller;

    private User $other;

    private Medicine $napa;

    private Listing $chair;

    private Listing $table;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 10])->id]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber'])->fresh();
        $this->other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Karim Wood'])->fresh();

        // 3 strips of 10 pieces: box 300. The shop pays 270 a box.
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        app(StockService::class)->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
        app(StockService::class)->setMasterSync(true);

        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => true]);
        $make = fn (User $s, string $title) => Listing::create([
            'seller_id' => $s->id, 'sector_id' => $wood->id, 'title' => $title, 'public_price' => 1000, 'wholesale_price' => 800, 'min_qty' => 1, 'delivery_charge' => 100,
            'stock' => 50, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
        $this->chair = $make($this->seller, 'Teak chair');
        $this->table = $make($this->other, 'Teak table');
    }

    private function ship(): array
    {
        return ['name' => 'Buyer', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    private function give(float $amount, ?User $to = null): AquaToken
    {
        return app(Tokens::class)->give($to ?? $this->buyer, $amount, 'For you', null, $this->admin);
    }

    private function shopSale(?AquaToken $token = null, int $boxes = 2, bool $offer = false): Invoice
    {
        $invoices = app(InvoiceService::class);
        $draft = $invoices->open('paikari', $this->staff, $this->shop);
        $invoices->addLine($draft, $this->napa, 'box', $boxes, $this->staff);
        if ($offer) {
            $draft->lines()->first()->update(['special_percent' => 5]);
        }
        if ($token) {
            $draft->update(['aqua_token_id' => $token->id]);
        }

        return $invoices->finalise($draft, $this->staff);
    }

    // ------------------------------------------------------------------ giving

    public function test_only_the_owner_gives_tokens_and_they_show_in_the_wallet(): void
    {
        $this->actingAs($this->staff)->post('/admin/tokens', ['user_id' => $this->buyer->id, 'amount' => 100])->assertRedirect('/');

        $this->actingAs($this->admin)->post('/admin/tokens', ['user_id' => $this->buyer->id, 'amount' => 100, 'note' => 'Thank you'])->assertRedirect('/admin/tokens');

        $this->actingAs($this->buyer)->get('/wallet')->assertInertia(fn (Assert $page) => $page->component('wallet')
            ->where('wallet.everywhere.total', 100)->where('wallet.everywhere.tokens.0.note', 'Thank you'));
    }

    public function test_a_token_must_be_worth_something_and_its_last_day_in_the_future(): void
    {
        $this->actingAs($this->admin)->post('/admin/tokens', ['user_id' => $this->buyer->id, 'amount' => 0])->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post('/admin/tokens', ['user_id' => $this->buyer->id, 'amount' => 50, 'expires_at' => '2026-10-01'])->assertSessionHasErrors('expires_at');
        $this->assertSame(0, AquaToken::count());
    }

    public function test_a_guest_has_no_wallet(): void
    {
        $this->get('/wallet')->assertRedirect();
    }

    public function test_an_old_token_is_not_counted_and_one_taken_back_is_gone(): void
    {
        $old = $this->give(100);
        $old->update(['expires_at' => now()->subDay()]);
        $gone = $this->give(60);
        $this->actingAs($this->admin)->post("/admin/tokens/{$gone->id}/take-back")->assertSessionHasNoErrors();
        $this->give(30);

        $this->assertSame(30.0, app(Tokens::class)->wallet($this->buyer)['everywhere']['total']);
    }

    public function test_a_used_token_cannot_be_taken_back(): void
    {
        $token = $this->give(100, $this->shop);
        $this->shopSale($token);

        $this->actingAs($this->admin)->post("/admin/tokens/{$token->id}/take-back")->assertSessionHasErrors('token');
    }

    // ------------------------------------------------------------------ the wallet's two parts

    public function test_return_credit_stays_in_its_own_sector_part_of_the_wallet(): void
    {
        $this->give(100);
        CreditEntry::create(['user_id' => $this->buyer->id, 'sector' => 'pharmacy', 'amount' => 250, 'reason' => 'return']);

        $wallet = app(Tokens::class)->wallet($this->buyer);

        $this->assertSame(100.0, $wallet['everywhere']['total'], 'credit is not mixed into what works everywhere');
        $this->assertSame([['sector' => 'pharmacy', 'balance' => 250.0]], $wallet['sector']);
    }

    // ------------------------------------------------------------------ on a shop order

    public function test_a_chosen_token_comes_off_the_memo_and_is_used_once(): void
    {
        $token = $this->give(100, $this->shop);

        $sale = $this->shopSale($token); // 540 less the 100 token

        $this->assertSame('100.00', (string) $sale->token_applied);
        $this->assertSame('440.00', (string) $sale->total);
        $this->assertNotNull($token->fresh()->spent_at);

        $next = $this->shopSale(); // nothing chosen, so nothing off
        $this->assertSame('0.00', (string) $next->token_applied);
    }

    public function test_the_token_comes_off_first_then_the_return_credit_on_a_separate_line(): void
    {
        $token = $this->give(100, $this->shop);
        CreditEntry::create(['user_id' => $this->shop->id, 'sector' => 'pharmacy', 'amount' => 600, 'reason' => 'return']);

        $sale = $this->shopSale($token); // 540: the token 100, then 440 of the credit

        $this->assertSame('100.00', (string) $sale->token_applied);
        $this->assertSame('440.00', (string) $sale->credit_applied);
        $this->assertSame('0.00', (string) $sale->total);
        $this->assertSame(160.0, round((float) CreditEntry::where('user_id', $this->shop->id)->sum('amount'), 2), 'the rest of the credit is still there');
    }

    public function test_a_token_never_goes_with_an_offer_price(): void
    {
        $token = $this->give(100, $this->shop);

        $sale = $this->shopSale($token, 2, true);

        $this->assertSame('0.00', (string) $sale->token_applied);
        $this->assertNull($token->fresh()->spent_at, 'the token is not wasted');
    }

    public function test_a_token_is_never_more_than_the_memo(): void
    {
        $token = $this->give(1000, $this->shop);

        $sale = $this->shopSale($token, 1); // 270

        $this->assertSame('270.00', (string) $sale->token_applied);
        $this->assertSame('0.00', (string) $sale->total);
    }

    public function test_someone_elses_token_cannot_be_spent_on_my_memo(): void
    {
        $theirs = $this->give(100, $this->buyer);

        $sale = $this->shopSale($theirs);

        $this->assertSame('0.00', (string) $sale->token_applied);
        $this->assertNull($theirs->fresh()->spent_at);
    }

    public function test_a_corrected_memo_gives_the_token_back(): void
    {
        $token = $this->give(100, $this->shop);
        $sale = $this->shopSale($token);

        app(InvoiceService::class)->correct($sale, $this->admin);

        $this->assertNull($token->fresh()->spent_at);
    }

    // ------------------------------------------------------------------ on the marketplace

    public function test_our_token_comes_out_of_our_commission_not_the_sellers_pay(): void
    {
        $plain = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1);
        $token = $this->give(40);

        $order = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);

        $this->assertSame('platform', $order->token_kind);
        $this->assertSame('40.00', (string) $order->token_amount);
        $this->assertEqualsWithDelta((float) $plain->total - 40, (float) $order->total, 0.001);
        $this->assertEqualsWithDelta((float) $plain->commission - 40, (float) $order->commission, 0.001);
        $this->assertEqualsWithDelta($plain->sellerGets(), $order->sellerGets(), 0.001, 'the seller is paid the same');
        $this->assertNotNull($token->fresh()->spent_at);
    }

    public function test_our_token_is_never_more_than_our_commission(): void
    {
        $plain = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1);
        $this->give(5000);

        $order = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);

        $this->assertEqualsWithDelta((float) $plain->commission, (float) $order->token_amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $order->commission, 0.001);
    }

    public function test_a_sellers_token_is_paid_by_the_seller_and_only_works_on_their_products(): void
    {
        app(Tokens::class)->saveSellerToken($this->seller, 150, 0, 1, true, null);

        $mine = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);
        $theirs = app(MarketOrders::class)->place($this->table, $this->buyer, $this->ship(), 1, null, true);

        $this->assertSame('seller', $mine->token_kind);
        $this->assertSame('150.00', (string) $mine->token_amount);
        $plainSeller = (float) $mine->quantity * (float) $mine->seller_price + (float) $mine->delivery_charge;
        $this->assertEqualsWithDelta($plainSeller - 150, $mine->sellerGets(), 0.001, 'the seller pays for it');
        $this->assertNull($theirs->token_kind, 'another seller\'s product gets nothing');
        $this->assertSame('0.00', (string) $theirs->token_amount);
    }

    public function test_the_larger_token_applies_and_they_are_not_added(): void
    {
        app(Tokens::class)->saveSellerToken($this->seller, 150, 0, 1, true, null);
        $platform = $this->give(100);

        $order = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);

        $this->assertSame('seller', $order->token_kind);
        $this->assertSame('150.00', (string) $order->token_amount);
        $this->assertNull($platform->fresh()->spent_at, 'the smaller one is kept for later');
    }

    public function test_one_buyer_can_use_a_sellers_token_only_as_often_as_it_allows(): void
    {
        app(Tokens::class)->saveSellerToken($this->seller, 150, 0, 1, true, null);

        app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);
        $again = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);

        $this->assertNull($again->token_kind);

        $someoneElse = User::factory()->create(['account_type' => AccountType::Public]);
        $this->assertSame('seller', app(MarketOrders::class)->place($this->chair, $someoneElse, $this->ship(), 1, null, true)->token_kind, 'the same token for every buyer');
    }

    public function test_a_sellers_token_needs_the_smallest_order_and_stops_when_switched_off_or_ended(): void
    {
        $tokens = app(Tokens::class);
        $tokens->saveSellerToken($this->seller, 150, 5000, 1, true, null);
        $this->assertNull(app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true)->token_kind, 'the order is too small');

        $tokens->saveSellerToken($this->seller, 150, 0, 5, false, null);
        $this->assertNull(app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true)->token_kind, 'switched off');

        $tokens->saveSellerToken($this->seller, 150, 0, 5, true, Carbon::now()->addDay());
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->assertNull(app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true)->token_kind, 'ended');
    }

    public function test_a_guest_gets_no_token(): void
    {
        app(Tokens::class)->saveSellerToken($this->seller, 150, 0, 1, true, null);

        $order = app(MarketOrders::class)->place($this->chair, null, $this->ship(), 1, null, true);

        $this->assertNull($order->token_kind);
    }

    public function test_the_buyer_has_to_ask_for_the_token(): void
    {
        $this->give(40);

        $order = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1);

        $this->assertNull($order->token_kind);
    }

    public function test_a_cancelled_order_gives_both_kinds_of_token_back(): void
    {
        $platform = $this->give(40);
        $first = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);
        $this->assertNotNull($platform->fresh()->spent_at);
        app(MarketOrders::class)->cancel($first, 'buyer');
        $this->assertNull($platform->fresh()->spent_at);

        $tokens = app(Tokens::class);
        $tokens->saveSellerToken($this->seller, 150, 0, 1, true, null);
        $second = app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true);
        app(MarketOrders::class)->cancel($second, 'seller');
        $this->assertSame('seller', app(MarketOrders::class)->place($this->chair, $this->buyer, $this->ship(), 1, null, true)->token_kind, 'the use was given back');
    }

    public function test_the_buyers_order_page_shows_the_token_and_the_listing_page_offers_it(): void
    {
        $this->give(40);

        $this->actingAs($this->buyer)->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $page) => $page->where('token', 40));

        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/order", $this->ship() + ['quantity' => 1, 'use_token' => 1])->assertSessionHasNoErrors();
        $order = MarketOrder::latest('id')->first();
        $this->actingAs($this->buyer)->get("/market/orders/{$order->public_token}")->assertInertia(fn (Assert $page) => $page->where('order.token_off', 40));
    }

    public function test_a_seller_saves_their_token_and_sees_it(): void
    {
        $this->actingAs($this->seller)->put('/wholesale/sell-token', ['amount' => 120, 'min_order' => 1000, 'per_buyer' => 2, 'active' => 1])->assertSessionHasNoErrors();

        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $page) => $page->where('token.amount', 120)->where('token.per_buyer', 2));

        $this->actingAs($this->seller)->put('/wholesale/sell-token', ['amount' => 0, 'per_buyer' => 1, 'active' => 1])->assertSessionHasErrors('amount');
        $this->actingAs($this->buyer)->put('/wholesale/sell-token', ['amount' => 50, 'per_buyer' => 1, 'active' => 1])->assertRedirect('/');
    }

    // ------------------------------------------------------------------ on the public checkout

    public function test_the_checkout_offers_the_best_token(): void
    {
        $this->give(40);
        $this->give(90);
        $this->actingAs($this->buyer)->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);

        $this->actingAs($this->buyer)->get('/checkout')->assertInertia(fn (Assert $page) => $page->where('token.amount', 90));
    }

    public function test_a_visitor_sees_no_token_at_checkout(): void
    {
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);

        $this->get('/checkout')->assertInertia(fn (Assert $page) => $page->where('token', null));
    }

    public function test_an_online_order_with_a_token_loses_it_when_cancelled(): void
    {
        $token = $this->give(90);
        $this->actingAs($this->buyer)->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
        $this->actingAs($this->buyer)->post('/checkout', $this->ship() + ['delivery_mode' => 'delivery', 'use_token' => 1])->assertSessionHasNoErrors();

        $order = Invoice::latest('id')->first();
        $this->assertSame($token->id, $order->aqua_token_id);
        $this->assertNull($token->fresh()->spent_at, 'only spent when the order is finished');

        try {
            app(InvoiceService::class)->cancel($order, null);
        } catch (StockException) {
        }
        $this->assertNull($token->fresh()->spent_at);
    }
}
