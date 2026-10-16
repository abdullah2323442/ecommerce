<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Medicine;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 13: one history of everything a buyer ordered, from every part of the site. */
class OrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
    }

    private function medicineOrder(User $for, string $status = 'placed'): Invoice
    {
        return Invoice::create(['kind' => 'retail', 'channel' => 'online', 'status' => $status, 'customer_id' => $for->id, 'label' => 'x', 'public_token' => str()->random(40), 'placed_at' => now()]);
    }

    private function marketOrder(User $for, string $status = 'placed'): MarketOrder
    {
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'seller_trusted_at' => now()]);
        $wood = Sector::firstOrCreate(['slug' => 'wood'], ['name' => 'Wood', 'unit' => 'foot']);
        $l = Listing::create(['seller_id' => $seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);

        return MarketOrder::create([
            'public_token' => str()->random(40), 'listing_id' => $l->id, 'seller_id' => $seller->id, 'buyer_id' => $for->id, 'title' => 'Teak chair', 'unit' => 'foot', 'quantity' => 1,
            'unit_price' => 1020, 'seller_price' => 1000, 'commission' => 20, 'delivery_charge' => 0, 'total' => 1020, 'audience' => 'public', 'ship_to' => ['name' => 'X', 'phone' => '01711000000', 'address' => 'Y'], 'status' => $status,
        ]);
    }

    public function test_orders_from_different_parts_show_together_newest_first_with_links(): void
    {
        $med = $this->medicineOrder($this->buyer);
        $this->travel(1)->minutes();
        $mk = $this->marketOrder($this->buyer, 'delivered');

        $this->actingAs($this->buyer)->get('/history')->assertInertia(fn ($p) => $p
            ->has('orders', 2)
            ->where('orders.0.kind', 'market')->where('orders.0.state', 'done')->where('orders.0.link', "/market/orders/{$mk->public_token}")
            ->where('orders.1.kind', 'medicine')->where('orders.1.state', 'open')->where('orders.1.link', "/orders/{$med->public_token}"));
    }

    public function test_only_my_own_orders_appear_and_a_guest_is_sent_to_sign_in(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $this->medicineOrder($other);
        $this->marketOrder($other);

        $this->actingAs($this->buyer)->get('/history')->assertInertia(fn ($p) => $p->has('orders', 0));

        auth()->logout();
        $this->get('/history')->assertRedirect('/login');
    }

    public function test_a_cancelled_order_says_so_and_nothing_names_a_business_side(): void
    {
        $this->medicineOrder($this->buyer, 'cancelled');

        $json = '';
        $this->actingAs($this->buyer)->get('/history')->assertInertia(function ($p) use (&$json) {
            $json = json_encode($p->toArray()['props']['orders']);
            $p->where('orders.0.state', 'cancelled');
        });

        foreach (['paikari', 'wholesale', 'craftsman', 'MPO'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $json);
        }
    }

    public function test_the_fixed_titles_are_in_the_buyers_language(): void
    {
        $this->medicineOrder($this->buyer);

        $this->withCookie('locale', 'bn')->actingAs($this->buyer)->get('/history')->assertInertia(fn ($p) => $p->where('orders.0.title', 'ওষুধের অর্ডার'));
        $this->withCookie('locale', 'en')->get('/history')->assertInertia(fn ($p) => $p->where('orders.0.title', 'Medicine order'));
    }
}
