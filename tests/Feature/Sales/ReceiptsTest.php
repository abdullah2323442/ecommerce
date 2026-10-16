<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\CustomMaterial;
use App\Models\Listing;
use App\Models\Sector;
use App\Models\User;
use App\Services\Custom\CustomOrders;
use App\Services\Food\FoodOrders;
use App\Services\Food\Restaurants;
use App\Services\Gaming\GameTopUp;
use App\Services\Market\MarketOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 22: a clean receipt in every sector, for whoever holds the order's own link, with nothing in it that is nobody's business. */
class ReceiptsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Carbon::setTestNow('2026-10-15 04:00:00');
        $this->post('/locale', ['locale' => 'en']);   // the receipts are read in English here
        config(['brand.name' => 'Siam Test', 'brand.address' => 'Shop 4, Mirpur', 'brand.phone' => '01700000000']);
    }

    private function marketOrder(bool $nameShown = false)
    {
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece']);
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'show_shop_name' => $nameShown])->fresh();
        $chair = Listing::create(['seller_id' => $seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'min_qty' => 1, 'delivery_charge' => 120, 'stock' => 5, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now()]);

        return app(MarketOrders::class)->place($chair, null, ['name' => 'Bibi', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'], 2);
    }

    private function receipt(string $kind, string $token)
    {
        return $this->get("/receipt/{$kind}/{$token}");
    }

    public function test_a_market_receipt_has_every_step_on_its_own_line(): void
    {
        $o = $this->marketOrder();

        $this->receipt('market', $o->public_token)->assertOk()->assertInertia(fn (Assert $p) => $p->component('receipt')
            ->where('receipt.number', $o->number())->where('receipt.business.name', 'Siam Test')->where('receipt.lines.0.total', 2200)
            ->where('receipt.steps.0.label', fn ($v) => $v === __('Goods'))->where('receipt.steps.1.amount', 120)->where('receipt.total', 2320)
            ->where('receipt.buyer.name', 'Bibi')->where('receipt.payment.status', fn ($v) => $v === __('To pay')));
    }

    public function test_a_sellers_name_is_on_the_receipt_only_where_the_owner_shows_it(): void
    {
        $hidden = $this->marketOrder(false);
        $this->receipt('market', $hidden->public_token)->assertInertia(fn (Assert $p) => $p->where('receipt.sold_by', 'Siam Test'));
        $this->assertStringNotContainsString('Rahim', json_encode($this->receipt('market', $hidden->public_token)->viewData('page')['props']['receipt']));
    }

    public function test_the_market_receipt_carries_no_commission_and_no_seller_price(): void
    {
        $o = $this->marketOrder();

        $json = json_encode($this->receipt('market', $o->public_token)->viewData('page')['props']['receipt']);

        foreach (['commission', 'seller_price', 'seller'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_a_game_receipt_shows_the_player_and_the_top_up(): void
    {
        $gaming = app(GameTopUp::class);
        $game = $gaming->saveGame(['name' => 'Free Fire', 'id_label' => 'Player ID']);
        $pack = $gaming->savePackage($game, ['name' => '100 Diamonds', 'price' => 95]);
        $order = $gaming->place(null, $pack->load('game'), '123456789', null, '01712345678', true);

        $this->receipt('game', $order->public_token)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('receipt.title', fn ($v) => $v === __('Top-up receipt'))->where('receipt.buyer.player', '123456789')->where('receipt.total', 95)->where('receipt.lines.0.name', 'Free Fire · 100 Diamonds'));
    }

    public function test_a_food_receipt_is_in_the_restaurants_name_with_ours_small(): void
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $restaurants = app(Restaurants::class);
        $shop = $restaurants->open(['name' => 'Burger Barn', 'owner_name' => 'Rafi', 'phone' => '01811111111', 'password' => 'long-password-1', 'area' => 'Mirpur', 'areas' => ['Pallabi'], 'commission_kind' => 'percent', 'commission_value' => 10], $admin);
        $shop->update(['opens_at' => '09:00', 'closes_at' => '23:00']);
        $burger = $restaurants->saveItem($shop, ['name' => 'Beef burger', 'price' => 250, 'cook_minutes' => 15]);
        $order = app(FoodOrders::class)->place($shop, null, [['item_id' => $burger->id, 'quantity' => 2]], 'delivery', ['name' => 'Bibi', 'phone' => '01712345678', 'area' => 'Mirpur', 'address' => 'House 4, Road 2']);

        $this->receipt('food', $order->public_token)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('receipt.business.name', 'Burger Barn')->where('receipt.powered_by', 'Siam Test')->where('receipt.lines.0.name', 'Beef burger')->where('receipt.lines.0.total', 500));
        $this->assertStringNotContainsString('commission', json_encode($this->receipt('food', $order->public_token)->viewData('page')['props']['receipt']));
    }

    public function test_a_made_to_order_receipt_says_returns_do_not_apply_and_shows_no_wage(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Bibi', 'phone' => '01712345678']);
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $teak = CustomMaterial::create(['name' => 'Teak', 'kind' => 'wood', 'rate' => 3000]);
        $svc = app(CustomOrders::class);
        $o = $svc->place($buyer, ['kind' => 'wood', 'material_id' => $teak->id, 'items' => [['values' => ['length' => 72], 'unit' => 'inch']]]);
        $svc->price($o, $staff, ['material_cost' => 4000, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10]);

        $res = $this->receipt('custom', $o->public_token)->assertOk();
        $json = json_encode($res->viewData('page')['props']['receipt']);

        $this->assertStringContainsString(trim(json_encode(__('Returns do not apply to a job made to your own measurements.')), '"'), $json);
        foreach (['wage', '1500', 'craftsman', 'material_cost'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }

    public function test_a_wrong_link_or_kind_gets_nothing(): void
    {
        $o = $this->marketOrder();

        $this->receipt('market', str_repeat('z', 40))->assertRedirect('/');
        $this->receipt('game', $o->public_token)->assertRedirect('/');
        $this->get("/receipt/medicine/{$o->public_token}")->assertRedirect('/');
        $this->get('/receipt/market/short')->assertRedirect('/');
    }

    public function test_a_receipt_is_for_the_holder_of_the_link_and_not_for_search_engines(): void
    {
        $o = $this->marketOrder();

        $this->receipt('market', $o->public_token)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringNotContainsString('/receipt/', $this->get('/sitemap-pages.xml')->getContent());
    }
}
