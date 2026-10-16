<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Listing;
use App\Models\Medicine;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27: web-app sync. A signed-in person's cart is the same on every device; a visitor's cart joins it at sign-in. */
class CartSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Medicine $napa;

    private Medicine $seclo;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['account_type' => AccountType::Public, 'email' => 'me@example.com', 'password' => bcrypt('Secret-pass-1')]);
        $make = fn (string $n) => Medicine::create([
            'company_id' => Company::firstOrCreate(['name' => 'Square'])->id, 'brand_name' => $n, 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make('Napa');
        $this->seclo = $make('Seclo');
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => false]);
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'seller_trusted_at' => now()]);
        $this->chair = Listing::create(['seller_id' => $seller->id, 'sector_id' => $wood->id, 'title' => 'Chair', 'public_price' => 1000, 'delivery_charge' => 100, 'stock' => 10, 'status' => 'published', 'approved_at' => now()]);
    }

    private function add(Medicine $m, int $q = 1)
    {
        return $this->post('/cart', ['medicine_id' => $m->id, 'unit' => 'strip', 'quantity' => $q]);
    }

    private function saved(string $kind): array
    {
        return (array) json_decode((string) DB::table('account_carts')->where('user_id', $this->me->id)->where('kind', $kind)->value('items'), true);
    }

    public function test_a_visitors_cart_stays_in_the_session_and_nothing_is_saved(): void
    {
        $this->add($this->napa, 2);

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 1));
        $this->assertSame(0, DB::table('account_carts')->count());
    }

    public function test_a_signed_in_persons_cart_is_kept_under_their_account(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 2);

        $this->assertCount(1, $this->saved('shop'));
        $this->assertSame(2, $this->saved('shop')[0]['q']);
        $this->assertNull(session('cart'));
    }

    public function test_the_same_cart_is_there_on_another_device(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 3);

        // A second device: a fresh session, the same account.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->me);

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 1)->where('lines.0.quantity', 3));
    }

    public function test_a_change_on_one_device_shows_on_the_other(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 1);
        $this->add($this->seclo, 1);
        $this->delete('/cart/'.$this->firstKey());

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 1));
    }

    private function firstKey(): string
    {
        $r = $this->get('/cart');

        return $r->viewData('page')['props']['lines'][0]['key'];
    }

    public function test_a_visitors_cart_joins_the_account_at_sign_in(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 1);
        auth()->logout();
        $this->flushSession();

        $this->add($this->seclo, 2);
        $this->post('/login', ['email' => 'me@example.com', 'password' => 'Secret-pass-1'])->assertRedirect();

        $this->assertCount(2, $this->saved('shop'));
        $this->assertNull(session('cart'));
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 2));
    }

    public function test_the_same_product_is_counted_once_with_the_larger_number(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 5);
        auth()->logout();
        $this->flushSession();

        $this->add($this->napa, 2);
        $this->post('/login', ['email' => 'me@example.com', 'password' => 'Secret-pass-1']);

        $items = $this->saved('shop');
        $this->assertCount(1, $items);
        $this->assertSame(5, $items[0]['q']);
    }

    public function test_the_market_cart_follows_the_account_too(): void
    {
        $this->actingAs($this->me);
        $this->post('/market-cart', ['listing_id' => $this->chair->id, 'quantity' => 2])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->saved('market'));
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->me);
        $this->get('/market-cart')->assertInertia(fn (Assert $p) => $p->has('cart.packages', 1));
    }

    public function test_ordering_empties_the_saved_cart(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 1);

        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery']);

        $this->assertSame([], $this->saved('shop'));
    }

    public function test_one_persons_cart_is_never_another_persons(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 1);

        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($other)->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 0));
    }

    public function test_a_cart_item_that_is_gone_drops_out_quietly(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 1);
        $this->napa->update(['is_active' => false]);

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->has('lines', 0));
        $this->assertSame([], $this->saved('shop'));
    }

    public function test_deleting_the_account_removes_the_saved_cart(): void
    {
        $this->actingAs($this->me);
        $this->add($this->napa, 1);

        $this->me->delete();

        $this->assertSame(0, DB::table('account_carts')->count());
    }
}
