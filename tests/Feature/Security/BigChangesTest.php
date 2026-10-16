<?php

namespace Tests\Feature\Security;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 26: big changes (prices, stock, closing accounts) need a separate confirmation, so a stolen session alone cannot make them. */
class BigChangesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $shop;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
    }

    private function confirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_closing_a_customer_account_asks_for_the_password_first(): void
    {
        $this->actingAs($this->admin)->post("/admin/customers/{$this->shop->id}/suspend", ['reason' => 'Late payment'])->assertRedirect(route('password.confirm'));
        $this->assertFalse($this->shop->fresh()->isSuspended());

        $this->actingAs($this->admin)->confirmed()->post("/admin/customers/{$this->shop->id}/suspend", ['reason' => 'Late payment'])->assertSessionHasNoErrors();
        $this->assertTrue($this->shop->fresh()->isSuspended());
    }

    public function test_opening_it_again_asks_too(): void
    {
        $this->shop->forceFill(['suspended_at' => now(), 'suspend_reason' => 'x'])->save();

        $this->actingAs($this->admin)->delete("/admin/customers/{$this->shop->id}/suspend")->assertRedirect(route('password.confirm'));
        $this->assertTrue($this->shop->fresh()->isSuspended());
    }

    public function test_a_customers_own_price_asks_for_the_password_first(): void
    {
        $this->actingAs($this->admin)->put("/admin/customers/{$this->shop->id}/rates", ['medicine_id' => $this->napa->id, 'discount_percent' => 20])->assertRedirect(route('password.confirm'));
        $this->assertSame(0, CustomerRate::count());

        $this->actingAs($this->admin)->confirmed()->put("/admin/customers/{$this->shop->id}/rates", ['medicine_id' => $this->napa->id, 'discount_percent' => 20])->assertSessionHasNoErrors();
        $this->assertSame(1, CustomerRate::count());
    }

    public function test_removing_a_customers_price_asks_too(): void
    {
        $rate = CustomerRate::create(['user_id' => $this->shop->id, 'medicine_id' => $this->napa->id, 'discount_percent' => 20]);

        $this->actingAs($this->admin)->delete("/admin/customers/{$this->shop->id}/rates/{$rate->id}")->assertRedirect(route('password.confirm'));
        $this->assertSame(1, CustomerRate::count());
    }

    public function test_a_company_or_medicine_rate_asks_for_the_password_first(): void
    {
        $this->actingAs($this->admin)->put("/admin/stock/rates/company/{$this->napa->company_id}", ['percent' => 22])->assertRedirect(route('password.confirm'));
        $this->actingAs($this->admin)->put("/admin/stock/rates/medicine/{$this->napa->id}", ['percent' => 22])->assertRedirect(route('password.confirm'));
    }

    public function test_a_stock_correction_still_asks_too(): void
    {
        $this->actingAs($this->admin)->post("/admin/stock/medicines/{$this->napa->id}/correct", ['quantity' => 5, 'reason' => 'count'])->assertRedirect(route('password.confirm'));
    }

    public function test_a_confirmation_does_not_last_forever(): void
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time() - 3 * 3600 - 60])->post("/admin/customers/{$this->shop->id}/suspend", ['reason' => 'Late'])->assertRedirect(route('password.confirm'));
        $this->assertFalse($this->shop->fresh()->isSuspended());
    }
}
