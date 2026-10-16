<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\Marketplace;
use App\Services\Market\Shops;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13: a shop's own page and Follow, only for a seller whose name is switched on (ch 05). */
class ShopPageTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $other;

    private User $buyer;

    private User $reviewer;

    private Sector $wood;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'show_shop_name' => true, 'area' => 'Mirpur', 'created_at' => '2025-06-01'])->fresh();
        $this->other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Karim Wood'])->fresh();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
    }

    private function product(User $seller, string $title, array $more = []): Listing
    {
        return Listing::create($more + [
            'seller_id' => $seller->id, 'sector_id' => $this->wood->id, 'title' => $title, 'public_price' => 500, 'min_qty' => 1, 'stock' => 5, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    public function test_a_shop_with_its_name_on_has_a_page_with_its_own_products_only(): void
    {
        $this->product($this->seller, 'Teak chair');
        $this->product($this->other, 'Teak table');

        $this->get("/shop/{$this->seller->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('market/shop')
            ->where('shop.name', 'Rahim Timber')->where('shop.area', 'Mirpur')->where('shop.months', 16)->where('shop.rating', 'New')
            ->has('items', 1)->where('items.0.title', 'Teak chair'));
    }

    public function test_a_seller_whose_name_is_off_has_no_page_and_nothing_hints_at_one(): void
    {
        $this->product($this->other, 'Teak table');

        $this->get("/shop/{$this->other->id}")->assertRedirect('/');
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('items.0.shop', null)->where('items.0.seller', config('brand.name', config('app.name'))));
    }

    public function test_a_buyer_account_or_staff_has_no_shop_page(): void
    {
        $this->get("/shop/{$this->buyer->id}")->assertRedirect('/');
        $this->get("/shop/{$this->reviewer->id}")->assertRedirect('/');
    }

    public function test_a_stopped_shop_has_no_page(): void
    {
        $this->seller->forceFill(['penalty_suspended_at' => now()])->save();

        $this->get("/shop/{$this->seller->id}")->assertRedirect('/');
    }

    public function test_the_market_card_and_product_page_point_to_the_shop(): void
    {
        $chair = $this->product($this->seller, 'Teak chair');

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('items.0.shop', $this->seller->id));
        $this->get("/market/{$chair->id}")->assertInertia(fn (Assert $p) => $p->where('listing.shop', $this->seller->id)->where('listing.seller', 'Rahim Timber'));
    }

    public function test_products_a_viewer_may_not_see_are_not_on_the_page(): void
    {
        $this->product($this->seller, 'Shown');
        $this->product($this->seller, 'Paused', ['status' => 'paused']);
        $this->product($this->seller, 'Trade only', ['public_price' => null, 'wholesale_price' => 400]);

        $this->get("/shop/{$this->seller->id}")->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.title', 'Shown'));
    }

    public function test_a_signed_in_buyer_follows_and_unfollows(): void
    {
        $this->actingAs($this->buyer)->post("/shop/{$this->seller->id}/follow")->assertSessionHasNoErrors();
        $this->actingAs($this->buyer)->post("/shop/{$this->seller->id}/follow")->assertSessionHasNoErrors();

        $this->assertSame(1, app(Shops::class)->followers($this->seller), 'twice is still one');
        $this->actingAs($this->buyer)->get("/shop/{$this->seller->id}")->assertInertia(fn (Assert $p) => $p->where('shop.following', true)->where('shop.followers', 1)->where('shop.can_follow', true));

        $this->actingAs($this->buyer)->delete("/shop/{$this->seller->id}/follow");
        $this->assertSame(0, app(Shops::class)->followers($this->seller));
    }

    public function test_a_guest_cannot_follow_and_is_not_offered_it(): void
    {
        $this->post("/shop/{$this->seller->id}/follow")->assertRedirect();
        $this->assertSame(0, app(Shops::class)->followers($this->seller));
        $this->get("/shop/{$this->seller->id}")->assertInertia(fn (Assert $p) => $p->where('shop.can_follow', false));
    }

    public function test_the_shop_itself_and_staff_cannot_follow(): void
    {
        $this->actingAs($this->seller)->post("/shop/{$this->seller->id}/follow")->assertSessionHasErrors('shop');
        $this->actingAs($this->seller)->get("/shop/{$this->seller->id}")->assertInertia(fn (Assert $p) => $p->where('shop.can_follow', false));
        $this->actingAs($this->reviewer)->get("/shop/{$this->seller->id}")->assertInertia(fn (Assert $p) => $p->where('shop.can_follow', false));
    }

    public function test_a_shop_whose_name_is_off_cannot_be_followed(): void
    {
        $this->actingAs($this->buyer)->post("/shop/{$this->other->id}/follow")->assertSessionHasErrors('shop');
        $this->assertSame(0, app(Shops::class)->followers($this->other));
    }

    public function test_followers_are_told_once_when_a_new_product_is_first_shown(): void
    {
        $this->actingAs($this->buyer)->post("/shop/{$this->seller->id}/follow");
        $stranger = User::factory()->create(['account_type' => AccountType::Public]);
        $new = $this->product($this->seller, 'Oak bed', ['status' => 'pending', 'approved_at' => null, 'commission_kind' => null, 'commission_value' => 0]);

        app(Marketplace::class)->approve($new, $this->reviewer, 'percent', 10);

        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'shop_new_product')->count());
        $this->assertSame(0, Notice::where('user_id', $stranger->id)->where('kind', 'shop_new_product')->count());

        // Changed, read again, approved again: not news a second time.
        $new->fresh()->update(['status' => 'pending']);
        app(Marketplace::class)->approve($new->fresh(), $this->reviewer, 'percent', 10);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'shop_new_product')->count());
    }

    public function test_a_shop_with_its_name_off_tells_no_one(): void
    {
        $this->actingAs($this->buyer)->post("/shop/{$this->seller->id}/follow");
        $this->seller->forceFill(['show_shop_name' => false])->save();
        $new = $this->product($this->seller, 'Oak bed', ['status' => 'pending', 'approved_at' => null]);

        app(Marketplace::class)->approve($new, $this->reviewer, 'percent', 10);

        $this->assertSame(0, Notice::where('kind', 'shop_new_product')->count(), 'their name is not ours to give away');
    }

    public function test_the_message_names_the_shop_and_the_product(): void
    {
        $this->actingAs($this->buyer)->post("/shop/{$this->seller->id}/follow");
        $new = $this->product($this->seller, 'Oak bed', ['status' => 'pending', 'approved_at' => null]);
        app(Marketplace::class)->approve($new, $this->reviewer, 'percent', 10);

        $body = json_decode(Notice::where('kind', 'shop_new_product')->firstOrFail()->body, true);

        $this->assertSame('Oak bed', $body['name']);
        $this->assertSame('Rahim Timber', $body['shop']);
        $this->assertSame('/market/'.$new->id, $body['link']);
    }
}
