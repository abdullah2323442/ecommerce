<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\ListingReport;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\ListingReports;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14: someone copied a seller's product — a report option; we review and remove. */
class ListingReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $original;

    private User $copier;

    private User $reviewer;

    private Sector $wood;

    private Listing $copy;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->original = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'seller_trusted_at' => now()]);
        $this->copier = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Copy Wood', 'seller_trusted_at' => now()]);
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot']);
        Storage::put('market/a.jpg', 'same-bytes');
        Storage::put('market/b.jpg', 'same-bytes');
        Storage::put('market/c.jpg', 'other-bytes');
        $this->copy = $this->listing($this->copier, 'Teak Chair', 'market/b.jpg');
    }

    private function listing(User $seller, string $title, string $photo): Listing
    {
        return Listing::create(['seller_id' => $seller->id, 'sector_id' => $this->wood->id, 'title' => $title, 'public_price' => 1000, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(), 'photo_path' => $photo]);
    }

    public function test_a_signed_in_person_can_report_a_copy_and_we_get_a_notice(): void
    {
        $this->listing($this->original, 'Teak chair', 'market/a.jpg');

        $report = app(ListingReports::class)->report($this->copy, $this->original, 'These are my photos');

        $this->assertSame(['open', $this->copy->id], [$report->status, $report->listing_id]);
        $this->assertSame(1, Notice::where('user_id', $this->reviewer->id)->where('kind', 'listing_report')->count());
    }

    public function test_you_cannot_report_your_own_product_or_report_twice_or_without_a_reason(): void
    {
        $svc = app(ListingReports::class);
        $svc->report($this->copy, $this->original, 'Mine');

        foreach ([[$this->copy, $this->copier, 'x yz'], [$this->copy, $this->original, 'Again'], [$this->copy, User::factory()->create(), ' ']] as [$l, $u, $why]) {
            try {
                $svc->report($l, $u, $why);
                $this->fail('should have been refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_look_alikes_are_found_by_the_same_picture_or_the_same_title(): void
    {
        $samePhoto = $this->listing($this->original, 'Wooden seat', 'market/a.jpg');
        $sameTitle = $this->listing($this->original, 'teak  chair!', 'market/c.jpg');
        $this->listing($this->original, 'Garjan plank', 'market/c.jpg');   // nothing in common
        $this->listing($this->copier, 'Teak chair', 'market/b.jpg');        // the same seller's own: not a copy of theirs

        $found = app(ListingReports::class)->lookAlikes($this->copy)->keyBy('id');

        $this->assertSame([$samePhoto->id, $sameTitle->id], $found->keys()->sort()->values()->all());
        $this->assertTrue($found[$samePhoto->id]['same_photo']);
        $this->assertTrue($found[$sameTitle->id]['same_title']);
    }

    public function test_taking_it_down_removes_the_listing_and_tells_the_reporter_and_the_seller(): void
    {
        $svc = app(ListingReports::class);
        $first = $svc->report($this->copy, $this->original, 'My photos');
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $second = $svc->report($this->copy, $other, 'Copied');

        $svc->resolve($first, $this->reviewer, 'remove', 'Same photo, posted later');

        $this->assertSame(['rejected', 'removed', 'removed'], [$this->copy->fresh()->status, $first->fresh()->status, $second->fresh()->status]);
        $this->assertSame(1, Notice::where('user_id', $this->original->id)->where('kind', 'listing_report_done')->count());
        $this->assertSame(1, Notice::where('user_id', $other->id)->where('kind', 'listing_report_done')->count());
        $this->assertSame(1, Notice::where('user_id', $this->copier->id)->where('kind', 'market_penalty')->count());
        $this->assertCount(0, Listing::shown()->get());
    }

    public function test_leaving_it_up_changes_nothing_but_the_report(): void
    {
        $svc = app(ListingReports::class);
        $report = $svc->report($this->copy, $this->original, 'My photos');

        $svc->resolve($report, $this->reviewer, 'dismiss', 'The photo is a stock image');

        $this->assertSame(['published', 'dismissed'], [$this->copy->fresh()->status, $report->fresh()->status]);
        $this->assertSame(0, Notice::where('user_id', $this->copier->id)->where('kind', 'market_penalty')->count());
    }

    public function test_a_report_is_decided_once_and_needs_a_note(): void
    {
        $svc = app(ListingReports::class);
        $report = $svc->report($this->copy, $this->original, 'My photos');

        try {
            $svc->resolve($report, $this->reviewer, 'dismiss', ' ');
            $this->fail('a note is needed');
        } catch (StockException) {
        }

        $svc->resolve($report, $this->reviewer, 'dismiss', 'Fine');
        $this->expectException(StockException::class);
        $svc->resolve($report->fresh(), $this->reviewer, 'remove', 'Changed mind');
    }

    public function test_the_page_offers_it_to_everyone_signed_in_but_the_seller(): void
    {
        $id = $this->copy->id;

        $this->get("/market/$id")->assertInertia(fn (Assert $p) => $p->where('listing.can_report', false));
        $this->actingAs($this->copier)->get("/market/$id")->assertInertia(fn (Assert $p) => $p->where('listing.can_report', false));
        $this->actingAs($this->original)->get("/market/$id")->assertInertia(fn (Assert $p) => $p->where('listing.can_report', true));

        $this->actingAs($this->original)->post("/market/$id/report", ['reason' => 'My photos'])->assertSessionHasNoErrors();
        $this->actingAs($this->original)->get("/market/$id")->assertInertia(fn (Assert $p) => $p->where('listing.can_report', false));
        $this->assertSame(1, ListingReport::count());
    }

    public function test_a_guest_must_sign_in_to_report(): void
    {
        $this->post("/market/{$this->copy->id}/report", ['reason' => 'Copied'])->assertRedirect();
        $this->assertSame(0, ListingReport::count());
    }

    public function test_we_see_the_reports_with_look_alikes_and_decide(): void
    {
        $this->listing($this->original, 'Teak chair', 'market/a.jpg');
        $report = app(ListingReports::class)->report($this->copy, $this->original, 'My photos');

        $this->actingAs($this->reviewer)->get('/admin/market/reports')->assertInertia(fn (Assert $p) => $p->component('admin/market-reports')
            ->has('open', 1)->where('open.0.seller', 'Copy Wood')->has('open.0.alike', 1));

        $this->actingAs($this->reviewer)->post("/admin/market/reports/{$report->id}/resolve", ['decision' => 'remove', 'note' => 'Copied'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $this->copy->fresh()->status);
    }

    public function test_only_those_who_manage_customers_see_the_reports(): void
    {
        $this->actingAs($this->original)->get('/admin/market/reports')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->get('/admin/market/reports')->assertRedirect('/');
    }
}
