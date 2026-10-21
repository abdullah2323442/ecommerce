<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Jobs\SendSms;
use App\Models\Invoice;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 29 (Phase 2): support tickets. Anyone writes (name and phone needed); staff answer; the buyer follows it from a private link. */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Hasan', 'phone' => '01712345678']);
    }

    private function body(array $over = []): array
    {
        return $over + ['name' => 'Karim', 'phone' => '+880 1812-345678', 'topic' => 'delivery', 'subject' => 'Where is my parcel', 'body' => 'It has been three days now.'];
    }

    private function open(array $over = [], ?User $as = null): SupportTicket
    {
        if ($as) {
            $this->actingAs($as);
        }
        $this->post('/support', $this->body($over))->assertSessionHasNoErrors();

        return SupportTicket::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ writing

    public function test_a_guest_can_write_with_a_name_and_a_phone_and_gets_a_private_page(): void
    {
        $this->post('/support', $this->body())->assertRedirect();

        $t = SupportTicket::firstOrFail();
        $this->assertSame('01812345678', $t->phone);
        $this->assertNull($t->user_id);
        $this->assertSame('open', $t->status);
        $this->assertSame(40, strlen($t->public_token));
        $this->assertSame(1, $t->messages()->count());
        $this->get("/support/t/{$t->public_token}")->assertInertia(fn (Assert $p) => $p->component('support/show')->where('ticket.subject', 'Where is my parcel')->has('messages', 1)->where('ticket.can_reply', true));
    }

    public function test_a_signed_in_buyer_is_tied_to_the_ticket_and_sees_a_list(): void
    {
        $t = $this->open([], $this->buyer);

        $this->assertSame($this->buyer->id, $t->user_id);
        $this->get('/support')->assertInertia(fn (Assert $p) => $p->has('tickets', 1)->where('tickets.0.number', $t->number()));
    }

    public function test_name_phone_subject_and_words_are_needed_and_limited(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([['name' => ''], ['phone' => 'abc'], ['phone' => '12345'], ['subject' => 'ab'], ['body' => 'hi'], ['body' => str_repeat('x', 2001)], ['subject' => str_repeat('s', 121)]] as $bad) {
            $r = $this->post('/support', $this->body($bad));
            $this->assertTrue(session()->has('errors'), json_encode($bad));
        }

        $this->assertSame(0, SupportTicket::count());
    }

    public function test_sending_too_many_in_an_hour_is_slowed_down(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post('/support', $this->body(['phone' => '0181234567'.$i, 'subject' => "Question $i"]));
        }

        $this->post('/support', $this->body(['phone' => '01912345670', 'subject' => 'Question 6']))->assertStatus(429);
    }

    public function test_markup_is_stripped_and_an_odd_topic_becomes_other(): void
    {
        $t = $this->open(['body' => '<script>alert(1)</script>My parcel is late', 'topic' => 'hack']);

        $this->assertStringNotContainsString('<', $t->messages()->firstOrFail()->body);
        $this->assertSame('other', $t->topic);
    }

    public function test_only_three_requests_may_be_open_at_once_for_a_phone(): void
    {
        foreach (range(1, 3) as $i) {
            $this->open(['subject' => "Question $i"]);
        }

        $this->post('/support', $this->body(['subject' => 'Question 4']))->assertSessionHasErrors('support');

        $this->assertSame(3, SupportTicket::count());
    }

    public function test_the_order_link_ties_the_ticket_to_the_order_only_when_the_buyer_holds_it(): void
    {
        $inv = Invoice::create(['number' => null, 'channel' => 'online', 'status' => 'placed', 'public_token' => str()->random(40), 'kind' => 'retail', 'ship_to' => ['phone' => '01812345678']]);

        $a = $this->open(['order_token' => $inv->public_token]);
        $this->assertSame($inv->id, $a->invoice_id);

        $b = $this->open(['order_token' => str()->random(40), 'subject' => 'Another one']);
        $this->assertNull($b->invoice_id);
        $this->assertNull($b->market_order_id);
    }

    public function test_the_form_prefills_from_the_account_and_the_order_link(): void
    {
        $tok = str()->random(40);

        $this->actingAs($this->buyer)->get("/support/new?order=$tok")->assertInertia(fn (Assert $p) => $p->where('defaults.phone', '01712345678')->where('orderToken', $tok));
        $this->get('/support/new?order=bad')->assertInertia(fn (Assert $p) => $p->where('orderToken', null));
    }

    // ------------------------------------------------------------------ the private page

    public function test_a_wrong_link_shows_nothing(): void
    {
        $this->open();

        $this->get('/support/t/'.str_repeat('a', 40))->assertRedirect();
        $this->post('/support/t/'.str_repeat('a', 40).'/reply', ['body' => 'hello'])->assertRedirect();
    }

    public function test_another_persons_ticket_is_not_in_a_list(): void
    {
        $this->open([], $this->buyer);
        $other = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($other)->get('/support')->assertInertia(fn (Assert $p) => $p->has('tickets', 0));
    }

    public function test_the_list_needs_a_sign_in(): void
    {
        $this->get('/support')->assertRedirect('/login');
    }

    // ------------------------------------------------------------------ answering

    public function test_staff_answer_and_the_ticket_moves_to_answered_and_is_taken_by_them(): void
    {
        $t = $this->open([], $this->buyer);

        $this->actingAs($this->staff)->post("/admin/support/{$t->id}/reply", ['body' => 'It is with the courier.'])->assertSessionHasNoErrors();

        $t = $t->fresh();
        $this->assertSame('answered', $t->status);
        $this->assertSame('staff', $t->last_by);
        $this->assertSame($this->staff->id, $t->assigned_to);
        $this->assertSame(2, $t->messages()->count());
    }

    public function test_an_account_holder_is_told_in_the_inbox_and_a_guest_is_told_only_if_the_owner_chose_text(): void
    {
        $withAccount = $this->open([], $this->buyer);
        $this->actingAs($this->staff)->post("/admin/support/{$withAccount->id}/reply", ['body' => 'Hello there'])->assertSessionHasNoErrors();
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'support_replied')->count());

        Queue::fake();
        auth()->logout();
        $guest = $this->open(['phone' => '01912345678', 'subject' => 'Guest question']);
        $this->actingAs($this->staff)->post("/admin/support/{$guest->id}/reply", ['body' => 'Hello guest'])->assertSessionHasNoErrors();
        Queue::assertNothingPushed();

        Setting::write('messages.channels', ['support_replied' => ['inbox', 'sms']]);
        auth()->logout();
        $guest2 = $this->open(['phone' => '01912345679', 'subject' => 'Guest question two']);
        $this->actingAs($this->staff)->post("/admin/support/{$guest2->id}/reply", ['body' => 'Hello again'])->assertSessionHasNoErrors();
        Queue::assertPushed(SendSms::class, fn ($j) => $j->phone === '01912345679' && str_contains($j->text, '/support/t/'.$guest2->public_token));
    }

    public function test_the_buyer_writes_back_and_it_goes_to_waiting_for_us(): void
    {
        $t = $this->open();
        $this->actingAs($this->staff)->post("/admin/support/{$t->id}/reply", ['body' => 'Which number?']);
        auth()->logout();

        $this->post("/support/t/{$t->public_token}/reply", ['body' => 'The one I gave.'])->assertSessionHasNoErrors();

        $t = $t->fresh();
        $this->assertSame('open', $t->status);
        $this->assertSame('buyer', $t->last_by);
        $this->assertSame(3, $t->messages()->count());
    }

    public function test_a_closed_request_cannot_be_answered_until_opened_but_the_buyer_can_reopen_it_within_two_weeks(): void
    {
        $t = $this->open();
        $this->actingAs($this->staff)->post("/admin/support/{$t->id}/close")->assertSessionHasNoErrors();
        $this->assertSame('closed', $t->fresh()->status);

        $this->post("/admin/support/{$t->id}/reply", ['body' => 'Too late'])->assertSessionHasErrors('support');

        auth()->logout();
        $this->travel(10)->days();
        $this->post("/support/t/{$t->public_token}/reply", ['body' => 'Still not solved'])->assertSessionHasNoErrors();
        $this->assertSame('open', $t->fresh()->status);
        $this->assertNull($t->fresh()->closed_at);
    }

    public function test_after_two_weeks_a_closed_request_stays_closed(): void
    {
        $t = $this->open();
        $this->actingAs($this->staff)->post("/admin/support/{$t->id}/close");
        auth()->logout();

        $this->travel(15)->days();

        $this->get("/support/t/{$t->public_token}")->assertInertia(fn (Assert $p) => $p->where('ticket.can_reply', false));
        $this->post("/support/t/{$t->public_token}/reply", ['body' => 'Hello again'])->assertSessionHasErrors('support');
        $this->assertSame('closed', $t->fresh()->status);
    }

    public function test_staff_can_open_a_closed_request_again(): void
    {
        $t = $this->open();
        $this->actingAs($this->staff)->post("/admin/support/{$t->id}/close");
        $this->post("/admin/support/{$t->id}/reopen")->assertSessionHasNoErrors();

        $this->assertSame('open', $t->fresh()->status);
        $this->assertSame(2, SupportMessage::where('ticket_id', $t->id)->where('by', 'system')->count());
    }

    // ------------------------------------------------------------------ the office

    public function test_the_list_filters_by_state_search_and_owner_and_marks_the_late_ones(): void
    {
        $a = $this->open(['subject' => 'Alpha parcel']);
        $b = $this->open(['subject' => 'Beta refund', 'phone' => '01912345678']);
        $a->forceFill(['last_message_at' => now()->subHours(30)])->save();
        $this->actingAs($this->staff)->post("/admin/support/{$b->id}/reply", ['body' => 'Looking']);

        $this->get('/admin/support')->assertInertia(fn (Assert $p) => $p->where('status', 'open')->has('tickets.data', 1)->where('tickets.data.0.late', true)->where('counts.open', 1)->where('counts.answered', 1));
        $this->get('/admin/support?status=answered')->assertInertia(fn (Assert $p) => $p->has('tickets.data', 1)->where('tickets.data.0.subject', 'Beta refund'));
        $this->get('/admin/support?status=answered&q=Beta')->assertInertia(fn (Assert $p) => $p->has('tickets.data', 1));
        $this->get('/admin/support?status=answered&q=Nothing')->assertInertia(fn (Assert $p) => $p->has('tickets.data', 0));
        $this->get('/admin/support?status=answered&mine=1')->assertInertia(fn (Assert $p) => $p->has('tickets.data', 1));
        $this->get('/admin/support?status=open&mine=1')->assertInertia(fn (Assert $p) => $p->has('tickets.data', 0));
    }

    public function test_a_search_with_percent_signs_matches_literally(): void
    {
        $this->open(['subject' => 'Plain question']);

        $this->actingAs($this->staff)->get('/admin/support?q=%25')->assertInertia(fn (Assert $p) => $p->has('tickets.data', 0));
    }

    public function test_staff_take_and_give_back_a_request(): void
    {
        $t = $this->open();

        $this->actingAs($this->staff)->post("/admin/support/{$t->id}/assign", ['take' => 1]);
        $this->assertSame($this->staff->id, $t->fresh()->assigned_to);

        $this->post("/admin/support/{$t->id}/assign", []);
        $this->assertNull($t->fresh()->assigned_to);
    }

    public function test_the_ticket_page_shows_the_thread_the_buyer_and_the_order(): void
    {
        $inv = Invoice::create(['number' => null, 'channel' => 'online', 'status' => 'placed', 'public_token' => str()->random(40), 'kind' => 'retail', 'ship_to' => []]);
        $t = $this->open(['order_token' => $inv->public_token]);

        $r = $this->actingAs($this->staff)->get("/admin/support/{$t->id}");
        $r->assertInertia(fn (Assert $p) => $p->component('admin/support/show')->where('ticket.phone', '01812345678')->where('ticket.order.url', '/admin/orders/'.$inv->id)->has('messages', 1));
    }

    public function test_only_staff_reach_the_office_pages(): void
    {
        $t = $this->open();
        $guestLink = "/admin/support/{$t->id}";

        auth()->logout();
        $this->get('/admin/support')->assertRedirect();
        $this->actingAs($this->buyer)->get('/admin/support')->assertRedirect('/');
        $this->get($guestLink)->assertRedirect('/');
        $this->post("$guestLink/reply", ['body' => 'I am not staff'])->assertRedirect('/');
        $this->post("$guestLink/close")->assertRedirect('/');
        $this->assertSame('open', $t->fresh()->status);
        $this->assertSame(1, $t->messages()->count());
    }
}
