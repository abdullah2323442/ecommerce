<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13, 14, 15: a trade account sells, we approve and add our commission, buyers pay both and see only their own prices. */
class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reviewer;

    private User $seller;

    private Sector $wood;

    private Sector $clothing;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'name' => 'Rahim', 'area' => 'Mirpur', 'seller_trusted_at' => now()]);
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => true, 'premium_over' => 5000]);
        $this->clothing = Sector::create(['slug' => 'clothing', 'name' => 'Clothing', 'unit' => 'piece', 'allow_fraction' => false]);
    }

    private function listing(array $over = []): Listing
    {
        return Listing::create(array_merge([
            'seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => 'Teak chair', 'description' => 'Solid teak.', 'public_price' => 1000,
            'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(), 'submitted_at' => now(),
        ], $over));
    }

    private function form(array $over = []): array
    {
        return array_merge(['sector_id' => $this->wood->id, 'title' => 'Teak chair', 'description' => 'Solid teak, polished.', 'public_price' => 500, 'wholesale_price' => 450, 'min_qty' => 10, 'delivery_charge' => 100, 'stock' => 20], $over);
    }

    private function buyer(array $over = []): User
    {
        return User::factory()->create(array_merge(['account_type' => AccountType::Public], $over));
    }

    // ------------------------------------------------------------------ selling

    public function test_a_trade_account_lists_something_and_it_waits_for_us(): void
    {
        $this->actingAs($this->seller)->post('/wholesale/sell', $this->form() + ['photo' => UploadedFile::fake()->image('chair.jpg', 2400, 1800)])->assertSessionHasNoErrors();

        $l = Listing::firstOrFail();
        $this->assertSame(['pending', $this->seller->id, 'Teak chair'], [$l->status, $l->seller_id, $l->title]);
        $this->assertNull($l->commission_kind);
        $this->assertSame(1600, getimagesizefromstring(Storage::get($l->photo_path))[0]);
        $this->assertSame(1, Notice::where('user_id', $this->reviewer->id)->where('kind', 'listing_new')->count());

        auth()->logout();
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 0));
        $this->get("/market/{$l->id}")->assertRedirect('/');
    }

    public function test_a_shop_a_public_account_and_a_closed_account_cannot_sell(): void
    {
        foreach ([AccountType::Paikari, AccountType::Public, AccountType::Staff] as $type) {
            $u = User::factory()->create(['account_type' => $type, 'categories' => ['wood']]);
            $this->actingAs($u)->post('/wholesale/sell', $this->form())->assertRedirect('/');
        }

        $this->seller->forceFill(['suspended_at' => now()])->save();
        $this->actingAs($this->seller)->post('/wholesale/sell', $this->form())->assertStatus(302);
        $this->assertSame(0, Listing::count());
    }

    public function test_only_the_sectors_the_seller_was_given_and_never_medicine(): void
    {
        $med = Sector::create(['slug' => 'medicine', 'name' => 'Medicine', 'unit' => 'box']);
        $this->seller->forceFill(['categories' => ['wood', 'medicine']])->save();
        $this->actingAs($this->seller);

        $this->post('/wholesale/sell', $this->form(['sector_id' => $this->clothing->id]))->assertSessionHasErrors('listing');
        $this->post('/wholesale/sell', $this->form(['sector_id' => $med->id]))->assertSessionHasErrors('listing');
        $this->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->has('sectors', 1)->where('sectors.0.name', 'Wood'));
        $this->assertSame(0, Listing::count());
    }

    public function test_a_price_is_needed_and_must_be_more_than_zero_and_whole_units_where_the_sector_counts_so(): void
    {
        $this->actingAs($this->seller);
        $this->post('/wholesale/sell', $this->form(['public_price' => null, 'wholesale_price' => null]))->assertSessionHasErrors('listing');
        $this->post('/wholesale/sell', $this->form(['public_price' => 0, 'wholesale_price' => null]))->assertSessionHasErrors('listing');
        $this->post('/wholesale/sell', $this->form(['title' => 'x']))->assertSessionHasErrors('title');
        $this->assertSame(0, Listing::count());

        $this->seller->forceFill(['categories' => ['wood', 'clothing']])->save();
        $this->post('/wholesale/sell', $this->form(['sector_id' => $this->clothing->id, 'stock' => 2.5]))->assertSessionHasErrors('listing');
        $this->post('/wholesale/sell', $this->form(['sector_id' => $this->wood->id, 'stock' => 2.5]))->assertSessionHasNoErrors();   // wood counts in feet, fractions allowed
        $this->assertSame(1, Listing::count());
    }

    public function test_a_word_on_the_prohibited_list_flags_it_for_the_reviewer(): void
    {
        Setting::write('market.prohibited_words', 'weapon, fireworks');

        $this->actingAs($this->seller)->post('/wholesale/sell', $this->form(['title' => 'Teak chair', 'description' => 'Comes with free fireworks']))->assertSessionHasNoErrors();

        $this->assertSame('fireworks', Listing::firstOrFail()->flag);
        $this->actingAs($this->reviewer)->get('/admin/market')->assertInertia(fn (Assert $p) => $p->where('queue.0.flag', 'fireworks'));
    }

    // ------------------------------------------------------------------ our side: approval and commission

    public function test_we_publish_it_with_our_commission_and_the_seller_is_told(): void
    {
        $l = $this->listing(['status' => 'pending', 'commission_kind' => null, 'commission_value' => null, 'public_price' => 500]);

        $this->actingAs($this->reviewer)->post("/admin/market/{$l->id}/approve", ['kind' => 'percent', 'value' => 2])->assertSessionHasNoErrors();

        $l->refresh();
        $this->assertSame(['published', 'percent', '2.00', $this->reviewer->id], [$l->status, $l->commission_kind, (string) $l->commission_value, $l->reviewed_by]);
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'listing_approved')->count());
        $this->post("/admin/market/{$l->id}/approve", ['kind' => 'percent', 'value' => 2])->assertSessionHasErrors('listing');   // already looked at
    }

    public function test_the_commission_is_on_top_of_the_seller_price_as_a_percent_or_as_taka(): void
    {
        $this->assertSame(510.0, $this->listing(['public_price' => 500, 'commission_value' => 2])->withCommission(500));
        $taka = $this->listing(['commission_kind' => 'taka', 'commission_value' => 50]);
        $this->assertSame(550.0, $taka->withCommission(500));
        $this->assertSame(50.0, $taka->commissionOn(500));
        $this->assertSame(2000.0, $this->listing(['commission_kind' => 'taka', 'commission_value' => 0])->withCommission(2000));
    }

    public function test_the_commission_is_checked(): void
    {
        $l = $this->listing(['status' => 'pending']);
        $this->actingAs($this->reviewer);

        $this->post("/admin/market/{$l->id}/approve", ['kind' => 'percent', 'value' => 95])->assertSessionHasErrors('listing');
        $this->post("/admin/market/{$l->id}/approve", ['kind' => 'cash', 'value' => 5])->assertSessionHasErrors('kind');
        $this->post("/admin/market/{$l->id}/approve", ['kind' => 'taka', 'value' => -1])->assertSessionHasErrors('value');
        $this->assertSame('pending', $l->fresh()->status);
    }

    public function test_turning_a_listing_down_needs_a_reason_and_the_seller_hears_it(): void
    {
        $l = $this->listing(['status' => 'pending']);

        $this->actingAs($this->reviewer)->post("/admin/market/{$l->id}/reject", [])->assertSessionHasErrors('note');
        $this->post("/admin/market/{$l->id}/reject", ['note' => 'This is not wood.'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $l->fresh()->status);
        $this->assertSame('This is not wood.', json_decode(Notice::where('kind', 'listing_rejected')->firstOrFail()->body, true)['note']);
    }

    public function test_we_can_change_the_commission_later_and_the_buyers_price_follows(): void
    {
        $l = $this->listing(['public_price' => 1000, 'commission_value' => 2]);
        $this->actingAs($this->reviewer)->put("/admin/market/{$l->id}/commission", ['kind' => 'percent', 'value' => 5])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('listing.price', 1050));
    }

    public function test_the_seller_can_ask_for_less_and_we_see_it_in_a_list_of_their_own(): void
    {
        $l = $this->listing();

        $this->actingAs($this->seller)->post("/wholesale/sell/{$l->id}", ['commission_request' => 'Please 1%, so I can sell more.'])->assertSessionHasNoErrors();

        $this->actingAs($this->reviewer)->get('/admin/market')->assertInertia(fn (Assert $p) => $p->has('asking', 1)->where('asking.0.request', 'Please 1%, so I can sell more.'));
        $this->put("/admin/market/{$l->id}/commission", ['kind' => 'percent', 'value' => 1]);
        $this->assertNull($l->fresh()->commission_request);
    }

    public function test_the_queue_shows_how_long_each_has_waited_and_which_have_no_photo(): void
    {
        $this->listing(['status' => 'pending', 'submitted_at' => now()->subDays(4), 'photo_path' => null, 'title' => 'Old one']);
        $this->listing(['status' => 'pending', 'submitted_at' => now()->subDay(), 'photo_path' => 'market/x.jpg', 'title' => 'Newer one']);

        $this->actingAs($this->reviewer)->get('/admin/market')->assertInertia(fn (Assert $p) => $p->has('queue', 2)
            ->where('queue.0.title', 'Old one')->where('queue.0.waiting_days', 4)->where('queue.0.no_photo', true)
            ->where('queue.1.no_photo', false)->where('auth.listingsPending', 2));
    }

    public function test_only_staff_who_manage_customers_review(): void
    {
        $l = $this->listing(['status' => 'pending']);
        $counterOnly = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);

        $this->actingAs($this->seller)->post("/admin/market/{$l->id}/approve", ['kind' => 'percent', 'value' => 2])->assertRedirect('/');
        $this->actingAs($counterOnly)->get('/admin/market')->assertRedirect('/');
        $this->assertSame('pending', $l->fresh()->status);
    }

    // ------------------------------------------------------------------ what buyers see

    public function test_a_public_buyer_sees_the_price_with_commission_and_never_the_parts(): void
    {
        $l = $this->listing(['public_price' => 1000, 'wholesale_price' => 800]);

        $page = $this->get("/market/{$l->id}");
        $page->assertInertia(fn (Assert $p) => $p->where('listing.price', 1020)->where('listing.title', 'Teak chair'));

        $json = json_encode($page->viewData('page')['props']['listing']);
        foreach (['commission', 'seller_price', 'wholesale', '800', 'Rahim', 'Mirpur'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "leaked {$secret}");
        }
    }

    public function test_a_trade_buyer_in_that_sector_sees_the_trade_price_and_minimum(): void
    {
        $l = $this->listing(['public_price' => 1000, 'wholesale_price' => 800, 'min_qty' => 10]);
        $buyer = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($buyer)->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('listing.price', 816)->where('listing.min_qty', 10));
    }

    public function test_a_trade_buyer_in_another_sector_sees_the_public_price(): void
    {
        $l = $this->listing(['public_price' => 1000, 'wholesale_price' => 800]);
        $buyer = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['clothing']]);

        $this->actingAs($buyer)->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('listing.price', 1020));
    }

    public function test_something_for_trade_buyers_only_does_not_exist_for_anyone_else(): void
    {
        $l = $this->listing(['public_price' => null, 'wholesale_price' => 800]);

        $this->get("/market/{$l->id}")->assertRedirect('/');
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 0));
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->get("/market/{$l->id}")->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['clothing']]))->get("/market/{$l->id}")->assertRedirect('/');

        $inSector = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);
        $this->actingAs($inSector)->get("/market/{$l->id}")->assertOk();
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 1));
    }

    public function test_waiting_turned_down_paused_and_closed_seller_listings_are_not_shown(): void
    {
        foreach (['pending', 'rejected', 'paused'] as $status) {
            $this->listing(['status' => $status, 'title' => "A {$status} one"]);
        }
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'suspended_at' => now()]);
        $this->listing(['seller_id' => $other->id, 'title' => 'From a closed account']);
        $this->wood->update(['is_active' => false]);
        $this->listing(['title' => 'In a closed sector']);

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 0)->has('sectors', 0));
    }

    public function test_every_product_carries_our_name_unless_the_owner_turned_the_sellers_on(): void
    {
        $l = $this->listing();
        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('listing.seller', config('brand.name')));

        $this->actingAs($this->reviewer)->put("/admin/market/sellers/{$this->seller->id}/shop-name", ['show' => true])->assertSessionHasNoErrors();
        auth()->logout();
        $this->get("/market/{$l->id}")->assertInertia(fn (Assert $p) => $p->where('listing.seller', 'Rahim Timber'));
    }

    // ------------------------------------------------------------------ browsing

    public function test_the_sectors_with_something_in_them_come_as_icons_with_counts(): void
    {
        $this->listing();
        $this->listing(['title' => 'Teak table']);

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('sectors', 1)->where('sectors.0.slug', 'wood')->where('sectors.0.count', 2));
        $this->get('/market?sector=clothing')->assertInertia(fn (Assert $p) => $p->has('items', 0));
    }

    public function test_only_budget_ranges_that_have_products_are_offered(): void
    {
        $this->listing(['public_price' => 150, 'commission_value' => 0, 'title' => 'Cheap']);
        $this->listing(['public_price' => 3000, 'commission_value' => 0, 'title' => 'Middle']);

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('ranges', 2)->where('ranges.0', ['from' => 0, 'to' => 200])->where('ranges.1', ['from' => 2000, 'to' => 5000]));
    }

    public function test_a_budget_brings_that_range_to_the_front(): void
    {
        $this->listing(['public_price' => 150, 'commission_value' => 0, 'title' => 'Cheap']);
        $this->listing(['public_price' => 3000, 'commission_value' => 0, 'title' => 'Middle']);

        $this->get('/market?from=2000&to=5000')->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.title', 'Middle'));
        $this->get('/market?from=5000')->assertInertia(fn (Assert $p) => $p->has('items', 0));
    }

    public function test_the_range_follows_the_commission_because_the_buyer_pays_it(): void
    {
        $this->listing(['public_price' => 195, 'commission_value' => 5, 'title' => 'Edge']);   // 204.75 to the buyer

        $this->get('/market?from=0&to=200')->assertInertia(fn (Assert $p) => $p->has('items', 0));
        $this->get('/market?from=200&to=500')->assertInertia(fn (Assert $p) => $p->has('items', 1));
    }

    public function test_a_product_above_the_sectors_premium_amount_goes_to_the_premium_stall_by_itself(): void
    {
        $l = $this->listing(['public_price' => 6000, 'commission_value' => 0, 'title' => 'Teak wardrobe']);
        $this->listing(['public_price' => 900, 'commission_value' => 0, 'title' => 'Teak stool']);

        $this->get('/market?premium=1')->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.title', 'Teak wardrobe')->where('hasPremium', true));
        $this->get('/market?stall=normal')->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.title', 'Teak stool'));

        // The seller drops the price: it leaves by itself. Raises it: it goes back.
        $this->actingAs($this->seller)->post("/wholesale/sell/{$l->id}", ['public_price' => 4000]);
        auth()->logout();
        $this->get('/market?premium=1')->assertInertia(fn (Assert $p) => $p->has('items', 0)->where('hasPremium', false));
        $this->actingAs($this->seller)->post("/wholesale/sell/{$l->id}", ['public_price' => 5200]);
        auth()->logout();
        $this->get('/market?premium=1')->assertInertia(fn (Assert $p) => $p->has('items', 1));
    }

    public function test_a_sector_with_no_premium_amount_has_no_premium_stall(): void
    {
        $this->seller->forceFill(['categories' => ['clothing']])->save();
        $this->listing(['sector_id' => $this->clothing->id, 'public_price' => 90000, 'commission_value' => 0]);

        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('hasPremium', false));
    }

    public function test_search_and_sorting(): void
    {
        $this->listing(['title' => 'Teak chair', 'public_price' => 900, 'commission_value' => 0, 'approved_at' => now()->subDays(2)]);
        $this->listing(['title' => 'Oak table', 'description' => 'Strong oak', 'public_price' => 1500, 'commission_value' => 0, 'approved_at' => now()->subDay()]);

        $this->get('/market?q=oak')->assertInertia(fn (Assert $p) => $p->has('items', 1)->where('items.0.title', 'Oak table'));
        $this->get('/market?sort=low')->assertInertia(fn (Assert $p) => $p->where('items.0.title', 'Teak chair'));
        $this->get('/market?sort=high')->assertInertia(fn (Assert $p) => $p->where('items.0.title', 'Oak table'));
        $this->get('/market?sort=new')->assertInertia(fn (Assert $p) => $p->where('items.0.title', 'Oak table'));
    }

    public function test_sellers_in_the_buyers_own_area_come_first(): void
    {
        $far = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'area' => 'Sylhet']);
        $this->listing(['seller_id' => $far->id, 'title' => 'Far one', 'approved_at' => now()]);
        $this->listing(['title' => 'Near one', 'approved_at' => now()->subDays(3)]);   // Mirpur

        $this->actingAs($this->buyer(['area' => 'mirpur']))->get('/market')->assertInertia(fn (Assert $p) => $p->where('items.0.title', 'Near one')->where('items.0.near', true));
        auth()->logout();
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('items.0.title', 'Far one'));   // nobody's area known: newest first
    }

    // ------------------------------------------------------------------ the picture

    public function test_the_picture_of_a_waiting_listing_is_for_its_seller_and_us_only(): void
    {
        $l = $this->listing(['status' => 'pending']);
        Storage::put('market/c.jpg', 'x');
        $l->update(['photo_path' => 'market/c.jpg']);

        $this->get("/market/photo/{$l->id}")->assertRedirect('/');
        $this->actingAs($this->buyer())->get("/market/photo/{$l->id}")->assertRedirect('/');
        $this->actingAs($this->seller)->get("/market/photo/{$l->id}")->assertOk();
        $this->actingAs($this->reviewer)->get("/market/photo/{$l->id}")->assertOk();

        $l->update(['status' => 'published']);
        auth()->logout();
        $this->get("/market/photo/{$l->id}")->assertOk();
    }

    // ------------------------------------------------------------------ the seller's own changes

    public function test_price_stock_and_delivery_change_at_once_but_the_words_the_picture_and_the_sector_go_back_to_us(): void
    {
        $l = $this->listing(['stock' => 5]);
        $this->actingAs($this->seller);

        $this->post("/wholesale/sell/{$l->id}", ['public_price' => 1200, 'stock' => 9, 'delivery_charge' => 150])->assertSessionHasNoErrors();
        $this->assertSame(['published', '1200.00', '9.00'], [$l->fresh()->status, (string) $l->fresh()->public_price, (string) $l->fresh()->stock]);

        $this->post("/wholesale/sell/{$l->id}", ['title' => 'Teak chair, new'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $l->fresh()->status);
    }

    public function test_a_turned_down_listing_that_is_fixed_goes_back_to_be_read(): void
    {
        $l = $this->listing(['status' => 'rejected', 'review_note' => 'No photo.']);

        $this->actingAs($this->seller)->post("/wholesale/sell/{$l->id}", ['description' => 'Solid teak, now with a photo.', 'photo' => UploadedFile::fake()->image('a.jpg')])->assertSessionHasNoErrors();

        $l->refresh();
        $this->assertSame(['pending', null], [$l->status, $l->review_note]);
        $this->assertNotNull($l->photo_path);
    }

    public function test_a_seller_pauses_shows_again_and_removes_their_own_listing_only(): void
    {
        $l = $this->listing();
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($other)->put("/wholesale/sell/{$l->id}/pause", ['paused' => true])->assertRedirect('/');
        $this->actingAs($this->seller)->put("/wholesale/sell/{$l->id}/pause", ['paused' => true])->assertSessionHasNoErrors();
        $this->assertSame('paused', $l->fresh()->status);
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->has('items', 0));

        $this->put("/wholesale/sell/{$l->id}/pause", ['paused' => false]);
        $this->assertSame('published', $l->fresh()->status);

        $this->actingAs($other)->delete("/wholesale/sell/{$l->id}")->assertRedirect('/');
        $this->actingAs($this->seller)->delete("/wholesale/sell/{$l->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Listing::count());
    }

    public function test_vacation_mode_is_a_switch_on_the_seller(): void
    {
        $this->actingAs($this->seller)->put('/wholesale/vacation', ['paused' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->seller->fresh()->seller_paused);
        $this->put('/wholesale/vacation', ['paused' => false]);
        $this->assertFalse($this->seller->fresh()->seller_paused);
    }

    // ------------------------------------------------------------------ sectors

    public function test_the_owner_opens_a_sector_with_its_own_unit_and_premium_amount(): void
    {
        $this->actingAs($this->admin)->post('/admin/market/sectors', [
            'slug' => 'grocery', 'name' => 'Grocery', 'name_bn' => 'মুদি', 'unit' => 'kg', 'allow_fraction' => true, 'premium_over' => 2000, 'preorder' => false, 'chat' => false, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $s = Sector::where('slug', 'grocery')->firstOrFail();
        $this->assertSame(['kg', true, '2000.00'], [$s->unit, $s->allow_fraction, (string) $s->premium_over]);

        $this->put("/admin/market/sectors/{$s->id}", ['slug' => 'grocery', 'name' => 'Grocery', 'unit' => 'sack', 'allow_fraction' => false, 'preorder' => true, 'chat' => false, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertSame(['sack', null], [$s->fresh()->unit, $s->fresh()->premium_over]);
    }

    public function test_a_sector_is_checked_and_medicine_is_not_one(): void
    {
        $this->actingAs($this->admin);
        $base = ['name' => 'X', 'unit' => 'piece', 'allow_fraction' => false, 'preorder' => false, 'chat' => false, 'is_active' => true];

        $this->post('/admin/market/sectors', $base + ['slug' => 'medicine'])->assertSessionHasErrors('slug');
        $this->post('/admin/market/sectors', $base + ['slug' => 'Bad Slug'])->assertSessionHasErrors('slug');
        $this->post('/admin/market/sectors', $base + ['slug' => 'wood'])->assertSessionHasErrors('slug');   // taken
        $this->assertSame(2, Sector::count());
    }

    public function test_only_the_owner_opens_sectors(): void
    {
        $base = ['slug' => 'grocery', 'name' => 'G', 'unit' => 'kg', 'allow_fraction' => true, 'preorder' => false, 'chat' => false, 'is_active' => true];

        $this->actingAs($this->reviewer)->post('/admin/market/sectors', $base)->assertRedirect('/');
        $this->assertSame(2, Sector::count());
    }
}
