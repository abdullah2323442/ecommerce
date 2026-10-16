<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 6.6 "End of day": everything unresolved is in front of the person who closes. */
class EndOfDayTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $robiul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->robiul = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Robiul']);
    }

    private function draft(User $by, int $minutesAgo = 5): Invoice
    {
        return Invoice::create(['kind' => 'retail', 'channel' => 'counter', 'status' => 'draft', 'label' => 'Customer 1', 'opened_by' => $by->id, 'opened_at' => now()->subMinutes($minutesAgo), 'last_activity_at' => now()]);
    }

    public function test_open_drafts_are_listed_by_the_person_who_opened_them(): void
    {
        $this->draft($this->robiul);
        $this->draft($this->robiul, 90);
        $this->draft($this->admin);

        $this->actingAs($this->robiul)->get('/admin/end-of-day')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/end-of-day')
            ->where('unresolved', fn ($items) => collect($items)->where('key', 'drafts')->count() === 2
                && collect($items)->firstWhere('who', 'Robiul')['count'] === 2
                && collect($items)->firstWhere('who', 'Robiul')['minutes'] >= 89));
    }

    public function test_an_empty_day_has_nothing_to_list(): void
    {
        $this->actingAs($this->robiul)->get('/admin/end-of-day')->assertInertia(fn (Assert $p) => $p->where('unresolved', []));
    }

    public function test_counter_staff_never_get_the_money_lines(): void
    {
        $paid = Invoice::create(['kind' => 'retail', 'channel' => 'counter', 'status' => 'final', 'finalised_at' => now(), 'total' => 500, 'paid_amount' => 100, 'opened_at' => now()]);

        $this->actingAs($this->robiul)->get('/admin/end-of-day')->assertInertia(fn (Assert $p) => $p->where('unresolved', fn ($items) => collect($items)->whereIn('key', ['unpaid', 'cash'])->isEmpty()));

        $this->actingAs($this->admin)->get('/admin/end-of-day')->assertInertia(fn (Assert $p) => $p->where('unresolved', fn ($items) => collect($items)->firstWhere('key', 'unpaid')['amount'] == 400
            && collect($items)->firstWhere('key', 'cash') !== null));
        $this->assertNotNull($paid->id);
    }

    public function test_the_day_page_lists_what_is_open_and_the_cash_line_goes_once_it_is_counted(): void
    {
        $this->draft($this->admin);

        $this->actingAs($this->admin)->get('/admin/day')->assertInertia(fn (Assert $p) => $p->where('isToday', true)->has('unresolved', 2));

        $this->post('/admin/day', ['day' => today()->toDateString(), 'counted_cash' => 0])->assertSessionHasNoErrors();
        $this->get('/admin/day')->assertInertia(fn (Assert $p) => $p->has('unresolved', 1));
        $this->get('/admin/day?day='.today()->subDay()->toDateString())->assertInertia(fn (Assert $p) => $p->where('isToday', false));
    }

    public function test_online_orders_waiting_and_requests_for_the_admin_are_listed_for_those_who_handle_them(): void
    {
        Invoice::create(['kind' => 'retail', 'channel' => 'online', 'status' => 'placed', 'placed_at' => now(), 'opened_at' => now()]);

        $this->actingAs($this->admin)->get('/admin/end-of-day')->assertInertia(fn (Assert $p) => $p->where('unresolved', fn ($items) => collect($items)->firstWhere('key', 'orders')['count'] === 1));

        $stockOnly = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Stock only', 'permissions' => ['stock', 'counter']])->id]);
        $this->actingAs($stockOnly)->get('/admin/end-of-day')->assertInertia(fn (Assert $p) => $p->where('unresolved', fn ($items) => collect($items)->firstWhere('key', 'orders') === null));
    }

    public function test_a_shop_or_visitor_is_sent_home(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->get('/admin/end-of-day')->assertRedirect('/');
        auth()->logout();
        $this->get('/admin/end-of-day')->assertRedirect();
    }
}
