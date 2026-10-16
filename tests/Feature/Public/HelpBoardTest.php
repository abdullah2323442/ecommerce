<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\HelpComment;
use App\Models\HelpPost;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tier;
use App\Models\User;
use App\Services\Help\HelpBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 19: post what you need, we approve it first, others answer, product links become cards, and nothing lingers. */
class HelpBoardTest extends TestCase
{
    use RefreshDatabase;

    private User $poster;

    private User $other;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->poster = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Rina Akter']);
        $this->other = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Karim Uddin']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $over = []): array
    {
        return array_merge(['category' => 'worker', 'title' => 'Need a page moderator', 'body' => 'I run a page with 20,000 followers and need someone to reply to comments in the evening.', 'contact' => '01799887766'], $over);
    }

    private function made(string $status = 'open', array $over = []): HelpPost
    {
        return HelpPost::create(array_merge($this->data(), ['user_id' => $this->poster->id, 'status' => $status, 'approved_at' => $status === 'open' ? now() : null, 'confirmed_at' => $status === 'open' ? now() : null], $over));
    }

    // ------------------------------------------------------------------ posting and approval

    public function test_a_signed_in_person_posts_and_it_waits_for_us_and_nobody_sees_it_yet(): void
    {
        $this->actingAs($this->poster)->post('/help', $this->data())->assertSessionHasNoErrors()->assertRedirect('/help/mine');

        $post = HelpPost::firstOrFail();
        $this->assertSame(['pending', $this->poster->id], [$post->status, $post->user_id]);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'help_new')->count());

        $this->get('/help')->assertInertia(fn (Assert $p) => $p->has('posts.data', 0));
        auth()->logout();
        $this->get("/help/{$post->id}")->assertRedirect('/');
    }

    public function test_a_visitor_cannot_post(): void
    {
        $this->post('/help', $this->data())->assertRedirect();
        $this->get('/help/new')->assertRedirect();
        $this->assertSame(0, HelpPost::count());
    }

    public function test_every_kind_of_account_may_post(): void
    {
        foreach ([AccountType::Paikari, AccountType::Wholesaler] as $type) {
            $u = User::factory()->create(['account_type' => $type]);
            $this->actingAs($u)->post('/help', $this->data(['title' => 'Need help: '.$type->value]))->assertSessionHasNoErrors();
        }
        $this->assertSame(2, HelpPost::count());
    }

    public function test_the_post_is_checked(): void
    {
        $this->actingAs($this->poster);
        $this->post('/help', $this->data(['category' => 'jobs']))->assertSessionHasErrors('category');
        $this->post('/help', $this->data(['title' => 'Hi']))->assertSessionHasErrors('title');
        $this->post('/help', $this->data(['body' => 'too short']))->assertSessionHasErrors('body');
        $this->post('/help', $this->data(['contact' => '']))->assertSessionHasErrors('contact');
        $this->assertSame(0, HelpPost::count());
    }

    public function test_we_publish_it_and_the_poster_is_told_and_everyone_can_read_it(): void
    {
        $post = $this->made('pending');

        $this->actingAs($this->staff)->post("/admin/help/{$post->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame(['open', $this->staff->id], [$post->fresh()->status, $post->fresh()->reviewed_by]);
        $this->assertSame(1, Notice::where('user_id', $this->poster->id)->where('kind', 'help_approved')->count());

        auth()->logout();
        $this->get('/help')->assertOk()->assertInertia(fn (Assert $p) => $p->has('posts.data', 1)->where('posts.data.0.title', 'Need a page moderator'));
        $this->get("/help/{$post->id}")->assertOk();
    }

    public function test_turning_a_post_down_needs_a_reason_and_the_poster_hears_it(): void
    {
        $post = $this->made('pending');

        $this->actingAs($this->staff)->post("/admin/help/{$post->id}/reject", [])->assertSessionHasErrors('note');
        $this->post("/admin/help/{$post->id}/reject", ['note' => 'This is an advertisement.'])->assertSessionHasNoErrors();

        $this->assertSame('rejected', $post->fresh()->status);
        $notice = Notice::where('user_id', $this->poster->id)->where('kind', 'help_rejected')->firstOrFail();
        $this->assertSame('This is an advertisement.', json_decode($notice->body, true)['note']);

        $this->post("/admin/help/{$post->id}/approve")->assertSessionHasErrors('post');   // already looked at
        $this->get('/help')->assertInertia(fn (Assert $p) => $p->has('posts.data', 0));
    }

    public function test_only_staff_who_manage_customers_review_posts(): void
    {
        $post = $this->made('pending');
        $noCustomers = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);

        $this->actingAs($this->poster)->post("/admin/help/{$post->id}/approve")->assertRedirect('/');
        $this->actingAs($noCustomers)->get('/admin/help')->assertRedirect('/');
        $this->assertSame('pending', $post->fresh()->status);

        $this->actingAs($this->staff)->get('/admin/help')->assertInertia(fn (Assert $p) => $p->has('pending', 1)->where('auth.helpPending', 1));
    }

    // ------------------------------------------------------------------ what is shown

    public function test_who_wrote_a_post_is_never_shown_and_the_contact_is_for_signed_in_people_only(): void
    {
        $post = $this->made();

        $page = $this->get("/help/{$post->id}");
        $page->assertInertia(fn (Assert $p) => $p->where('post.contact', null));
        foreach (['Rina Akter', 'user_id', '01799887766'] as $secret) {
            $this->assertStringNotContainsString($secret, $page->getContent(), "found {$secret}");
        }

        $this->actingAs($this->other)->get("/help/{$post->id}")->assertInertia(fn (Assert $p) => $p->where('post.contact', '01799887766')->where('post.mine', false));
        $list = $this->get('/help');
        $this->assertStringNotContainsString('Rina Akter', $list->getContent());
    }

    public function test_the_board_can_be_filtered_and_searched(): void
    {
        $this->made('open', ['title' => 'Need a page moderator']);
        $this->made('open', ['category' => 'product', 'title' => 'Looking for a blue fountain pen', 'body' => 'I cannot find a good blue fountain pen anywhere in the market.']);

        $this->get('/help?category=product')->assertInertia(fn (Assert $p) => $p->has('posts.data', 1)->where('posts.data.0.category', 'product'));
        $this->get('/help?q=moderator')->assertInertia(fn (Assert $p) => $p->has('posts.data', 1)->where('posts.data.0.title', 'Need a page moderator'));
        $this->get('/help?q=pen&category=worker')->assertInertia(fn (Assert $p) => $p->has('posts.data', 0));
        $this->get('/help?category=nonsense')->assertInertia(fn (Assert $p) => $p->has('posts.data', 2)->where('category', null));
    }

    public function test_the_owner_can_see_their_own_pending_and_closed_post_but_nobody_else_can(): void
    {
        $pending = $this->made('pending');
        $closed = $this->made('closed');

        $this->actingAs($this->poster)->get("/help/{$pending->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('post.status', 'pending')->where('post.mine', true));
        $this->get("/help/{$closed->id}")->assertOk();
        $this->actingAs($this->other)->get("/help/{$pending->id}")->assertRedirect('/');
        $this->get("/help/{$closed->id}")->assertRedirect('/');
    }

    // ------------------------------------------------------------------ the limit

    public function test_a_person_may_only_have_so_many_posts_at_once(): void
    {
        $this->made('pending');
        $this->made('open');
        $this->made('closed');   // a closed one does not count
        $this->made('open');

        $this->actingAs($this->poster)->post('/help', $this->data(['title' => 'A fourth one']))->assertSessionHasErrors('post');
        $this->assertSame(4, HelpPost::count());

        Setting::write('help.active_limit', 5);
        Setting::write('help.daily_limit', 10);
        $this->post('/help', $this->data(['title' => 'A fourth one']))->assertSessionHasNoErrors();
    }

    public function test_and_only_so_many_in_a_day(): void
    {
        $this->actingAs($this->poster);
        Setting::write('help.active_limit', 10);
        $this->post('/help', $this->data(['title' => 'First one today']))->assertSessionHasNoErrors();
        $this->post('/help', $this->data(['title' => 'Second one today']))->assertSessionHasNoErrors();
        $this->post('/help', $this->data(['title' => 'Third one today']))->assertSessionHasErrors('post');

        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->post('/help', $this->data(['title' => 'Third one tomorrow']))->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------ closing and the 30-day question

    public function test_the_owner_closes_a_solved_post_and_nobody_else_can(): void
    {
        $post = $this->made();

        $this->actingAs($this->other)->post("/help/{$post->id}/close")->assertRedirect('/');
        $this->assertSame('open', $post->fresh()->status);

        $this->actingAs($this->poster)->post("/help/{$post->id}/close")->assertSessionHasNoErrors();
        $this->assertSame(['closed', 'owner'], [$post->fresh()->status, $post->fresh()->closed_by]);
        $this->get('/help')->assertInertia(fn (Assert $p) => $p->has('posts.data', 0));
    }

    public function test_a_month_on_the_owner_is_asked_and_the_answer_decides(): void
    {
        $post = $this->made();
        $board = app(HelpBoard::class);

        Carbon::setTestNow(now()->addDays(29));
        $this->assertSame(['asked' => 0, 'closed' => 0], $board->sweep());

        Carbon::setTestNow(now()->addDays(2));   // 31 days
        $this->assertSame(['asked' => 1, 'closed' => 0], $board->sweep());
        $this->assertSame(1, Notice::where('user_id', $this->poster->id)->where('kind', 'help_still_needed')->count());
        $this->assertSame(['asked' => 0, 'closed' => 0], $board->sweep());   // not asked twice

        $this->actingAs($this->poster)->get("/help/{$post->id}")->assertInertia(fn (Assert $p) => $p->where('post.asked', true));
        $this->post("/help/{$post->id}/confirm")->assertSessionHasNoErrors();
        $this->assertNull($post->fresh()->asked_at);

        // Another month and it is asked again, from the day it was confirmed.
        Carbon::setTestNow(now()->addDays(29));
        $this->assertSame(0, $board->sweep()['asked']);
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(1, $board->sweep()['asked']);
    }

    public function test_no_answer_in_a_week_closes_it_by_itself(): void
    {
        $post = $this->made();
        $board = app(HelpBoard::class);

        Carbon::setTestNow(now()->addDays(31));
        $board->sweep();
        Carbon::setTestNow(now()->addDays(6));
        $this->assertSame(0, $board->sweep()['closed']);
        Carbon::setTestNow(now()->addDays(2));
        $this->assertSame(1, $board->sweep()['closed']);

        $this->assertSame(['closed', 'expired'], [$post->fresh()->status, $post->fresh()->closed_by]);
    }

    public function test_the_days_are_settings(): void
    {
        $this->made();
        Setting::write('help.ask_days', 10);

        Carbon::setTestNow(now()->addDays(11));
        $this->assertSame(1, app(HelpBoard::class)->sweep()['asked']);
    }

    // ------------------------------------------------------------------ comments and product cards

    public function test_a_signed_in_person_comments_and_it_shows_to_everyone(): void
    {
        $post = $this->made();

        $this->actingAs($this->other)->post("/help/{$post->id}/comments", ['comment' => 'I can help in the evenings.'])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get("/help/{$post->id}")->assertInertia(fn (Assert $p) => $p->has('comments', 1)->where('comments.0.text', 'I can help in the evenings.')->where('canComment', false));
    }

    public function test_other_links_and_phone_numbers_are_not_allowed_in_a_comment(): void
    {
        $post = $this->made();
        $this->actingAs($this->other);

        foreach (['Visit https://example.com/shop please', 'Mail me at www.shop.com', 'Call 01711 223344 now', 'Look http://127.0.0.1/medicines/1 and http://evil.test/medicines/1'] as $bad) {
            $this->post("/help/{$post->id}/comments", ['comment' => $bad])->assertSessionHasErrors('post');
        }
        $this->post("/help/{$post->id}/comments", ['comment' => ''])->assertSessionHasErrors('comment');
        $this->assertSame(0, HelpComment::count());
    }

    public function test_a_product_link_shows_as_a_card_with_the_viewers_own_price(): void
    {
        $post = $this->made();
        $this->actingAs($this->other)->post("/help/{$post->id}/comments", ['comment' => "Try this: http://localhost/medicines/{$this->napa->id}"])->assertSessionHasNoErrors();

        $card = fn () => $this->get("/help/{$post->id}")->viewData('page')['props']['comments'][0];

        // A plain buyer sees the printed price.
        $c = $card();
        $this->assertSame('Try this:', $c['text']);
        $this->assertEquals(['id' => $this->napa->id, 'gone' => false, 'name' => 'Napa', 'unit' => 'box', 'price' => 300], collect($c['cards'][0])->only(['id', 'gone', 'name', 'unit', 'price'])->all());

        // A shop sees its own rate.
        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 10])->id]);
        $this->actingAs($shop);
        $this->assertEquals(270, $card()['cards'][0]['price']);
    }

    public function test_a_relative_link_works_too_and_the_same_product_is_one_card(): void
    {
        $post = $this->made();
        $this->actingAs($this->other)->post("/help/{$post->id}/comments", ['comment' => "/medicines/{$this->napa->id} and again /medicines/{$this->napa->id}"])->assertSessionHasNoErrors();

        $c = $this->get("/help/{$post->id}")->viewData('page')['props']['comments'][0];
        $this->assertSame('and again', $c['text']);
        $this->assertCount(1, $c['cards']);
    }

    public function test_a_card_for_a_removed_product_says_so_and_one_the_viewer_may_not_see_is_not_there(): void
    {
        $post = $this->made();
        $this->actingAs($this->other)->post("/help/{$post->id}/comments", ['comment' => "/medicines/{$this->napa->id}"]);

        $this->napa->update(['is_active' => false]);
        $this->assertSame(['id' => $this->napa->id, 'gone' => true], $this->get("/help/{$post->id}")->viewData('page')['props']['comments'][0]['cards'][0]);

        $this->napa->update(['is_active' => true]);
        $wood = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);
        $this->actingAs($wood);
        $this->assertSame([], $this->get("/help/{$post->id}")->viewData('page')['props']['comments'][0]['cards']);

        $this->napa->delete();
        $this->actingAs($this->other);
        $this->assertTrue($this->get("/help/{$post->id}")->viewData('page')['props']['comments'][0]['cards'][0]['gone']);
    }

    public function test_nothing_can_be_added_to_a_closed_or_waiting_post(): void
    {
        $closed = $this->made('closed');
        $pending = $this->made('pending');
        $this->actingAs($this->other);

        $this->post("/help/{$closed->id}/comments", ['comment' => 'Hello there'])->assertRedirect('/');
        $this->post("/help/{$pending->id}/comments", ['comment' => 'Hello there'])->assertRedirect('/');
        $this->assertSame(0, HelpComment::count());
    }

    public function test_we_can_hide_a_comment(): void
    {
        $post = $this->made();
        $c = HelpComment::create(['help_post_id' => $post->id, 'user_id' => $this->other->id, 'body' => 'Spam spam spam']);

        $this->actingAs($this->staff)->put("/admin/help/comments/{$c->id}/hide", ['hidden' => true])->assertSessionHasNoErrors();
        $this->get("/help/{$post->id}")->assertInertia(fn (Assert $p) => $p->has('comments', 0));
    }

    // ------------------------------------------------------------------ reports

    public function test_enough_reports_take_a_post_down_until_we_look(): void
    {
        $post = $this->made();
        $reporters = User::factory()->count(3)->create(['account_type' => AccountType::Public]);

        foreach ($reporters as $i => $r) {
            $this->actingAs($r)->post("/help/{$post->id}/report", ['reason' => 'He ran off with the money'])->assertSessionHasNoErrors();
            $this->assertSame($i === 2, $post->fresh()->hidden);
        }

        auth()->logout();
        $this->get('/help')->assertInertia(fn (Assert $p) => $p->has('posts.data', 0));
        $this->actingAs($this->staff)->get('/admin/help')->assertInertia(fn (Assert $p) => $p->has('reported', 1)->where('reported.0.reports', 3));
        $this->assertGreaterThanOrEqual(3, Notice::where('user_id', $this->staff->id)->where('kind', 'help_reported')->count());

        $this->put("/admin/help/{$post->id}/hide", ['hidden' => false]);
        $this->assertFalse($post->fresh()->hidden);
    }

    public function test_one_person_reports_once_and_not_their_own_post(): void
    {
        $post = $this->made();

        $this->actingAs($this->other);
        $this->post("/help/{$post->id}/report", ['reason' => 'Spam']);
        $this->post("/help/{$post->id}/report", ['reason' => 'Spam again']);
        $this->assertSame(1, $post->reports()->count());

        $this->actingAs($this->poster)->post("/help/{$post->id}/report")->assertSessionHasErrors('post');
    }

    public function test_the_notice_that_this_is_not_our_responsibility_is_on_the_pages(): void
    {
        $post = $this->made();

        $this->assertStringContainsString('help/index', $this->get('/help')->getContent());
        $this->assertTrue(file_exists(resource_path('js/components/help-bits.tsx')));
        $this->assertStringContainsString('we are not responsible', file_get_contents(resource_path('js/components/help-bits.tsx')));
        $this->get("/help/{$post->id}")->assertOk();
    }
}
