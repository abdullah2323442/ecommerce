<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\ListingQuestion;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\User;
use App\Services\Market\ListingQuestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 23: Q&A under a seller's product; one the seller leaves too long comes to us, and counts against them. */
class ListingQuestionsTest extends TestCase
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
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber'])->fresh();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => true]);
        $this->chair = Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $wood->id, 'title' => 'Teak chair', 'public_price' => 1000, 'wholesale_price' => 800, 'min_qty' => 1, 'delivery_charge' => 100,
            'stock' => 50, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 10, 'approved_at' => now(), 'submitted_at' => now(),
        ]);
    }

    private function ask(string $text = 'Is this real teak wood?'): ListingQuestion
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/questions", ['question' => $text])->assertSessionHasNoErrors();

        return ListingQuestion::latest('id')->firstOrFail();
    }

    public function test_a_question_is_not_shown_until_it_is_answered_and_the_seller_is_told(): void
    {
        $q = $this->ask();

        $this->actingAs($this->buyer)->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->has('questions', 0)->where('can_ask', true));
        $this->assertSame(1, Notice::where('user_id', $this->seller->id)->where('kind', 'listing_question_new')->count());

        $this->actingAs($this->seller)->put("/wholesale/sell/questions/{$q->id}", ['answer' => 'Yes, 100 percent teak.'])->assertSessionHasNoErrors();

        $this->get("/market/{$this->chair->id}")->assertInertia(fn (Assert $p) => $p->has('questions', 1)->where('questions.0.answer', 'Yes, 100 percent teak.'));
    }

    public function test_the_seller_is_never_named_in_what_the_public_reads(): void
    {
        $q = $this->ask();
        app(ListingQuestions::class)->answer($q->load('listing', 'asker'), $this->seller, 'Yes it is teak.');

        $shown = app(ListingQuestions::class)->shown($this->chair);

        $this->assertSame(['id', 'question', 'answer', 'at'], array_keys($shown[0]));
    }

    public function test_the_seller_cannot_ask_about_their_own_product_and_a_guest_cannot_ask(): void
    {
        $this->actingAs($this->seller)->post("/market/{$this->chair->id}/questions", ['question' => 'Is it good wood?'])->assertSessionHasErrors('question');
        auth()->logout();
        $this->post("/market/{$this->chair->id}/questions", ['question' => 'Is it good wood?'])->assertRedirect();
        $this->assertSame(0, ListingQuestion::count());
    }

    public function test_a_question_with_a_phone_number_or_link_is_refused(): void
    {
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/questions", ['question' => 'Call me on 01712345678 please'])->assertSessionHasErrors('question');
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/questions", ['question' => 'See https://example.com now'])->assertSessionHasErrors('question');
        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/questions", ['question' => 'Short'])->assertSessionHasErrors('question');
        $this->assertSame(0, ListingQuestion::count());
    }

    public function test_only_the_owner_of_the_product_can_answer_it(): void
    {
        $q = $this->ask();
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']])->fresh();

        $this->actingAs($other)->put("/wholesale/sell/questions/{$q->id}", ['answer' => 'Not mine to answer'])->assertRedirect('/');
        $this->assertNull($q->fresh()->answered_at);
    }

    public function test_an_unanswered_question_comes_to_us_after_the_set_time_and_only_once(): void
    {
        $q = $this->ask();

        Carbon::setTestNow('2026-10-14 20:00:00');
        $this->assertSame(0, app(ListingQuestions::class)->escalate(), 'ten hours is not yet too long');

        Carbon::setTestNow('2026-10-15 11:00:00');
        $this->assertSame(1, app(ListingQuestions::class)->escalate());
        $this->assertSame(0, app(ListingQuestions::class)->escalate(), 'told once');
        $this->assertNotNull($q->fresh()->escalated_at);
        $this->assertGreaterThan(0, Notice::where('kind', 'question_new')->count());
    }

    public function test_an_answered_question_does_not_come_to_us(): void
    {
        $q = $this->ask();
        app(ListingQuestions::class)->answer($q->load('listing', 'asker'), $this->seller, 'Yes it is.');

        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->assertSame(0, app(ListingQuestions::class)->escalate());
    }

    public function test_our_staff_see_and_answer_what_came_to_us_and_the_asker_reads_it(): void
    {
        $q = $this->ask();
        Carbon::setTestNow('2026-10-16 10:00:00');
        app(ListingQuestions::class)->escalate();

        $this->actingAs($this->staff)->get('/admin/questions')->assertInertia(fn (Assert $p) => $p->has('fromSellers', 1)->where('fromSellers.0.id', $q->id));
        $this->actingAs($this->staff)->put("/admin/seller-questions/{$q->id}/answer", ['answer' => 'It is teak.'])->assertSessionHasNoErrors();

        $this->assertNotNull($q->fresh()->answered_at);
        $this->actingAs($this->staff)->get('/admin/questions')->assertInertia(fn (Assert $p) => $p->has('fromSellers', 0));
    }

    public function test_a_buyer_cannot_use_the_staff_routes(): void
    {
        $q = $this->ask();

        $this->actingAs($this->buyer)->put("/admin/seller-questions/{$q->id}/answer", ['answer' => 'Sneaky'])->assertRedirect('/');
        $this->assertNull($q->fresh()->answered_at);
    }

    public function test_slow_answers_put_the_seller_on_the_watch_list(): void
    {
        $svc = app(ListingQuestions::class);
        foreach (range(1, 3) as $i) {
            $this->ask("Is this the number {$i} chair wood?");
        }
        Carbon::setTestNow('2026-10-16 10:00:00');
        $svc->escalate();

        $this->assertSame(3, $svc->slow($this->seller));

        $this->actingAs($this->staff)->get('/admin/market/cases')->assertInertia(fn (Assert $p) => $p->where('standing.0.slow', 3)->where('standing.0.watch', true));

        Carbon::setTestNow('2027-01-20 10:00:00');
        $this->assertSame(0, $svc->slow($this->seller), 'the mark fades after 60 days');
    }

    public function test_a_hidden_question_is_not_shown(): void
    {
        $q = $this->ask();
        app(ListingQuestions::class)->answer($q->load('listing', 'asker'), $this->seller, 'Yes it is.');
        app(ListingQuestions::class)->hide($q, true);

        $this->assertSame([], app(ListingQuestions::class)->shown($this->chair));
    }

    public function test_a_hidden_listing_takes_no_questions(): void
    {
        $this->chair->update(['status' => 'paused']);

        $this->actingAs($this->buyer)->post("/market/{$this->chair->id}/questions", ['question' => 'Is this real teak wood?'])->assertRedirect('/');
    }
}
