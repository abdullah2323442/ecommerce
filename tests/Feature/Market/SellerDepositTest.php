<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\DepositEntry;
use App\Models\Listing;
use App\Models\Notice;
use App\Models\Role;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Market\SellerDeposits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14, security deposit: the option exists, stays off until the owner turns it on, and then a seller must keep the asked amount with us before listing. */
class SellerDepositTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private Sector $wood;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood'], 'business_name' => 'Rahim Timber', 'name' => 'Rahim', 'area' => 'Mirpur', 'seller_trusted_at' => now()]);
        $this->wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => true, 'premium_over' => 5000]);
    }

    private function form(): array
    {
        return ['sector_id' => $this->wood->id, 'title' => 'Teak chair', 'description' => 'Solid teak, polished.', 'public_price' => 500, 'wholesale_price' => 450, 'min_qty' => 10, 'delivery_charge' => 100, 'stock' => 20];
    }

    private function on(float $amount = 5000): void
    {
        Setting::write('deposit.enabled', true);
        Setting::write('deposit.amount', $amount);
    }

    public function test_it_is_off_until_the_owner_turns_it_on_so_nobody_is_asked_for_money(): void
    {
        $svc = app(SellerDeposits::class);
        Setting::write('deposit.amount', 5000);

        $this->assertFalse($svc->enabled());
        $this->assertSame(0.0, $svc->requiredFor($this->seller));
        $this->actingAs($this->seller)->post('/wholesale/sell', $this->form())->assertSessionHasNoErrors();
        $this->assertSame(1, Listing::count());
    }

    public function test_a_seller_who_has_not_kept_enough_cannot_send_in_a_new_product(): void
    {
        $this->on(5000);

        $this->actingAs($this->seller)->post('/wholesale/sell', $this->form())->assertSessionHasErrors();
        $this->assertSame(0, Listing::count());

        app(SellerDeposits::class)->receive($this->seller, 3000, null, $this->admin);
        $this->post('/wholesale/sell', $this->form())->assertSessionHasErrors();

        app(SellerDeposits::class)->receive($this->seller, 2000, null, $this->admin);
        $this->post('/wholesale/sell', $this->form())->assertSessionHasNoErrors();
        $this->assertSame(1, Listing::count());
    }

    public function test_the_sell_page_says_how_much_is_asked_and_held(): void
    {
        $this->on(5000);
        app(SellerDeposits::class)->receive($this->seller, 1200, null, $this->admin);

        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('deposit.on', true)->where('deposit.required', 5000)->where('deposit.held', 1200)->where('deposit.short', 3800));
    }

    public function test_the_owner_can_ask_one_seller_for_another_amount(): void
    {
        $this->on(5000);
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($this->admin)->put("/admin/market/deposits/{$this->seller->id}/required", ['required' => 1000])->assertSessionHasNoErrors();
        $svc = app(SellerDeposits::class);
        $this->assertSame(1000.0, $svc->requiredFor($this->seller));
        $this->assertSame(5000.0, $svc->requiredFor($other));

        $this->put("/admin/market/deposits/{$this->seller->id}/required", ['required' => null]);
        $this->assertSame(5000.0, $svc->requiredFor($this->seller));
    }

    public function test_money_is_taken_in_given_back_or_deducted_with_a_trail_and_the_seller_is_told(): void
    {
        $this->actingAs($this->admin);
        $base = "/admin/market/deposits/{$this->seller->id}";

        $this->post("$base/receive", ['amount' => 5000, 'note' => 'Cash at the office'])->assertSessionHasNoErrors();
        $this->post("$base/deduct", ['amount' => 800, 'note' => 'Return not refunded to the buyer'])->assertSessionHasNoErrors();
        $this->post("$base/give-back", ['amount' => 1000])->assertSessionHasNoErrors();

        $this->assertSame(3200.0, app(SellerDeposits::class)->held($this->seller));
        $this->assertSame(['received', 'deducted', 'returned'], DepositEntry::orderBy('id')->pluck('kind')->all());
        $this->assertSame([5000.0, -800.0, -1000.0], DepositEntry::orderBy('id')->get()->map(fn ($e) => (float) $e->amount)->all());
        $this->assertSame(3, Notice::where('user_id', $this->seller->id)->where('kind', 'market_deposit')->count());
        $this->assertSame(3, ActivityLog::where('action', 'like', 'deposit.%')->count());
    }

    public function test_you_cannot_take_back_more_than_is_held_or_deduct_without_a_reason(): void
    {
        $svc = app(SellerDeposits::class);
        $svc->receive($this->seller, 1000, null, $this->admin);
        $this->actingAs($this->admin);
        $base = "/admin/market/deposits/{$this->seller->id}";

        $this->post("$base/give-back", ['amount' => 1500])->assertSessionHasErrors('deposit');
        $this->post("$base/deduct", ['amount' => 500])->assertSessionHasErrors('deposit');
        $this->post("$base/receive", ['amount' => 0])->assertSessionHasErrors('amount');
        $this->assertSame(1000.0, $svc->held($this->seller));
        $this->assertSame(1, DepositEntry::count());
    }

    public function test_only_those_who_manage_the_market_open_the_deposits(): void
    {
        $this->get('/admin/market/deposits')->assertRedirect('/login');
        $this->actingAs($this->seller)->get('/admin/market/deposits')->assertRedirect('/');
        $this->actingAs($this->seller)->post("/admin/market/deposits/{$this->seller->id}/receive", ['amount' => 100])->assertRedirect('/');
        $this->assertSame(0, DepositEntry::count());

        $reviewer = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->actingAs($reviewer)->get('/admin/market/deposits')->assertOk();
    }

    public function test_the_list_shows_sellers_with_what_they_hold_and_what_is_short(): void
    {
        $this->on(5000);
        Listing::create(['seller_id' => $this->seller->id, 'sector_id' => $this->wood->id, 'title' => 'Chair', 'public_price' => 100, 'status' => 'published', 'commission_kind' => 'percent', 'commission_value' => 2]);
        app(SellerDeposits::class)->receive($this->seller, 2000, null, $this->admin);

        $this->actingAs($this->admin)->get("/admin/market/deposits?seller={$this->seller->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/market-deposits')->where('on', true)->where('rows.0.name', 'Rahim Timber')->where('rows.0.held', 2000)->where('rows.0.short', 3000)->where('history.0.kind', 'received'));
    }
}
