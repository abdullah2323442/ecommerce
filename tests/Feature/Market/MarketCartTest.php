<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Sector;
use App\Models\User;
use App\Services\Wallet\Tokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 13: one cart, one checkout, the order split by seller, each seller packs and ships separately with their own delivery charge. */
class MarketCartTest extends TestCase
{
    use RefreshDatabase;

    private User $rahim;

    private User $karim;

    private Listing $chair;

    private Listing $table;

    private Listing $shirt;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->rahim = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood', 'clothing'], 'business_name' => 'Rahim Timber', 'seller_trusted_at' => now()]);
        $this->karim = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['clothing'], 'business_name' => 'Karim Wear', 'seller_trusted_at' => now()]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => false]);
        $clothing = Sector::create(['slug' => 'clothing', 'name' => 'Clothing', 'unit' => 'piece', 'allow_fraction' => false]);
        $make = fn (User $u, Sector $s, string $t, int $price, int $delivery, ?int $stock = 10) => Listing::create([
            'seller_id' => $u->id, 'sector_id' => $s->id, 'title' => $t, 'public_price' => $price, 'delivery_charge' => $delivery, 'stock' => $stock,
            'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(),
        ]);
        $this->chair = $make($this->rahim, $wood, 'Teak chair', 1000, 100);
        $this->table = $make($this->rahim, $wood, 'Teak table', 2000, 150);
        $this->shirt = $make($this->karim, $clothing, 'Cotton shirt', 500, 60);
    }

    private function add(Listing $l, int $q = 1)
    {
        return $this->post('/market-cart', ['listing_id' => $l->id, 'quantity' => $q]);
    }

    private function ship(array $more = []): array
    {
        return $more + ['name' => 'Hasan', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    public function test_the_cart_holds_products_from_different_sellers_and_splits_them_into_packages(): void
    {
        $this->add($this->chair, 2)->assertSessionHasNoErrors();
        $this->add($this->shirt, 1);
        $this->add($this->table, 1);

        $this->get('/market-cart')->assertInertia(fn ($p) => $p
            ->has('cart.packages', 2)
            ->where('cart.packages.0.seller', config('brand.name'))   // the seller's own name stays hidden unless the owner shows it
            ->has('cart.packages.0.lines', 2)->where('cart.packages.0.delivery_charge', 150)   // the dearest of their products
            ->has('cart.packages.1.lines', 1)->where('cart.packages.1.delivery_charge', 60)
            ->where('cart.total', 2 * 1020 + 2040 + 150 + 510 + 60));
    }

    public function test_one_checkout_makes_an_order_for_each_product_and_groups_them(): void
    {
        $this->add($this->chair, 2);
        $this->add($this->shirt, 3);

        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasNoErrors();

        $orders = MarketOrder::orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertCount(1, $orders->pluck('group_token')->unique());
        $this->assertSame([$this->rahim->id, $this->karim->id], $orders->pluck('seller_id')->all());
        $this->assertSame('8.00', (string) $this->chair->fresh()->stock);
        $this->assertSame('7.00', (string) $this->shirt->fresh()->stock);
        $this->assertSame(0, count(session('market_cart', [])), 'the cart is emptied');

        $this->get("/market/packages/{$orders[0]->group_token}")->assertInertia(fn ($p) => $p->has('packages', 2)->where('packages.1.n', 2));
    }

    public function test_if_one_line_cannot_be_filled_nothing_is_ordered(): void
    {
        $this->add($this->chair, 2);
        $this->add($this->shirt, 3);
        $this->shirt->update(['stock' => 1]);

        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasErrors('order');

        $this->assertSame(0, MarketOrder::count());
        $this->assertSame('10.00', (string) $this->chair->fresh()->stock, 'the first one was not left half-done');
        $this->assertCount(2, session('market_cart'), 'the cart is kept so the buyer can fix it');
    }

    public function test_a_product_one_may_not_see_cannot_be_added_and_a_vanished_one_drops_out(): void
    {
        $this->add($this->chair);
        $this->shirt->update(['status' => 'draft']);
        $this->add($this->shirt)->assertRedirect('/');

        $this->chair->update(['status' => 'draft']);
        $this->get('/market-cart')->assertInertia(fn ($p) => $p->has('cart.packages', 0));
    }

    public function test_changing_and_removing_lines_and_the_sellers_own_product(): void
    {
        $this->add($this->chair);
        $this->put('/market-cart/'.$this->chair->id.':0', ['quantity' => 4]);
        $this->get('/market-cart')->assertInertia(fn ($p) => $p->where('cart.packages.0.lines.0.quantity', 4));

        $this->delete('/market-cart/'.$this->chair->id.':0');
        $this->get('/market-cart')->assertInertia(fn ($p) => $p->has('cart.packages', 0));

        $this->actingAs($this->rahim)->post('/market-cart', ['listing_id' => $this->chair->id, 'quantity' => 1])->assertSessionHasErrors('order');
    }

    public function test_an_empty_cart_and_bad_details_are_refused_and_staff_have_no_market_cart(): void
    {
        $this->post('/market-cart/checkout', $this->ship())->assertSessionHasErrors('order');

        $this->add($this->chair);
        $this->post('/market-cart/checkout', $this->ship(['phone' => '123']))->assertSessionHasErrors('phone');

        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->actingAs($staff)->get('/market-cart')->assertRedirect('/');
    }

    public function test_only_the_first_package_takes_a_token(): void
    {
        $this->actingAs($this->buyer);
        app(Tokens::class)->give($this->buyer, 50, 'Welcome', null, $this->rahim);
        $this->add($this->chair);
        $this->add($this->shirt);

        $this->post('/market-cart/checkout', $this->ship(['use_token' => true]))->assertSessionHasNoErrors();

        $orders = MarketOrder::orderBy('id')->get();
        $this->assertGreaterThan(0, (float) $orders[0]->token_amount);
        $this->assertSame(0.0, (float) $orders[1]->token_amount);
    }
}
