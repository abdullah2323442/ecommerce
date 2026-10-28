<?php

namespace Tests\Feature\Services;

use App\Enums\AccountType;
use App\Models\RxInterest;
use App\Models\RxOffer;
use App\Models\RxRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Item 577, the prescription marketplace kept ready for later: the tables exist, and people can say they want it. */
class RxMarketTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_placeholder_page_is_open_to_everyone(): void
    {
        $this->get('/prescription-market')->assertOk()->assertInertia(fn (Assert $p) => $p->component('rx-market')->where('me', null)->where('joined', false));
    }

    public function test_a_visitor_says_they_want_it_and_the_same_number_is_kept_once(): void
    {
        $this->post('/prescription-market/interest', ['name' => 'Hasan', 'phone' => '+880 1712-345678', 'area' => 'Mirpur'])->assertSessionHasNoErrors();
        $this->post('/prescription-market/interest', ['name' => 'Again', 'phone' => '01712345678'])->assertSessionHasNoErrors();

        $this->assertSame(1, RxInterest::count());
        $this->assertSame(['Hasan', '01712345678', 'Mirpur'], [RxInterest::first()->name, RxInterest::first()->phone, RxInterest::first()->area]);
    }

    public function test_a_number_that_is_not_a_bangladesh_mobile_is_refused(): void
    {
        $this->post('/prescription-market/interest', ['phone' => '12345'])->assertSessionHasErrors('phone');
        $this->post('/prescription-market/interest', ['phone' => ''])->assertSessionHasErrors('phone');

        $this->assertSame(0, RxInterest::count());
    }

    public function test_a_signed_in_person_is_prefilled_and_told_they_are_on_the_list(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public, 'phone' => '01712345678', 'name' => 'Hasan']);

        $this->actingAs($user)->get('/prescription-market')->assertInertia(fn (Assert $p) => $p->where('me.name', 'Hasan')->where('joined', false));
        $this->post('/prescription-market/interest', ['phone' => '01712345678'])->assertSessionHasNoErrors();

        $this->assertSame($user->id, RxInterest::firstOrFail()->user_id);
        $this->get('/prescription-market')->assertInertia(fn (Assert $p) => $p->where('joined', true));
    }

    public function test_the_tables_for_requests_and_answers_are_ready_and_an_answer_is_one_for_each_pharmacy(): void
    {
        $pharmacy = User::factory()->create(['account_type' => AccountType::Paikari]);
        $request = RxRequest::create(['phone' => '01712345678', 'area' => 'Mirpur', 'note' => 'Two medicines']);
        RxOffer::create(['rx_request_id' => $request->id, 'pharmacy_id' => $pharmacy->id, 'price' => 250.5, 'note' => 'Both in stock']);

        $this->assertSame('open', $request->fresh()->status);
        $this->assertCount(1, $request->offers);
        $this->expectException(UniqueConstraintViolationException::class);
        RxOffer::create(['rx_request_id' => $request->id, 'pharmacy_id' => $pharmacy->id, 'price' => 100]);
    }

    public function test_the_owner_sees_how_many_want_it_and_nobody_else_does(): void
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        RxInterest::create(['phone' => '01711111111', 'name' => 'A']);
        RxInterest::create(['phone' => '01722222222']);

        $this->actingAs($admin)->get('/admin/rx-market')->assertInertia(fn (Assert $p) => $p->component('admin/rx-market')->where('total', 2)->has('people', 2)->where('ready.requests', 0));

        auth()->logout();
        $this->get('/admin/rx-market')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->get('/admin/rx-market')->assertRedirect('/');
    }
}
