<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\Market\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 13: a wishlist for a signed-in buyer. */
class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'seller_trusted_at' => now()]);
        $this->buyer = User::factory()->create();
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);
        $this->chair = Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => 'Teak Chair', 'public_price' => 1000, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now()]);
    }

    public function test_a_buyer_saves_and_removes_a_product(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/wishlist")->assertSessionHasNoErrors();
        $this->assertSame(1, WishlistItem::where('user_id', $this->buyer->id)->count());

        $this->get("/market/{$this->chair->id}")->assertInertia(fn ($p) => $p->where('wishlisted', true));
        $this->get('/wishlist')->assertInertia(fn ($p) => $p->has('items', 1)->where('items.0.title', 'Teak Chair')->where('items.0.price', 1020)->missing('items.0.seller_price'));

        $this->post("/market/{$this->chair->id}/wishlist");
        $this->assertSame(0, WishlistItem::count());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->post("/market/{$this->chair->id}/wishlist")->assertRedirect('/login');
        $this->get('/wishlist')->assertRedirect('/login');
    }

    public function test_a_product_taken_off_sale_leaves_the_list_without_a_word(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/wishlist");
        $this->chair->update(['status' => 'draft']);

        $this->get('/wishlist')->assertInertia(fn ($p) => $p->has('items', 0));
    }

    public function test_one_cannot_save_a_product_one_may_not_see(): void
    {
        $this->chair->update(['status' => 'draft']);

        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/wishlist")->assertRedirect('/');
        $this->assertSame(0, WishlistItem::count());
    }

    public function test_each_buyer_has_their_own_list(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/wishlist");

        $this->actingAs(User::factory()->create())->get('/wishlist')->assertInertia(fn ($p) => $p->has('items', 0));
    }

    public function test_a_real_price_drop_is_told_once_and_a_rise_then_a_fall_is_told_again(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/wishlist");
        $wl = app(Wishlist::class);
        $told = fn () => Notice::where('user_id', $this->buyer->id)->where('kind', 'wishlist_drop')->count();

        $this->assertSame(0, $wl->dropAlerts(), 'nothing changed');

        $this->chair->update(['public_price' => 800]);
        $this->assertSame(1, $wl->dropAlerts());
        $this->assertSame(1, $told());
        $this->assertSame(0, $wl->dropAlerts(), 'told once for this price');

        $this->chair->update(['public_price' => 1000]);
        $this->assertSame(0, $wl->dropAlerts(), 'a rise is not news');
        $this->chair->update(['public_price' => 900]);
        $this->assertSame(1, $wl->dropAlerts(), 'but a fall from the higher price is');
        $this->assertSame(2, $told());
    }

    public function test_no_alert_for_a_product_the_buyer_can_no_longer_see(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/wishlist");
        $this->chair->update(['public_price' => 500, 'status' => 'draft']);

        $this->assertSame(0, app(Wishlist::class)->dropAlerts());
    }
}
