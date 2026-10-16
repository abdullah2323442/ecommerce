<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\ProductQuestion;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 23, Q&A: buyers ask under a product, we are told, we answer, everyone reads the answer. */
class ProductQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $napa;

    private User $buyer;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_box' => 300, 'is_active' => true,
        ]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Hasan Ali']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
    }

    private function ask(string $text = 'Can I take this on an empty stomach?')
    {
        return $this->post("/medicines/{$this->napa->id}/questions", ['question' => $text]);
    }

    public function test_a_signed_in_buyer_asks_and_staff_are_told_but_it_is_not_public_yet(): void
    {
        $this->actingAs($this->buyer)->from("/medicines/{$this->napa->id}");
        $this->ask()->assertSessionHasNoErrors()->assertSessionHas('status');

        $q = ProductQuestion::firstOrFail();
        $this->assertSame([$this->napa->id, $this->buyer->id, null], [$q->medicine_id, $q->user_id, $q->answered_at]);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'question_new')->count());

        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('questions', 0)->where('myWaiting', 1));
        auth()->logout();
        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('questions', 0)->where('myWaiting', 0));
    }

    public function test_a_visitor_cannot_ask(): void
    {
        $this->ask()->assertRedirect();
        $this->assertSame(0, ProductQuestion::count());
    }

    public function test_a_shop_may_ask_too(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($shop);
        $this->ask('Is this the same as the 650 mg one?')->assertSessionHasNoErrors();
        $this->assertSame(1, ProductQuestion::count());
    }

    public function test_a_short_question_an_address_or_a_phone_number_is_refused(): void
    {
        $this->actingAs($this->buyer);

        $this->ask('Why?')->assertSessionHasErrors('question');
        $this->ask('Call me on 01711 223344 about this please')->assertSessionHasErrors('question');
        $this->ask('See https://example.com for the price')->assertSessionHasErrors('question');
        $this->ask('Mail me at www.shop.com about it')->assertSessionHasErrors('question');
        $this->assertSame(0, ProductQuestion::count());
    }

    public function test_a_buyer_asks_only_so_many_a_day(): void
    {
        $this->actingAs($this->buyer);
        for ($i = 1; $i <= 10; $i++) {
            ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => "Question number {$i} here"]);
        }

        $this->ask()->assertSessionHasErrors('question');
        $this->assertSame(10, ProductQuestion::count());
    }

    public function test_staff_answer_and_everyone_reads_it_without_the_askers_name(): void
    {
        $q = ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'Can I take this on an empty stomach?']);

        $this->actingAs($this->staff)->put("/admin/questions/{$q->id}/answer", ['answer' => 'Yes, it is fine.'])->assertSessionHasNoErrors();

        auth()->logout();
        $page = $this->get("/medicines/{$this->napa->id}");
        $page->assertInertia(fn (Assert $p) => $p->has('questions', 1)->where('questions.0.answer', 'Yes, it is fine.')->where('questions.0.question', 'Can I take this on an empty stomach?'));
        $this->assertStringNotContainsString('Hasan Ali', $page->getContent());
        $this->assertArrayNotHasKey('user_id', $page->viewData('page')['props']['questions'][0]);
    }

    public function test_the_asker_is_told_once_when_it_is_first_answered(): void
    {
        $q = ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'Can I take this on an empty stomach?']);

        $this->actingAs($this->staff)->put("/admin/questions/{$q->id}/answer", ['answer' => 'Yes, it is fine.']);
        $this->put("/admin/questions/{$q->id}/answer", ['answer' => 'Yes, it is fine, with water.']);   // an edit

        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'question_answered')->count());
        $this->assertSame("/medicines/{$this->napa->id}", json_decode(Notice::where('kind', 'question_answered')->firstOrFail()->body, true)['link']);
        $this->assertSame('Yes, it is fine, with water.', $q->fresh()->answer);
    }

    public function test_a_hidden_question_is_not_shown_and_can_be_shown_again(): void
    {
        $q = ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'Can I take this on an empty stomach?', 'answer' => 'Yes.', 'answered_at' => now()]);

        $this->actingAs($this->staff)->put("/admin/questions/{$q->id}/hide", ['hidden' => true])->assertSessionHasNoErrors();
        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('questions', 0));

        $this->put("/admin/questions/{$q->id}/hide", ['hidden' => false]);
        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('questions', 1));
    }

    public function test_the_staff_screen_lists_waiting_questions_first_and_the_menu_counts_them(): void
    {
        ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'A question that waits']);
        ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'Hidden and waiting', 'hidden' => true]);
        ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'Already answered', 'answer' => 'Yes.', 'answered_at' => now()]);

        $this->actingAs($this->staff)->get('/admin/questions')->assertInertia(fn (Assert $p) => $p->component('admin/questions')
            ->has('waiting', 1)->where('waiting.0.asker', 'Hasan Ali')->has('answered', 1)->where('auth.questions', 1));
    }

    public function test_only_staff_who_handle_orders_can_answer_and_everyone_else_is_sent_home(): void
    {
        $q = ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'A question that waits']);
        $counterOnly = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);

        $this->actingAs($counterOnly)->put("/admin/questions/{$q->id}/answer", ['answer' => 'Yes.'])->assertRedirect('/');
        $this->actingAs($this->buyer)->get('/admin/questions')->assertRedirect('/');
        $this->assertNull($q->fresh()->answered_at);
    }

    public function test_an_answer_cannot_be_empty_and_an_inactive_medicine_takes_no_questions(): void
    {
        $q = ProductQuestion::create(['medicine_id' => $this->napa->id, 'user_id' => $this->buyer->id, 'question' => 'A question that waits']);
        $this->actingAs($this->staff)->put("/admin/questions/{$q->id}/answer", ['answer' => ' '])->assertSessionHasErrors('answer');

        $this->napa->update(['is_active' => false]);
        $this->actingAs($this->buyer);
        $this->ask()->assertRedirect('/');   // an unknown page: quietly home
        $this->assertSame(1, ProductQuestion::count());
    }
}
