<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Models\ListingReview;
use App\Models\MarketOrder;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\ListingPhotos;
use App\Services\Market\MarketOrders;
use App\Services\Market\Reviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13: reviews only from buyers who really bought (with photos), and more than one photo on a product. */
class ReviewsAndPhotosTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private User $staff;

    private Listing $chair;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        Storage::fake();
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber'])->fresh();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Secret Buyer Name']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->chair = Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'min_qty' => 1, 'stock' => 20, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(), 'photo_path' => 'market/main.jpg',
        ]);
        Storage::put('market/main.jpg', 'x');
    }

    private function ship(): array
    {
        return ['name' => 'Buyer', 'phone' => '01712345678', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 4'];
    }

    private function order(string $status = 'delivered', ?User $buyer = null): MarketOrder
    {
        $o = app(MarketOrders::class)->place($this->chair, $buyer ?? $this->buyer, $this->ship(), 1);
        $o->update(['status' => $status, 'delivered_at' => $status === 'delivered' ? now() : null]);

        return $o->fresh();
    }

    private function review(MarketOrder $o, array $more = [])
    {
        return $this->post("/market/orders/{$o->public_token}/review", $more + ['rating' => 5, 'body' => 'Very good chair, strong wood.']);
    }

    // ------------------------------------------------------------------ reviews

    public function test_a_buyer_whose_order_arrived_reviews_it_once(): void
    {
        $o = $this->order();

        $this->review($o)->assertSessionHasNoErrors();
        $this->review($o)->assertSessionHasErrors('order');

        $this->assertSame(1, ListingReview::count());
        $this->assertSame($this->buyer->id, ListingReview::firstOrFail()->buyer_id);
    }

    public function test_an_order_that_has_not_arrived_cannot_be_reviewed(): void
    {
        foreach (['placed', 'shipped', 'cancelled', 'not_received'] as $status) {
            $this->review($this->order($status))->assertSessionHasErrors('order');
        }

        $this->assertSame(0, ListingReview::count());
    }

    public function test_the_order_page_offers_the_form_only_when_it_can_be_used(): void
    {
        $arrived = $this->order();
        $this->get("/market/orders/{$arrived->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_review', true));

        $this->review($arrived);
        $this->get("/market/orders/{$arrived->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_review', false));

        $onTheWay = $this->order('shipped');
        $this->get("/market/orders/{$onTheWay->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_review', false));
    }

    public function test_a_guess_at_the_link_gets_nothing(): void
    {
        $this->review(new MarketOrder(['public_token' => str_repeat('a', 40)]))->assertRedirect('/');
        $this->assertSame(0, ListingReview::count());
    }

    public function test_stars_are_one_to_five_and_words_have_no_links_or_numbers(): void
    {
        $o = $this->order();

        $this->review($o, ['rating' => 0])->assertSessionHasErrors('rating');
        $this->review($o, ['rating' => 6])->assertSessionHasErrors('rating');
        $this->review($o, ['body' => 'Call me 01712345678'])->assertSessionHasErrors('order');
        $this->review($o, ['body' => 'See https://example.com'])->assertSessionHasErrors('order');
        $this->assertSame(0, ListingReview::count());

        $this->review($o, ['body' => ''])->assertSessionHasNoErrors();
        $this->assertNull(ListingReview::firstOrFail()->body, 'stars alone are fine');
    }

    public function test_photos_up_to_three_and_the_buyer_never_named(): void
    {
        $o = $this->order();
        $four = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 300, 300), range(1, 4));

        $this->review($o, ['photos' => $four])->assertSessionHasErrors('photos');

        $this->review($o, ['photos' => array_slice($four, 0, 2)])->assertSessionHasNoErrors();
        $review = ListingReview::firstOrFail();
        $this->assertSame(2, $review->photos()->count());

        $page = $this->get("/market/{$this->chair->id}")->viewData('page')['props'];
        $this->assertSame(['id', 'rating', 'body', 'verified', 'at', 'photos'], array_keys($page['reviews'][0]));
        $this->assertTrue($page['reviews'][0]['verified']);
        $this->assertCount(2, $page['reviews'][0]['photos']);
        $this->assertStringNotContainsString('Secret Buyer Name', json_encode($page['reviews']));
    }

    public function test_the_page_and_the_list_show_the_average_and_count(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $this->review($this->order(), ['rating' => 5]);
        $this->review($this->order('delivered', $other), ['rating' => 4]);

        $this->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->where('rating.average', 4.5)->where('rating.count', 2)->where('rating.stars.5', 1)->where('rating.stars.4', 1)->has('reviews', 2));
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('items.0.stars', ['average' => 4.5, 'count' => 2]));
    }

    public function test_a_product_with_no_review_has_no_average(): void
    {
        $this->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->where('rating.average', null)->where('rating.count', 0)->has('reviews', 0));
        $this->get('/market')->assertInertia(fn (Assert $p) => $p->where('items.0.stars', null));
    }

    public function test_we_can_hide_a_review_and_it_stops_counting(): void
    {
        $this->review($this->order(), ['rating' => 1]);
        $r = ListingReview::firstOrFail();

        $this->actingAs($this->staff)->put("/admin/reviews/{$r->id}/hide", ['hidden' => 1])->assertSessionHasNoErrors();

        $this->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->where('rating.count', 0)->has('reviews', 0));

        $this->actingAs($this->staff)->put("/admin/reviews/{$r->id}/hide", ['hidden' => 0]);
        $this->assertSame(1, app(Reviews::class)->summary($this->chair)['count']);
    }

    public function test_a_buyer_cannot_use_the_staff_pages(): void
    {
        $this->review($this->order());
        $r = ListingReview::firstOrFail();

        $this->actingAs($this->buyer)->put("/admin/reviews/{$r->id}/hide", ['hidden' => 1])->assertRedirect('/');
        $this->actingAs($this->buyer)->get('/admin/reviews')->assertRedirect('/');
        $this->actingAs($this->staff)->get('/admin/reviews')->assertInertia(fn (Assert $p) => $p->has('reviews', 1));
    }

    public function test_a_review_photo_is_shown_while_the_review_is_shown_to_the_buyer_and_staff_any_time(): void
    {
        $this->review($this->order(), ['photos' => [UploadedFile::fake()->image('a.jpg', 300, 300)]]);
        $review = ListingReview::firstOrFail();
        $photo = $review->photos()->firstOrFail();

        $this->get(route('market.review-photo', $photo))->assertOk();

        app(Reviews::class)->hide($review, true);
        $this->get(route('market.review-photo', $photo))->assertRedirect('/');
        $this->actingAs($this->buyer)->get(route('market.review-photo', $photo))->assertOk();
        $this->actingAs($this->staff)->get(route('market.review-photo', $photo))->assertOk();
    }

    // ------------------------------------------------------------------ more photos

    public function test_the_seller_adds_up_to_five_more_photos(): void
    {
        foreach (range(1, 5) as $i) {
            $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image("p{$i}.jpg", 300, 300)])->assertSessionHasNoErrors();
        }
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('p6.jpg', 300, 300)])->assertSessionHasErrors('photo');

        $this->assertSame(5, $this->chair->photos()->count());
        $this->assertSame('published', $this->chair->fresh()->status, 'no new review for more pictures');
    }

    public function test_the_buyer_sees_the_main_photo_first_then_the_rest(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);

        $gallery = $this->get("/market/{$this->chair->id}")->viewData('page')['props']['gallery'];

        $this->assertCount(2, $gallery);
        $this->assertStringContainsString('/market/photo/', $gallery[0]['url'].'');
    }

    public function test_the_seller_makes_another_photo_the_main_one_and_nothing_is_lost(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $extra = $this->chair->photos()->firstOrFail();
        $extraPath = $extra->path;

        $this->actingAs($this->seller)->put("/wholesale/sell/photos/{$extra->id}/main")->assertSessionHasNoErrors();

        $this->assertSame($extraPath, $this->chair->fresh()->photo_path);
        $this->assertSame('market/main.jpg', $extra->fresh()->path, 'the old main one is now an extra');
    }

    public function test_a_seller_removes_a_photo_and_its_file(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $extra = $this->chair->photos()->firstOrFail();
        $path = $extra->path;

        $this->actingAs($this->seller)->delete("/wholesale/sell/photos/{$extra->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, ListingPhoto::count());
        Storage::assertMissing($path);
    }

    public function test_another_seller_cannot_touch_my_photos(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $extra = $this->chair->photos()->firstOrFail();
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']])->fresh();

        $this->actingAs($other)->delete("/wholesale/sell/photos/{$extra->id}")->assertRedirect('/');
        $this->actingAs($other)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('b.jpg')])->assertRedirect('/');
        $this->assertSame(1, ListingPhoto::count());
    }

    public function test_an_extra_photo_is_only_served_while_the_product_is_shown(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $extra = $this->chair->photos()->firstOrFail();
        auth()->logout();

        $this->get(route('market.extra-photo', $extra))->assertOk();

        $this->chair->update(['status' => 'paused']);
        $this->get(route('market.extra-photo', $extra))->assertRedirect('/');
        $this->actingAs($this->seller)->get(route('market.extra-photo', $extra))->assertOk();
    }

    public function test_removing_a_listing_removes_its_extra_photos(): void
    {
        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->chair->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $path = $this->chair->photos()->firstOrFail()->path;

        $this->actingAs($this->seller)->delete("/wholesale/sell/{$this->chair->id}")->assertSessionHasNoErrors();

        Storage::assertMissing($path);
        $this->assertSame(0, ListingPhoto::count());
    }

    public function test_the_service_alone_makes_a_photo_main_when_there_was_none(): void
    {
        $this->chair->update(['photo_path' => null]);
        $photo = $this->chair->photos()->create(['path' => 'market/x.jpg']);

        app(ListingPhotos::class)->makeMain($photo);

        $this->assertSame('market/x.jpg', $this->chair->fresh()->photo_path);
        $this->assertSame(0, ListingPhoto::count());
    }
}
