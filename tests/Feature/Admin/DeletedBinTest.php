<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\CustomerRate;
use App\Models\DeletedItem;
use App\Models\Expense;
use App\Models\Medicine;
use App\Models\Role;
use App\Models\Tier;
use App\Models\User;
use App\Services\Audit\Bin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 25: "I deleted something by mistake" — it stays 30 days in a Deleted bin and can be put back. */
class DeletedBinTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_deleted_tier_goes_to_the_bin_and_comes_back_with_the_same_number(): void
    {
        $tier = Tier::create(['name' => 'Gold', 'discount_percent' => 12]);

        $this->actingAs($this->admin)->delete("/admin/tiers/{$tier->id}")->assertSessionHasNoErrors();
        $this->assertNull(Tier::find($tier->id));
        $item = DeletedItem::firstOrFail();
        $this->assertSame(['tier', 'Gold', $this->admin->id], [$item->type, $item->label, $item->deleted_by]);

        $this->post("/admin/deleted/{$item->id}/restore")->assertSessionHasNoErrors();

        $back = Tier::findOrFail($tier->id);
        $this->assertSame('Gold', $back->name);
        $this->assertSame('12.00', (string) $back->discount_percent);
        $this->assertSame(0, DeletedItem::count());
    }

    public function test_a_courier_comes_back_with_its_zone_rates(): void
    {
        $courier = Courier::create(['name' => 'Steadfast', 'cod_percent' => 1, 'is_active' => true]);
        foreach (['own' => 60, 'outside' => 120] as $zone => $first) {
            $courier->rates()->create(['zone' => $zone, 'first_kg' => $first, 'extra_kg' => 20]);
        }

        $this->actingAs($this->admin)->delete("/admin/couriers/{$courier->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Courier::count());

        $this->post('/admin/deleted/'.DeletedItem::firstOrFail()->id.'/restore')->assertSessionHasNoErrors();

        $back = Courier::findOrFail($courier->id);
        $this->assertSame(['outside', 'own'], $back->rates()->orderBy('zone')->pluck('zone')->all());
        $this->assertSame('120.00', (string) $back->rates()->where('zone', 'outside')->value('first_kg'));
    }

    public function test_an_expense_a_role_and_a_manual_rate_can_be_put_back(): void
    {
        $expense = Expense::create(['spent_on' => '2026-10-01', 'category' => 'rent', 'amount' => 25000, 'created_by' => $this->admin->id]);
        $role = Role::create(['name' => 'Packer', 'permissions' => ['orders']]);
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $medicine = Medicine::create(['company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 300, 'is_active' => true]);
        $rate = CustomerRate::create(['user_id' => $shop->id, 'medicine_id' => $medicine->id, 'discount_percent' => 15]);

        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()]);
        $this->delete("/admin/stock/expenses/{$expense->id}")->assertSessionHasNoErrors();
        $this->delete("/admin/roles/{$role->id}")->assertSessionHasNoErrors();
        $this->delete("/admin/customers/{$shop->id}/rates/{$rate->id}")->assertSessionHasNoErrors();
        $this->assertSame(3, DeletedItem::count());

        foreach (DeletedItem::pluck('id') as $id) {
            $this->post("/admin/deleted/{$id}/restore")->assertSessionHasNoErrors();
        }

        $this->assertSame('25000.00', (string) Expense::findOrFail($expense->id)->amount);
        $this->assertSame(['orders'], Role::findOrFail($role->id)->permissions);
        $this->assertSame('15.00', (string) CustomerRate::findOrFail($rate->id)->discount_percent);
        $this->assertSame(0, DeletedItem::count());
    }

    public function test_putting_back_something_whose_name_was_taken_since_is_refused_and_it_stays_in_the_bin(): void
    {
        $tier = Tier::create(['name' => 'Gold', 'discount_percent' => 12]);
        $this->actingAs($this->admin)->delete("/admin/tiers/{$tier->id}");

        Role::create(['name' => 'Packer', 'permissions' => []]);
        $role = Role::where('name', 'Packer')->firstOrFail();
        $this->withSession(['auth.password_confirmed_at' => time()])->delete("/admin/roles/{$role->id}");
        Role::create(['name' => 'Packer', 'permissions' => ['orders']]);   // the name is in use again

        $item = DeletedItem::where('type', 'role')->firstOrFail();
        $this->post("/admin/deleted/{$item->id}/restore")->assertSessionHasErrors('item');
        $this->assertNotNull($item->fresh());
    }

    public function test_what_is_older_than_thirty_days_is_removed_for_good(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $old = Tier::create(['name' => 'Old', 'discount_percent' => 1]);
        $this->actingAs($this->admin)->delete("/admin/tiers/{$old->id}");

        Carbon::setTestNow('2026-10-25 10:00:00');
        $recent = Tier::create(['name' => 'Recent', 'discount_percent' => 2]);
        $this->delete("/admin/tiers/{$recent->id}");

        Carbon::setTestNow('2026-11-02 10:00:00');   // 32 days after the first, 8 after the second
        $this->assertSame(1, app(Bin::class)->purge());
        $this->assertSame(['Recent'], DeletedItem::pluck('label')->all());
    }

    public function test_the_page_lists_the_bin_with_days_left_and_is_for_the_owner_only(): void
    {
        Carbon::setTestNow('2026-10-01 10:00:00');
        $tier = Tier::create(['name' => 'Gold', 'discount_percent' => 12]);
        $this->actingAs($this->admin)->delete("/admin/tiers/{$tier->id}");
        Carbon::setTestNow('2026-10-11 10:00:00');

        $this->get('/admin/deleted')->assertInertia(fn (Assert $p) => $p->component('admin/deleted')->where('days', 30)->has('items', 1)->where('items.0.days_left', 20)->where('items.0.by', $this->admin->name));

        $cashier = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);
        $this->actingAs($cashier)->get('/admin/deleted')->assertRedirect('/');
        $this->post('/admin/deleted/'.DeletedItem::firstOrFail()->id.'/restore')->assertRedirect('/');
        $this->assertSame(1, DeletedItem::count());
    }

    public function test_a_tier_with_customers_is_still_not_deleted_and_so_nothing_is_kept(): void
    {
        $tier = Tier::create(['name' => 'Gold', 'discount_percent' => 12]);
        User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]);

        $this->actingAs($this->admin)->delete("/admin/tiers/{$tier->id}")->assertSessionHasErrors('tier');

        $this->assertNotNull(Tier::find($tier->id));
        $this->assertSame(0, DeletedItem::count());
    }
}
