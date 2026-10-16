<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\SaleReturn;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13, 12: change the address until staff start; a plain account applies for a return and is called. */
class PublicReturnsAndAddressTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $napa;

    private User $staff;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20]],
        ]);
        $stock->setMasterSync(true);
    }

    private function order(?User $user = null, string $district = 'Dhaka'): Invoice
    {
        $user && $this->actingAs($user);
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $this->post('/checkout', ['name' => 'Rina', 'phone' => '01722222222', 'district' => $district, 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery']);

        return Invoice::latest('id')->firstOrFail();
    }

    private function finish(Invoice $order): Invoice
    {
        $this->actingAs($this->staff);
        $this->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");
        $this->post("/admin/counter/{$order->id}/finalise")->assertSessionHasNoErrors();

        return $order->fresh();
    }

    private function newAddress(array $over = []): array
    {
        return array_merge(['name' => 'Rina', 'phone' => '01733333333', 'district' => 'Sylhet', 'area' => 'Zindabazar', 'address' => 'Flat 2B'], $over);
    }

    public function test_the_address_can_change_until_staff_start_and_the_charge_follows_the_district(): void
    {
        $order = $this->order();
        $this->assertSame('60.00', (string) $order->delivery_charge);

        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_change_address', true));
        $this->put("/orders/{$order->public_token}/address", $this->newAddress())->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('Zindabazar', $order->ship_to['area']);
        $this->assertSame('01733333333', $order->ship_to['phone']);
        $this->assertSame('120.00', (string) $order->delivery_charge);

        $this->put("/orders/{$order->public_token}/address", $this->newAddress(['district' => 'Dhaka']));
        $this->assertSame('60.00', (string) $order->fresh()->delivery_charge);
    }

    public function test_the_new_address_is_checked_like_the_first(): void
    {
        $order = $this->order();

        $this->put("/orders/{$order->public_token}/address", $this->newAddress(['phone' => '99']))->assertSessionHasErrors('phone');
        $this->put("/orders/{$order->public_token}/address", $this->newAddress(['district' => 'Nowhere']))->assertSessionHasErrors('district');
        $this->put("/orders/{$order->public_token}/address", $this->newAddress(['address' => '']))->assertSessionHasErrors('address');
        $this->assertSame('Mirpur', $order->fresh()->ship_to['area']);
    }

    public function test_it_is_too_late_once_staff_have_started_and_a_collect_order_has_no_address_to_change(): void
    {
        $order = $this->order();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        auth()->logout();

        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_change_address', false));
        $this->put("/orders/{$order->public_token}/address", $this->newAddress())->assertSessionHasErrors('order');
        $this->assertSame('Mirpur', $order->fresh()->ship_to['area']);

        config(['brand.address' => 'Shop 4']);
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->post('/checkout', ['name' => 'Rina', 'phone' => '01722222222', 'district' => 'Dhaka', 'area' => 'X', 'address' => 'Y', 'delivery_mode' => 'collect']);
        $collect = Invoice::latest('id')->firstOrFail();
        $this->get("/orders/{$collect->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_change_address', false));
    }

    public function test_a_plain_account_sees_what_it_bought_and_can_ask_for_a_return(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public]);
        $order = $this->finish($this->order($user));

        $this->actingAs($user)->get('/my-orders/returns')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('paikari/returns')->where('portal.base', '/my-orders')->has('memos', 1)->where('memos.0.lines.0.returnable', true));

        $line = $order->lines()->firstOrFail();
        $this->post('/my-orders/returns', [
            'invoice_id' => $order->id, 'items' => [['line_id' => $line->id, 'unit' => 'strip', 'quantity' => 1]], 'reason_code' => 'damaged', 'reason' => 'The strip was torn.', 'resolution' => 'credit',
        ])->assertSessionHasNoErrors();

        $return = SaleReturn::firstOrFail();
        $this->assertSame('requested', $return->status);
        $this->assertSame('public', $return->kind);
        $this->assertSame($user->id, $return->customer_id);
    }

    public function test_an_approved_return_becomes_credit_that_the_next_order_uses(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public]);
        $first = $this->finish($this->order($user));
        $line = $first->lines()->firstOrFail();

        $this->actingAs($user)->post('/my-orders/returns', [
            'invoice_id' => $first->id, 'items' => [['line_id' => $line->id, 'unit' => 'strip', 'quantity' => 1]], 'reason_code' => 'damaged', 'reason' => 'Torn.', 'resolution' => 'credit',
        ]);
        $this->actingAs($this->admin)->post('/admin/returns/'.SaleReturn::firstOrFail()->id.'/approve')->assertSessionHasNoErrors();
        $this->assertEquals(100.0, (float) CreditEntry::where('user_id', $user->id)->sum('amount'));

        $second = $this->finish($this->order($user));
        $this->assertSame('100.00', (string) $second->credit_applied);
    }

    public function test_a_return_is_refused_outside_the_window_and_for_someone_elses_order(): void
    {
        $user = User::factory()->create(['account_type' => AccountType::Public]);
        $order = $this->finish($this->order($user));
        $line = $order->lines()->firstOrFail();
        $payload = ['invoice_id' => $order->id, 'items' => [['line_id' => $line->id, 'unit' => 'strip', 'quantity' => 1]], 'reason_code' => 'damaged', 'reason' => 'Torn.', 'resolution' => 'credit'];

        $order->update(['finalised_at' => now()->subDays(10)]);
        $this->actingAs($user)->post('/my-orders/returns', $payload)->assertSessionHasErrors('return');

        $order->update(['finalised_at' => now()]);
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post('/my-orders/returns', $payload)->assertRedirect('/');
        $this->assertSame(0, SaleReturn::count());
    }

    public function test_the_return_screen_needs_a_login_and_is_only_for_plain_accounts(): void
    {
        $this->get('/my-orders/returns')->assertRedirect('/login');
        $this->post('/my-orders/returns', [])->assertRedirect('/login');

        foreach ([AccountType::Paikari, AccountType::Wholesaler, AccountType::Staff] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get('/my-orders/returns')->assertRedirect('/');
        }
    }

    public function test_a_guest_is_told_how_to_ask_for_a_return_and_the_account_holder_gets_the_link(): void
    {
        $guest = $this->finish($this->order());
        auth()->logout();
        $this->get("/orders/{$guest->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.mine', false));

        $user = User::factory()->create(['account_type' => AccountType::Public]);
        $own = $this->finish($this->order($user));
        $this->actingAs($user)->get("/orders/{$own->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.mine', true));
    }
}
