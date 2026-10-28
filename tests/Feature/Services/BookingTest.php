<?php

namespace Tests\Feature\Services;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Notice;
use App\Models\ServiceBooking;
use App\Models\ServiceOffer;
use App\Models\ServiceSlot;
use App\Models\Setting;
use App\Models\User;
use App\Services\Booking\Bookings;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Item 613, service and coaching booking: a provider lists times, a buyer asks for one, the provider answers, and the place is never lost or double-sold. */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private User $buyer;

    private User $admin;

    private ServiceOffer $offer;

    private ServiceSlot $slot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->tutor = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rima Tutoring', 'phone' => '01712340001']);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Hasan', 'phone' => '01712345678']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->offer = $this->bookings()->saveOffer($this->tutor, ['title' => 'Maths lesson', 'minutes' => 60, 'price' => 500, 'place' => 'online']);
        $this->slot = ServiceSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addDays(3), 'capacity' => 1, 'booked' => 0]);
    }

    private function bookings(): Bookings
    {
        return app(Bookings::class);
    }

    private function book(?User $buyer = null, string $phone = '01812345678', ?ServiceSlot $slot = null): ServiceBooking
    {
        return $this->bookings()->book($slot ?? $this->slot, $buyer, 'Hasan', $phone, 'Please bring examples');
    }

    // ------------------------------------------------------------------ the provider's services and times

    public function test_only_a_selling_account_can_give_a_service(): void
    {
        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Staff] as $type) {
            $user = User::factory()->create(['account_type' => $type]);
            $this->actingAs($user)->get('/wholesale/sell/services')->assertRedirect();
        }

        $this->actingAs($this->buyer)->post('/wholesale/sell/services', ['title' => 'Cheat', 'minutes' => 60, 'price' => 0, 'place' => 'online'])->assertRedirect();
        $this->assertSame(1, ServiceOffer::count());
    }

    public function test_a_service_needs_a_name_a_sensible_length_a_price_and_a_place(): void
    {
        foreach ([['title' => 'ab'], ['minutes' => 5], ['minutes' => 700], ['price' => -1], ['place' => 'moon']] as $bad) {
            try {
                $this->bookings()->saveOffer($this->tutor, $bad + ['title' => 'Maths lesson', 'minutes' => 60, 'price' => 0, 'place' => 'online']);
                $this->fail('A bad service was saved.');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(1, ServiceOffer::count());
    }

    public function test_the_provider_adds_a_service_and_times_from_their_page(): void
    {
        $this->actingAs($this->tutor)->post('/wholesale/sell/services', ['title' => 'Physics lesson', 'minutes' => 90, 'price' => 700, 'place' => 'home', 'area' => 'Mirpur'])->assertSessionHasNoErrors();
        $offer = ServiceOffer::where('title', 'Physics lesson')->firstOrFail();

        $this->post("/wholesale/sell/services/{$offer->id}/slots", ['starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'capacity' => 2, 'days' => 3])->assertSessionHasNoErrors();

        $this->assertSame(3, $offer->slots()->count());
        $this->assertSame([2, 2, 2], $offer->slots()->pluck('capacity')->all());
        $this->get('/wholesale/sell/services')->assertInertia(fn (Assert $p) => $p->component('market/sell-services')->has('offers', 2)->where('confirmHours', 24));
    }

    public function test_times_must_be_in_the_future_and_the_same_time_is_not_added_twice(): void
    {
        $when = now()->addDays(5)->format('Y-m-d H:i:s');

        $this->assertSame(1, $this->bookings()->addSlots($this->tutor, $this->offer, $when, 1));
        $this->assertSame(0, $this->bookings()->addSlots($this->tutor, $this->offer, $when, 1));

        foreach ([[now()->subDay()->format('Y-m-d H:i:s'), 1, 1], [now()->addMinutes(10)->format('Y-m-d H:i:s'), 1, 1], [$when, 0, 1], [$when, 1, 99], ['not a date', 1, 1]] as [$at, $cap, $days]) {
            try {
                $this->bookings()->addSlots($this->tutor, $this->offer, $at, $cap, $days);
                $this->fail('A bad time was added.');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_nobody_changes_another_providers_service_or_times(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($other)->put("/wholesale/sell/services/{$this->offer->id}", ['title' => 'Mine now', 'minutes' => 60, 'price' => 0, 'place' => 'online'])->assertForbidden();
        $this->post("/wholesale/sell/services/{$this->offer->id}/slots", ['starts_at' => now()->addDays(4)->format('Y-m-d H:i:s'), 'capacity' => 1])->assertForbidden();
        $this->delete("/wholesale/sell/services/slots/{$this->slot->id}")->assertForbidden();
        $this->assertSame('Maths lesson', $this->offer->fresh()->title);
    }

    public function test_a_time_somebody_booked_cannot_be_removed_until_the_booking_is_gone(): void
    {
        $b = $this->book();

        $this->actingAs($this->tutor)->delete("/wholesale/sell/services/slots/{$this->slot->id}")->assertSessionHasErrors('slots');
        $this->assertNotNull($this->slot->fresh());

        $this->bookings()->decline($b, $this->tutor, null);
        $this->delete("/wholesale/sell/services/slots/{$this->slot->id}")->assertSessionHasNoErrors();
        $this->assertNull($this->slot->fresh());
    }

    // ------------------------------------------------------------------ booking

    public function test_a_booking_holds_a_place_and_tells_the_provider(): void
    {
        Notice::query()->delete();

        $b = $this->book($this->buyer);

        $this->assertSame('pending', $b->status);
        $this->assertSame(1, $this->slot->fresh()->booked);
        $this->assertEquals(500, $b->price);
        $this->assertSame(40, strlen($b->public_token));
        $this->assertSame(1, Notice::where('user_id', $this->tutor->id)->where('kind', 'booking_new')->count());
    }

    public function test_a_full_time_cannot_be_booked_and_a_bigger_one_fills_up_place_by_place(): void
    {
        $this->book(null, '01811111111');

        try {
            $this->book(null, '01822222222');
            $this->fail('A full time was booked.');
        } catch (StockException $e) {
            $this->assertStringContainsString('full', $e->getMessage());
        }

        $big = ServiceSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addDays(4), 'capacity' => 2, 'booked' => 0]);
        $this->book(null, '01833333333', $big);
        $this->book(null, '01844444444', $big);
        $this->assertSame(2, $big->fresh()->booked);
        $this->expectException(StockException::class);
        $this->book(null, '01855555555', $big);
    }

    public function test_the_same_phone_cannot_ask_for_the_same_time_twice_but_may_ask_again_after_a_decline(): void
    {
        $big = ServiceSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addDays(4), 'capacity' => 3, 'booked' => 0]);
        $first = $this->book(null, '01811111111', $big);

        try {
            $this->book(null, '+880 1811-111111', $big);
            $this->fail('Booked twice.');
        } catch (StockException $e) {
            $this->assertStringContainsString('already', $e->getMessage());
        }

        $this->bookings()->decline($first, $this->tutor, null);
        $this->book(null, '01811111111', $big);
        $this->assertSame(1, $big->fresh()->booked);
    }

    public function test_a_provider_cannot_book_their_own_service_and_bad_details_are_refused(): void
    {
        foreach ([[$this->tutor, 'Rima', '01812345678'], [null, 'H', '01812345678'], [null, 'Hasan', '12345']] as [$who, $name, $phone]) {
            try {
                $this->bookings()->book($this->slot, $who, $name, $phone, null);
                $this->fail('A bad booking was made.');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, ServiceBooking::count());
        $this->assertSame(0, $this->slot->fresh()->booked);
    }

    public function test_a_paused_hidden_or_too_close_time_cannot_be_booked(): void
    {
        $this->offer->update(['active' => false]);
        $this->assertThrows(fn () => $this->book(), StockException::class);

        $this->offer->update(['active' => true, 'admin_hidden' => true]);
        $this->assertThrows(fn () => $this->book(), StockException::class);

        $this->offer->update(['admin_hidden' => false]);
        $soon = ServiceSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addMinutes(10), 'capacity' => 1, 'booked' => 0]);
        $this->assertThrows(fn () => $this->book(null, '01812345678', $soon), StockException::class);

        $this->tutor->forceFill(['suspended_at' => now()])->save();
        $this->assertThrows(fn () => $this->book(), StockException::class);
    }

    // ------------------------------------------------------------------ the answers

    public function test_confirming_tells_the_buyer_and_keeps_the_place(): void
    {
        $b = $this->book($this->buyer);
        Notice::query()->delete();

        $this->actingAs($this->tutor)->post("/wholesale/sell/services/bookings/{$b->id}/confirm")->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $b->fresh()->status);
        $this->assertSame(1, $this->slot->fresh()->booked);
        $notice = Notice::where('user_id', $this->buyer->id)->firstOrFail();
        $this->assertSame('booking_answer', $notice->kind);
        $this->assertSame('confirmed', json_decode($notice->body, true)['state']);
    }

    public function test_declining_gives_the_place_back_and_tells_the_buyer_why(): void
    {
        $b = $this->book($this->buyer);
        Notice::query()->delete();

        $this->actingAs($this->tutor)->post("/wholesale/sell/services/bookings/{$b->id}/decline", ['reason' => 'I am away that day'])->assertSessionHasNoErrors();

        $this->assertSame('declined', $b->fresh()->status);
        $this->assertSame('I am away that day', $b->fresh()->reason);
        $this->assertSame(0, $this->slot->fresh()->booked);
        $this->assertSame('declined', json_decode(Notice::where('user_id', $this->buyer->id)->firstOrFail()->body, true)['state']);
        $this->book(null, '01899999999'); // the place can be booked again
    }

    public function test_a_booking_is_answered_once_and_only_by_its_provider(): void
    {
        $b = $this->book($this->buyer);
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($other)->post("/wholesale/sell/services/bookings/{$b->id}/confirm")->assertForbidden();
        $this->actingAs($this->tutor)->post("/wholesale/sell/services/bookings/{$b->id}/confirm")->assertSessionHasNoErrors();
        $this->post("/wholesale/sell/services/bookings/{$b->id}/confirm")->assertSessionHasErrors('booking');
    }

    public function test_a_booking_can_be_marked_done_only_when_confirmed_and_after_its_time(): void
    {
        $b = $this->book($this->buyer);
        $this->assertThrows(fn () => $this->bookings()->markDone($b, $this->tutor), StockException::class);

        $this->bookings()->confirm($b, $this->tutor);
        $this->assertThrows(fn () => $this->bookings()->markDone($b->fresh(), $this->tutor), StockException::class); // not yet its time

        $this->slot->update(['starts_at' => now()->subHour()]);
        $this->bookings()->markDone($b->fresh(), $this->tutor);
        $this->assertSame('done', $b->fresh()->status);
    }

    // ------------------------------------------------------------------ cancelling

    public function test_a_buyer_cancels_by_the_link_before_the_cutoff_and_the_place_goes_back(): void
    {
        $b = $this->book();

        $this->post("/services/booking/{$b->public_token}/cancel")->assertSessionHasNoErrors();

        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertSame(0, $this->slot->fresh()->booked);
        $this->assertSame(1, Notice::where('user_id', $this->tutor->id)->where('kind', 'booking_answer')->count());
    }

    public function test_it_is_too_late_to_cancel_inside_the_cutoff_hours(): void
    {
        Setting::write('services.cancel_hours', 12);
        $b = $this->book();
        $this->slot->update(['starts_at' => now()->addHours(5)]);

        $this->post("/services/booking/{$b->public_token}/cancel")->assertSessionHasErrors('cancel');
        $this->assertSame('pending', $b->fresh()->status);
        $this->assertSame(1, $this->slot->fresh()->booked);
        $this->get("/services/booking/{$b->public_token}")->assertInertia(fn (Assert $p) => $p->where('booking.can_cancel', false)->where('booking.cancel_hours', 12));
    }

    public function test_a_finished_booking_cannot_be_cancelled(): void
    {
        $b = $this->book();
        $this->bookings()->decline($b, $this->tutor, null);

        $this->post("/services/booking/{$b->public_token}/cancel")->assertSessionHasErrors('cancel');
        $this->assertSame(0, $this->slot->fresh()->booked); // not given back twice
    }

    // ------------------------------------------------------------------ the sweeps

    public function test_a_request_nobody_answers_is_declined_and_the_place_goes_back(): void
    {
        $old = $this->book($this->buyer);
        ServiceBooking::whereKey($old->id)->update(['created_at' => now()->subHours(25)]);
        $big = ServiceSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addDays(4), 'capacity' => 3, 'booked' => 0]);
        $fresh = $this->book(null, '01833333333', $big);
        $confirmed = $this->book(null, '01844444444', $big);
        $this->bookings()->confirm($confirmed, $this->tutor);
        ServiceBooking::whereKey($confirmed->id)->update(['created_at' => now()->subDays(3)]);

        $this->assertSame(1, $this->bookings()->expireUnanswered());

        $this->assertSame('declined', $old->fresh()->status);
        $this->assertSame('No answer in time', $old->fresh()->reason);
        $this->assertSame(0, $this->slot->fresh()->booked);
        $this->assertSame('pending', $fresh->fresh()->status);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame(0, $this->bookings()->expireUnanswered());
    }

    public function test_a_day_before_a_confirmed_booking_is_remembered_once_to_a_signed_in_buyer(): void
    {
        $b = $this->book($this->buyer);
        $this->bookings()->confirm($b, $this->tutor);
        $guest = $this->book(null, '01833333333', ServiceSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addHours(10), 'capacity' => 1, 'booked' => 0]));
        $this->bookings()->confirm($guest, $this->tutor);
        Notice::query()->delete();

        $this->assertSame(0, $this->bookings()->sendReminders()); // three days away, and a guest has no account to tell

        $this->slot->update(['starts_at' => now()->addHours(20)]);
        $this->assertSame(1, $this->bookings()->sendReminders());
        $this->assertSame(0, $this->bookings()->sendReminders());
        $this->assertSame('reminder', json_decode(Notice::where('user_id', $this->buyer->id)->firstOrFail()->body, true)['state']);
    }

    // ------------------------------------------------------------------ the shop window

    public function test_the_list_shows_services_with_a_free_time_and_the_next_one(): void
    {
        ServiceSlot::create(['offer_id' => $this->offer->id, 'starts_at' => now()->addDays(1), 'capacity' => 1, 'booked' => 0]);
        $empty = $this->bookings()->saveOffer($this->tutor, ['title' => 'No times', 'minutes' => 30, 'price' => 0, 'place' => 'provider']);
        $full = $this->bookings()->saveOffer($this->tutor, ['title' => 'Full one', 'minutes' => 30, 'price' => 0, 'place' => 'provider']);
        ServiceSlot::create(['offer_id' => $full->id, 'starts_at' => now()->addDays(2), 'capacity' => 1, 'booked' => 1]);

        $this->get('/services')->assertInertia(fn (Assert $p) => $p->component('services/index')->has('offers', 1)->where('offers.0.title', 'Maths lesson')->where('offers.0.provider', 'Rima Tutoring')->where('offers.0.price', 500)->has('offers.0.next'));
    }

    public function test_an_area_search_finds_services_there_and_online_ones_everywhere(): void
    {
        $home = $this->bookings()->saveOffer($this->tutor, ['title' => 'Home visit', 'minutes' => 30, 'price' => 0, 'place' => 'home', 'area' => 'Mirpur']);
        ServiceSlot::create(['offer_id' => $home->id, 'starts_at' => now()->addDays(2), 'capacity' => 1, 'booked' => 0]);

        $titles = fn (string $area) => collect($this->get('/services?area='.$area)->viewData('page')['props']['offers'])->pluck('title')->sort()->values()->all();

        $this->assertSame(['Home visit', 'Maths lesson'], $titles('Mirpur'));
        $this->assertSame(['Maths lesson'], $titles('Gulshan'));
    }

    public function test_hiding_a_service_removes_it_from_the_window_but_keeps_its_bookings(): void
    {
        $b = $this->book($this->buyer);

        $this->actingAs($this->admin)->put("/admin/services/{$this->offer->id}/hide", ['hidden' => true])->assertSessionHasNoErrors();

        $this->get('/services')->assertInertia(fn (Assert $p) => $p->has('offers', 0));
        $this->get("/services/{$this->offer->id}")->assertNotFound();
        $this->assertSame('pending', $b->fresh()->status);
        $this->get('/admin/services')->assertInertia(fn (Assert $p) => $p->has('offers', 1)->where('offers.0.hidden', true)->where('offers.0.open', 1));
    }

    public function test_the_service_page_lists_free_times_and_booking_from_it_leads_to_the_private_page(): void
    {
        $this->get("/services/{$this->offer->id}")->assertInertia(fn (Assert $p) => $p->component('services/show')->has('slots', 1)->where('own', false));

        $this->actingAs($this->buyer)->post("/services/{$this->offer->id}/book", ['slot_id' => $this->slot->id, 'name' => 'Hasan', 'phone' => '01712345678'])->assertSessionHasNoErrors();
        $b = ServiceBooking::firstOrFail();
        $this->assertSame($this->buyer->id, $b->buyer_id);

        $this->get("/services/booking/{$b->public_token}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('services/booking')->where('booking.status', 'pending')->where('booking.phone', null));
        $this->actingAs($this->tutor)->post("/wholesale/sell/services/bookings/{$b->id}/confirm");
        $this->get("/services/booking/{$b->public_token}")->assertInertia(fn (Assert $p) => $p->where('booking.status', 'confirmed')->where('booking.phone', '01712340001'));
    }

    public function test_a_time_from_another_service_cannot_be_booked_through_this_one(): void
    {
        $other = $this->bookings()->saveOffer($this->tutor, ['title' => 'Other', 'minutes' => 30, 'price' => 0, 'place' => 'online']);
        $theirs = ServiceSlot::create(['offer_id' => $other->id, 'starts_at' => now()->addDays(2), 'capacity' => 1, 'booked' => 0]);

        $this->post("/services/{$this->offer->id}/book", ['slot_id' => $theirs->id, 'name' => 'Hasan', 'phone' => '01712345678'])->assertSessionHasErrors('book');
        $this->assertSame(0, $theirs->fresh()->booked);
    }

    public function test_the_private_link_cannot_be_guessed(): void
    {
        // Not there for a visitor: a quiet redirect home, never a hint.
        $this->get('/services/booking/'.str_repeat('a', 40))->assertRedirect('/');
        $this->get('/services/booking/short')->assertRedirect('/');
    }

    public function test_only_those_who_manage_customers_see_the_admin_list(): void
    {
        $this->get('/admin/services')->assertRedirect('/login');
        $this->actingAs($this->buyer)->get('/admin/services')->assertRedirect('/');
        $this->actingAs($this->buyer)->put("/admin/services/{$this->offer->id}/hide", ['hidden' => true])->assertRedirect('/');
        $this->assertFalse($this->offer->fresh()->admin_hidden);
    }
}
