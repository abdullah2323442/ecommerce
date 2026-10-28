<?php

namespace Tests\Feature\Supplier;

use App\Enums\AccountType;
use App\Models\FoodOrder;
use App\Models\MarketOrder;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 3, items 17 and 18: a supplier app next to the customer app, one login for both, and a button to go across. */
class SupplierAppTest extends TestCase
{
    use RefreshDatabase;

    private function seller(array $more = []): User
    {
        return User::factory()->create($more + ['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber']);
    }

    private function order(User $seller, array $more = []): MarketOrder
    {
        static $n = 0;
        $n++;

        return MarketOrder::create($more + [
            'public_token' => str_pad((string) $n, 40, 'x'), 'seller_id' => $seller->id, 'buyer_id' => null, 'title' => 'Chair', 'unit' => 'piece', 'quantity' => 1, 'unit_price' => 1000, 'seller_price' => 980, 'commission' => 20,
            'audience' => 'public', 'ship_to' => [], 'delivery_charge' => 0, 'total' => 1000, 'status' => 'placed', 'pay_by' => 'cod',
        ]);
    }

    public function test_a_seller_opens_the_supplier_app_with_what_is_waiting_first(): void
    {
        $seller = $this->seller();
        $other = $this->seller();
        $this->order($seller);
        $this->order($seller);
        $this->order($seller, ['pay_by' => 'online']);                     // not paid yet: the seller does not see it
        $this->order($seller, ['pay_by' => 'online', 'paid_at' => now()]); // paid: waiting
        $this->order($seller, ['status' => 'accepted']);                   // already answered
        $this->order($other);                                              // someone else's

        $this->actingAs($seller)->get('/supplier')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('supplier')->where('name', 'Rahim Timber')->where('waiting', 3)->where('entries.0.url', '/wholesale/sell/orders')->where('entries.0.badge', 3)->has('entries', 9));
    }

    public function test_a_restaurant_owner_sees_their_own_orders_and_menu_screens(): void
    {
        $owner = User::factory()->create(['account_type' => AccountType::FoodVendor]);
        $shop = Restaurant::create(['owner_id' => $owner->id, 'name' => 'Burger Barn', 'area' => 'Mirpur', 'areas' => ['Mirpur'], 'status' => 'active', 'commission_kind' => 'percent', 'commission_value' => 10]);
        FoodOrder::create(['public_token' => str_repeat('a', 40), 'restaurant_id' => $shop->id, 'mode' => 'delivery', 'status' => 'pending', 'ship_to' => [], 'subtotal' => 250, 'delivery_charge' => 30, 'total' => 280]);
        FoodOrder::create(['public_token' => str_repeat('b', 40), 'restaurant_id' => $shop->id, 'mode' => 'delivery', 'status' => 'confirmed', 'ship_to' => [], 'subtotal' => 250, 'delivery_charge' => 30, 'total' => 280]);

        $this->actingAs($owner)->get('/supplier')->assertInertia(fn (Assert $p) => $p->where('waiting', 1)->where('entries.0.url', '/food/vendor')->where('entries.0.badge', 1)->has('entries', 2));
    }

    public function test_an_mpo_and_a_craftsman_each_see_only_their_own_screen(): void
    {
        $mpo = User::factory()->create(['account_type' => AccountType::Mpo]);
        $craftsman = User::factory()->create(['account_type' => AccountType::Craftsman]);

        $this->actingAs($mpo)->get('/supplier')->assertInertia(fn (Assert $p) => $p->where('entries.0.url', '/mpo')->has('entries', 1));
        $this->actingAs($craftsman)->get('/supplier')->assertInertia(fn (Assert $p) => $p->where('entries.0.url', '/craftsman')->has('entries', 1));
    }

    public function test_buyers_staff_and_the_owner_have_no_supplier_app_and_a_guest_is_asked_to_sign_in(): void
    {
        $this->get('/supplier')->assertRedirect('/login');

        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Staff, AccountType::Admin] as $type) {
            // Not for them: a quiet redirect home or a missing page, never the supplier screen.
            $this->assertContains($this->actingAs(User::factory()->create(['account_type' => $type]))->get('/supplier')->getStatusCode(), [302, 404]);
        }
    }

    public function test_a_suspended_seller_has_no_supplier_app(): void
    {
        $this->actingAs($this->seller(['suspended_at' => now()]))->get('/supplier')->assertRedirect('/');
    }

    public function test_the_customer_app_shows_a_way_across_only_to_people_who_sell(): void
    {
        $this->actingAs($this->seller())->get('/')->assertInertia(fn (Assert $p) => $p->where('supplierApp', true));
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/')->assertInertia(fn (Assert $p) => $p->where('supplierApp', false));
        auth()->logout();
        $this->get('/')->assertInertia(fn (Assert $p) => $p->where('supplierApp', false));
    }

    public function test_the_supplier_app_has_its_own_install_file_beside_the_customer_app(): void
    {
        $supplier = $this->get('/supplier/manifest.webmanifest')->assertOk()->json();
        $customer = $this->get('/manifest.webmanifest')->assertOk()->json();

        $this->assertSame('/supplier', $supplier['id']);
        $this->assertSame('/supplier', $supplier['start_url']);
        $this->assertSame('/', $supplier['scope']); // wide enough to hold the selling screens
        $this->assertNotSame($customer['id'], $supplier['id']);
        $this->assertNotSame($customer['name'], $supplier['name']);
        $this->assertCount(3, $supplier['icons']);
    }

    public function test_the_page_points_the_phone_at_the_right_install_file(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)->get('/supplier')->assertSee('/supplier/manifest.webmanifest', false);
        $this->get('/')->assertSee('/manifest.webmanifest', false)->assertDontSee('/supplier/manifest.webmanifest', false);
    }

    public function test_one_login_serves_both_apps(): void
    {
        $seller = $this->seller();

        $this->actingAs($seller)->get('/supplier')->assertOk();
        $this->get('/')->assertOk();
        $this->get('/wholesale/sell')->assertOk();
        $this->assertAuthenticatedAs($seller);
    }
}
