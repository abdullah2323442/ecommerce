<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Enums\Section;
use App\Models\Company;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\Tier;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        // Changes to prices and accounts ask for the password again (spec ch 26); these tests have just confirmed it.
        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->customer = User::factory()->create(['account_type' => AccountType::Paikari, 'phone' => '01711111111']);
        $company = Company::create(['name' => 'Square']);
        $this->medicine = Medicine::create([
            'company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg',
            'packaging_type' => 'box', 'mrp_piece' => 100, 'is_active' => true,
        ]);
    }

    // ---- access control -----------------------------------------------------------------

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/customers')->assertRedirect('/login');
    }

    public function test_paikari_and_wholesaler_are_silently_sent_home(): void
    {
        foreach ([AccountType::Paikari, AccountType::Wholesaler, AccountType::Public] as $type) {
            $user = User::factory()->create(['account_type' => $type]);

            $this->actingAs($user)->get('/admin/customers')->assertRedirect('/');
            $this->actingAs($user)->patch("/admin/customers/{$this->customer->id}/tier", ['tier_id' => null])->assertRedirect('/');
        }
    }

    public function test_staff_can_enter_the_panel_but_not_customer_controls(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        // A denied screen answers like a missing one: a silent redirect home, not a 403 page (VIS-4).
        $this->actingAs($staff)->get('/admin/customers')->assertRedirect('/');
        $this->actingAs($staff)->put("/admin/customers/{$this->customer->id}/rates", [
            'medicine_id' => $this->medicine->id, 'discount_percent' => 90,
        ])->assertRedirect('/');
        $this->assertDatabaseCount('customer_rates', 0);
    }

    public function test_a_suspended_admin_loses_access(): void
    {
        $this->admin->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($this->admin)->get('/admin/customers')->assertRedirect('/');
    }

    // ---- pages ---------------------------------------------------------------------------

    public function test_index_lists_customers_and_filters_by_search_and_type(): void
    {
        User::factory()->create(['account_type' => AccountType::Wholesaler, 'name' => 'Rahim Traders']);

        $this->actingAs($this->admin)->get('/admin/customers')->assertInertia(fn (Assert $page) => $page
            ->component('admin/customers/index')->has('customers.data', 2));

        $this->actingAs($this->admin)->get('/admin/customers?type=wholesaler')->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)->where('customers.data.0.name', 'Rahim Traders'));

        $this->actingAs($this->admin)->get('/admin/customers?q=01711')->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)->where('customers.data.0.id', $this->customer->id));
    }

    public function test_index_never_lists_staff_or_admin_accounts(): void
    {
        User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Staff Person']);

        $this->actingAs($this->admin)->get('/admin/customers?q=Staff')->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
    }

    public function test_show_page_loads(): void
    {
        $this->actingAs($this->admin)->get("/admin/customers/{$this->customer->id}")
            ->assertInertia(fn (Assert $page) => $page->component('admin/customers/show')->where('customer.id', $this->customer->id));
    }

    public function test_staff_account_has_no_customer_page(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);

        $this->actingAs($this->admin)->patch("/admin/customers/{$staff->id}/tier", ['tier_id' => null])->assertNotFound();
    }

    // ---- tier ----------------------------------------------------------------------------

    public function test_changing_a_tier_is_applied_and_logged(): void
    {
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);

        $this->actingAs($this->admin)->patch("/admin/customers/{$this->customer->id}/tier", ['tier_id' => $tier->id])->assertRedirect();

        $this->assertSame($tier->id, $this->customer->refresh()->tier_id);
        $this->assertDatabaseHas('tier_changes', ['user_id' => $this->customer->id, 'to_tier_id' => $tier->id, 'changed_by' => $this->admin->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.tier_changed', 'subject_id' => $this->customer->id]);
    }

    public function test_setting_the_same_tier_again_logs_nothing(): void
    {
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        $this->customer->forceFill(['tier_id' => $tier->id])->save();

        $this->actingAs($this->admin)->patch("/admin/customers/{$this->customer->id}/tier", ['tier_id' => $tier->id]);

        $this->assertDatabaseCount('tier_changes', 0);
    }

    public function test_an_unknown_tier_is_rejected(): void
    {
        $this->actingAs($this->admin)->patch("/admin/customers/{$this->customer->id}/tier", ['tier_id' => 999])->assertSessionHasErrors('tier_id');
    }

    // ---- suspension ----------------------------------------------------------------------

    public function test_suspend_requires_a_reason_and_is_logged(): void
    {
        $this->actingAs($this->admin)->post("/admin/customers/{$this->customer->id}/suspend", [])->assertSessionHasErrors('reason');

        $this->actingAs($this->admin)->post("/admin/customers/{$this->customer->id}/suspend", ['reason' => 'Late payment']);

        $this->assertTrue($this->customer->refresh()->isSuspended());
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.suspended', 'subject_id' => $this->customer->id]);
    }

    public function test_a_suspended_customer_is_locked_out_of_their_section(): void
    {
        $this->actingAs($this->admin)->post("/admin/customers/{$this->customer->id}/suspend", ['reason' => 'Late payment']);

        $this->assertFalse($this->customer->refresh()->canSee(Section::Paikari));
    }

    public function test_reactivating_restores_access_and_is_logged(): void
    {
        $this->customer->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($this->admin)->delete("/admin/customers/{$this->customer->id}/suspend");

        $this->assertFalse($this->customer->refresh()->isSuspended());
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.reactivated']);
    }

    // ---- manual rates --------------------------------------------------------------------

    public function test_a_manual_percent_rate_is_saved_and_wins_in_pricing(): void
    {
        $tier = Tier::create(['name' => 'B', 'discount_percent' => 15]);
        $this->customer->forceFill(['tier_id' => $tier->id])->save();

        $this->actingAs($this->admin)->put("/admin/customers/{$this->customer->id}/rates", [
            'medicine_id' => $this->medicine->id, 'discount_percent' => 25,
        ])->assertSessionHasNoErrors();

        $result = app(PriceResolver::class)->resolve($this->customer->refresh(), $this->medicine);
        $this->assertSame(25.0, $result->percent);
        $this->assertSame(PriceResolver::LAYER_MANUAL, $result->layer);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.manual_rate_set']);
    }

    public function test_a_taka_price_is_stored_as_taka_and_converts_to_a_percentage(): void
    {
        $this->actingAs($this->admin)->put("/admin/customers/{$this->customer->id}/rates", [
            'medicine_id' => $this->medicine->id, 'fixed_price' => 90,
        ]);

        $this->assertSame(10.0, app(PriceResolver::class)->resolve($this->customer->refresh(), $this->medicine)->percent);
    }

    public function test_a_taka_price_at_or_above_the_printed_piece_price_is_refused_with_an_explanation(): void
    {
        foreach ([100, 250] as $price) {
            $this->actingAs($this->admin)->put("/admin/customers/{$this->customer->id}/rates", ['medicine_id' => $this->medicine->id, 'fixed_price' => $price])
                ->assertSessionHasErrors('fixed_price');
        }

        $this->assertDatabaseCount('customer_rates', 0);
        $this->assertStringContainsString('100.00', session('errors')->first('fixed_price'), 'the message names the printed piece price');
    }

    public function test_saving_a_rate_twice_updates_instead_of_duplicating(): void
    {
        foreach ([20, 30] as $percent) {
            $this->actingAs($this->admin)->put("/admin/customers/{$this->customer->id}/rates", [
                'medicine_id' => $this->medicine->id, 'discount_percent' => $percent,
            ]);
        }

        $this->assertDatabaseCount('customer_rates', 1);
        $this->assertSame('30.00', CustomerRate::first()->discount_percent);
    }

    public function test_rate_validation(): void
    {
        $url = "/admin/customers/{$this->customer->id}/rates";

        $this->actingAs($this->admin)->put($url, ['medicine_id' => $this->medicine->id])->assertSessionHasErrors(['discount_percent', 'fixed_price']);
        $this->actingAs($this->admin)->put($url, ['medicine_id' => $this->medicine->id, 'discount_percent' => 101])->assertSessionHasErrors('discount_percent');
        $this->actingAs($this->admin)->put($url, ['medicine_id' => $this->medicine->id, 'discount_percent' => -1])->assertSessionHasErrors('discount_percent');
        $this->actingAs($this->admin)->put($url, ['medicine_id' => 9999, 'discount_percent' => 10])->assertSessionHasErrors('medicine_id');
        $this->actingAs($this->admin)->put($url, ['medicine_id' => $this->medicine->id, 'fixed_price' => 0])->assertSessionHasErrors('fixed_price');
    }

    public function test_a_rate_can_be_removed_but_only_through_its_own_customer(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);
        $rate = CustomerRate::create(['user_id' => $this->customer->id, 'medicine_id' => $this->medicine->id, 'discount_percent' => 20]);

        $this->actingAs($this->admin)->delete("/admin/customers/{$other->id}/rates/{$rate->id}")->assertNotFound();
        $this->assertDatabaseCount('customer_rates', 1);

        $this->actingAs($this->admin)->delete("/admin/customers/{$this->customer->id}/rates/{$rate->id}")->assertRedirect();
        $this->assertDatabaseCount('customer_rates', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.manual_rate_removed']);
    }

    public function test_a_shop_can_have_its_own_free_delivery_amount_and_clearing_it_goes_back_to_the_site_amount(): void
    {
        \App\Models\Setting::write('delivery.free_over', 6000);
        $delivery = app(\App\Services\Sales\DeliveryCharge::class);
        $this->assertSame(6000.0, $delivery->freeOver($this->customer));

        $this->actingAs($this->admin)->put("/admin/customers/{$this->customer->id}/free-delivery", ['amount' => '4000'])->assertRedirect();
        $this->assertSame(4000.0, $delivery->freeOver($this->customer->fresh()));
        $this->assertSame(6000.0, $delivery->freeOver(), 'Other shops keep the site-wide amount.');
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.free_delivery']);

        $this->actingAs($this->admin)->put("/admin/customers/{$this->customer->id}/free-delivery", ['amount' => ''])->assertRedirect();
        $this->assertSame(6000.0, $delivery->freeOver($this->customer->fresh()));
    }

    public function test_the_free_delivery_amount_is_checked_and_only_the_admin_can_set_it(): void
    {
        $this->actingAs($this->admin)->put("/admin/customers/{$this->customer->id}/free-delivery", ['amount' => '-5'])->assertSessionHasErrors('amount');
        $this->actingAs($this->customer)->put("/admin/customers/{$this->customer->id}/free-delivery", ['amount' => '10'])->assertRedirect('/');
        $this->assertNull($this->customer->fresh()->free_delivery_over);
    }
}
