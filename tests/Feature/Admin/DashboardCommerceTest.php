<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The admin dashboard shows the whole shop (pharmacy, market and the rest), not only the pharmacy. */
class DashboardCommerceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function market(float $total, string $status = 'delivered', string $pay = 'cod', ?\DateTimeInterface $at = null): void
    {
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler]);
        MarketOrder::insert(['public_token' => str()->random(40), 'seller_id' => $seller->id, 'title' => 'Chair', 'unit' => 'piece', 'quantity' => 1, 'unit_price' => $total, 'seller_price' => $total, 'commission' => 0, 'delivery_charge' => 0, 'total' => $total,
            'audience' => 'public', 'status' => $status, 'ship_to' => '{}', 'pay_by' => $pay, 'paid_at' => now(), 'created_at' => $at ?? now(), 'updated_at' => now()]);
    }

    private function shop(float $total, string $status = 'final', ?\DateTimeInterface $at = null, bool $cancelled = false): void
    {
        Invoice::insert(['kind' => 'retail', 'channel' => 'online', 'status' => $status, 'total' => $total, 'payment_method' => 'cash', 'cancelled_at' => $cancelled ? now() : null, 'created_at' => $at ?? now(), 'updated_at' => now()]);
    }

    public function test_sales_from_every_shop_are_added_up_and_cancelled_ones_never_count(): void
    {
        $this->market(1000);
        $this->market(500, 'cancelled');
        $this->shop(300);
        $this->shop(900, 'final', null, true);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('dashboard')
            ->where('commerce.kpis.revenue.value', 1300)->where('commerce.kpis.orders.value', 2)->where('commerce.kpis.average.value', 650)
            ->where('commerce.sources.0.key', 'pharmacy')->where('commerce.sources.0.money', 300)->where('commerce.sources.1.money', 1000));
    }

    public function test_it_compares_with_the_thirty_days_before_and_draws_every_day(): void
    {
        $this->market(400, 'delivered', 'cod', now()->subDays(40));
        $this->market(600);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('commerce.kpis.revenue.was', 400)->where('commerce.kpis.revenue.value', 600)->has('commerce.series', 30));
    }

    public function test_an_unpaid_online_order_is_not_a_sale_yet(): void
    {
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler]);
        MarketOrder::insert(['public_token' => str()->random(40), 'seller_id' => $seller->id, 'title' => 'Plan', 'unit' => 'piece', 'quantity' => 1, 'unit_price' => 700, 'seller_price' => 700, 'commission' => 0, 'delivery_charge' => 0, 'total' => 700,
            'audience' => 'public', 'status' => 'placed', 'ship_to' => '{}', 'pay_by' => 'online', 'paid_at' => null, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('commerce.kpis.revenue.value', 0));
    }

    public function test_the_journey_and_payment_split_add_up(): void
    {
        $this->market(100, 'placed');
        $this->market(100, 'shipped', 'online');
        $this->market(100, 'delivered');
        $this->market(100, 'cancelled');

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('commerce.pipeline.waiting', 1)->where('commerce.pipeline.moving', 1)->where('commerce.pipeline.done', 1)->where('commerce.pipeline.cancelled', 1)
            ->where('commerce.payment.online', 100)->where('commerce.payment.cash', 200));
    }

    public function test_the_latest_orders_come_from_every_shop_newest_first(): void
    {
        $this->shop(250, 'final', now()->subMinutes(5));
        $this->market(900);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->has('commerce.recent', 2)->where('commerce.recent.0.source', 'market')->where('commerce.recent.1.source', 'pharmacy'));
    }

    public function test_an_empty_shop_shows_zeros_not_errors(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $p) => $p->where('commerce.kpis.revenue.value', 0)->where('commerce.kpis.average.value', 0)->has('commerce.series', 30)->where('commerce.recent', []));
    }

    public function test_the_money_figures_never_reach_anyone_but_the_office(): void
    {
        $this->market(1000);

        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($buyer)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('account')->missing('commerce'));
    }
}
