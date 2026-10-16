<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Http\Controllers\Admin\CourierController;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Sales\DeliveryCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 20: three layers of delivery charge, a courier list with zone rates by weight, and the reminders that keep it right. */
class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $heavy;

    private Medicine $light;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name, ?int $grams) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'form' => 'syrup', 'packaging_type' => 'bottle', 'mrp_piece' => 1000, 'is_active' => true, 'weight_g' => $grams,
        ]);
        $this->heavy = $make('Heavy', 2000);   // 2 kg a piece
        $this->light = $make('Light', null);   // no weight
    }

    private function courier(string $name, float $cod, array $rates, bool $active = true): Courier
    {
        $c = Courier::create(['name' => $name, 'cod_percent' => $cod, 'is_active' => $active]);
        foreach ($rates as $zone => [$first, $extra]) {
            $c->rates()->create(['zone' => $zone, 'first_kg' => $first, 'extra_kg' => $extra]);
        }

        return $c;
    }

    private function items(array $pairs): Collection
    {
        return collect($pairs)->map(fn ($p) => ['medicine' => $p[0], 'unit' => 'piece', 'quantity' => $p[1]]);
    }

    private function quote(array $pairs, string $district = 'Sylhet', ?string $area = null, ?float $subtotal = null, string $mode = 'delivery'): array
    {
        return app(DeliveryCharge::class)->quote($this->items($pairs), $mode, $district, $area, $subtotal ?? collect($pairs)->sum(fn ($p) => $p[0]->mrp_piece * $p[1]));
    }

    public function test_the_zone_is_own_area_inside_the_district_or_outside(): void
    {
        Setting::write('delivery.shop_district', 'Dhaka');
        Setting::write('delivery.shop_area', 'Mirpur');
        $d = app(DeliveryCharge::class);

        $this->assertSame('own', $d->zone('Dhaka', 'mirpur '));
        $this->assertSame('district', $d->zone('Dhaka', 'Uttara'));
        $this->assertSame('district', $d->zone('dhaka', null));
        $this->assertSame('outside', $d->zone('Sylhet', 'Mirpur'));
        $this->assertSame('outside', $d->zone(null, null));
    }

    public function test_with_no_weights_the_flat_area_charge_applies_and_free_over_beats_it(): void
    {
        $this->assertSame(['flat', 120.0], [$this->quote([[$this->light, 1]])['source'], $this->quote([[$this->light, 1]])['options'][0]['charge']]);
        $this->assertSame(60.0, $this->quote([[$this->light, 1]], 'Dhaka')['options'][0]['charge']);

        Setting::write('delivery.free_over', 500);
        $free = $this->quote([[$this->light, 1]]);
        $this->assertSame('free', $free['source']);
        $this->assertSame(0.0, $free['options'][0]['charge']);
    }

    public function test_collecting_from_the_shop_costs_nothing(): void
    {
        $q = $this->quote([[$this->heavy, 1]], 'Sylhet', null, null, 'collect');

        $this->assertSame('none', $q['source']);
        $this->assertSame(0.0, $q['options'][0]['charge']);
    }

    public function test_the_courier_charge_follows_the_example_in_the_spec(): void
    {
        // 3 pieces of 2 kg = 6 kg, outside the district. Speed Fast: first kilo 120, each extra kilo 20, 1% cash share on 3,000.
        $this->courier('Speed Fast', 1, ['outside' => [120, 20]]);
        $this->courier('Steadfast', 1, ['outside' => [100, 15]]);
        $this->courier('Pathao', 1, ['outside' => [130, 25]]);

        $q = $this->quote([[$this->heavy, 3]], 'Sylhet', null, 3000);

        $this->assertSame('courier', $q['source']);
        $this->assertSame(6.0, $q['weight_kg']);
        $this->assertSame(['Steadfast', 'Speed Fast', 'Pathao'], array_column($q['options'], 'name'));
        $this->assertSame([205.0, 250.0, 285.0], array_column($q['options'], 'charge'));   // 100+5*15+30, 120+5*20+30, 130+5*25+30
    }

    public function test_a_courier_only_counts_where_it_has_a_rate_and_while_it_is_on(): void
    {
        $this->courier('Local Only', 0, ['district' => [50, 10]]);
        $this->courier('Off', 0, ['outside' => [10, 1]], false);
        $this->courier('Far', 0, ['outside' => [200, 30]]);

        $outside = $this->quote([[$this->heavy, 1]], 'Sylhet');
        $this->assertSame(['Far'], array_column($outside['options'], 'name'));

        $district = $this->quote([[$this->heavy, 1]], 'Dhaka', 'Uttara');
        $this->assertSame(['Local Only'], array_column($district['options'], 'name'));

        $own = $this->quote([[$this->heavy, 1]], 'Dhaka', 'Mirpur');   // nobody serves "own", and no shop area is set
        $this->assertSame(['Local Only'], array_column($own['options'], 'name'));
    }

    public function test_if_no_courier_can_carry_it_to_that_zone_the_flat_charge_is_used(): void
    {
        $this->courier('Local Only', 0, ['district' => [50, 10]]);

        $q = $this->quote([[$this->heavy, 1]], 'Sylhet');

        $this->assertSame('flat', $q['source']);
        $this->assertSame(120.0, $q['options'][0]['charge']);
    }

    public function test_one_product_without_a_weight_puts_the_whole_order_on_the_flat_charge(): void
    {
        $this->courier('Speed Fast', 0, ['outside' => [120, 20]]);

        $q = $this->quote([[$this->heavy, 1], [$this->light, 1]]);

        $this->assertSame('flat', $q['source']);
        $this->assertNull($q['weight_kg']);
    }

    public function test_the_weight_is_rounded_the_way_the_shop_says(): void
    {
        $this->heavy->forceFill(['weight_g' => 1200])->save();
        $this->courier('Speed Fast', 0, ['outside' => [100, 50]]);

        $this->assertSame(150.0, $this->quote([[$this->heavy->fresh(), 1]])['options'][0]['charge']);   // 1.2 kg up to 2: 100 + 1*50

        Setting::write('delivery.round_mode', 'up_half');
        $this->assertSame(125.0, $this->quote([[$this->heavy->fresh(), 1]])['options'][0]['charge']);   // 1.5 kg: 100 + 0.5*50

        Setting::write('delivery.round_mode', 'exact');
        $this->assertSame(110.0, $this->quote([[$this->heavy->fresh(), 1]])['options'][0]['charge']);   // 1.2 kg: 100 + 0.2*50

        $this->heavy->forceFill(['weight_g' => 300])->save();
        $this->assertSame(100.0, $this->quote([[$this->heavy->fresh(), 1]])['options'][0]['charge']);   // under a kilo is a kilo
    }

    public function test_a_mandatory_charge_on_a_product_is_the_highest_layer_and_the_highest_one_wins(): void
    {
        $this->courier('Speed Fast', 0, ['outside' => [120, 20], 'district' => [60, 10]]);
        Setting::write('delivery.free_over', 100);
        $this->heavy->forceFill(['delivery_fixed_in' => 80, 'delivery_fixed_out' => 150])->save();
        $other = Medicine::create(['company_id' => $this->heavy->company_id, 'brand_name' => 'Big', 'packaging_type' => 'bottle', 'mrp_piece' => 50, 'is_active' => true, 'weight_g' => 500, 'delivery_fixed_out' => 300]);

        $this->assertSame(['fixed', 150.0], [$this->quote([[$this->heavy->fresh(), 1]])['source'], $this->quote([[$this->heavy->fresh(), 1]])['options'][0]['charge']]);
        $this->assertSame(80.0, $this->quote([[$this->heavy->fresh(), 1]], 'Dhaka', 'Uttara')['options'][0]['charge']);
        $this->assertSame(300.0, $this->quote([[$this->heavy->fresh(), 1], [$other, 1]])['options'][0]['charge']);
    }

    public function test_checkout_asks_for_a_courier_when_there_are_several_and_the_charge_is_never_taken_from_the_browser(): void
    {
        $fast = $this->courier('Speed Fast', 0, ['outside' => [120, 20]]);
        $cheap = $this->courier('Steadfast', 0, ['outside' => [100, 15]]);
        $this->post('/cart', ['medicine_id' => $this->heavy->id, 'unit' => 'piece', 'quantity' => 2]);

        $this->getJson('/checkout/quote?district=Sylhet&area=Zindabazar&mode=delivery')->assertOk()
            ->assertJsonPath('source', 'courier')->assertJsonPath('weight_kg', 4)->assertJsonPath('options.0.name', 'Steadfast')->assertJsonPath('options.0.charge', 145);
        $this->getJson('/checkout/quote?district=Sylhet&mode=collect')->assertJsonPath('source', 'none');

        $order = ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Sylhet', 'area' => 'Zindabazar', 'address' => 'Flat 2', 'delivery_mode' => 'delivery'];
        $this->post('/checkout', $order)->assertSessionHasErrors('order');
        $this->post('/checkout', $order + ['courier_id' => 9999])->assertSessionHasErrors('order');
        $this->assertSame(0, Invoice::count());

        $this->post('/checkout', $order + ['courier_id' => $fast->id, 'delivery_charge' => 1])->assertSessionHasNoErrors();
        $placed = Invoice::firstOrFail();
        $this->assertSame($fast->id, $placed->courier_id);
        $this->assertSame('180.00', (string) $placed->delivery_charge);   // 120 + 3 extra kilos * 20, whatever the browser sent
    }

    public function test_a_buyer_changing_the_district_gets_the_charge_worked_out_again_keeping_the_courier_when_it_still_goes_there(): void
    {
        $both = $this->courier('Both', 0, ['district' => [50, 10], 'outside' => [120, 20]]);
        $this->courier('Far', 0, ['outside' => [100, 10]]);
        $this->post('/cart', ['medicine_id' => $this->heavy->id, 'unit' => 'piece', 'quantity' => 1]);
        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Sylhet', 'area' => 'X', 'address' => 'Y', 'delivery_mode' => 'delivery', 'courier_id' => $both->id]);
        $order = Invoice::firstOrFail();
        $this->assertSame('140.00', (string) $order->delivery_charge);

        $this->put("/orders/{$order->public_token}/address", ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'Z'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame($both->id, $order->courier_id);
        $this->assertSame('60.00', (string) $order->delivery_charge);   // 50 + 1 extra kilo * 10

        $this->put("/orders/{$order->public_token}/address", ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Sylhet', 'area' => 'X', 'address' => 'Y']);
        $this->assertSame('140.00', (string) $order->fresh()->delivery_charge);
    }

    public function test_the_courier_screen_saves_rates_by_zone_and_an_empty_zone_means_it_does_not_go_there(): void
    {
        $this->actingAs($this->admin);

        $this->post('/admin/couriers', ['name' => 'Pathao', 'cod_percent' => 1.5, 'is_active' => true, 'rates' => [
            'own' => ['first_kg' => 60, 'extra_kg' => 10], 'district' => null, 'outside' => ['first_kg' => 130, 'extra_kg' => 25],
        ]])->assertSessionHasNoErrors();

        $c = Courier::firstOrFail();
        $this->assertSame(['outside', 'own'], $c->rates()->orderBy('zone')->pluck('zone')->all());
        $this->assertSame('1.50', (string) $c->cod_percent);

        $this->put("/admin/couriers/{$c->id}", ['name' => 'Pathao', 'cod_percent' => 1.5, 'is_active' => true, 'rates' => ['own' => null, 'district' => ['first_kg' => 70, 'extra_kg' => 12], 'outside' => null]])->assertSessionHasNoErrors();
        $this->assertSame(['district'], $c->rates()->pluck('zone')->all());

        $this->delete("/admin/couriers/{$c->id}");
        $this->assertSame(0, Courier::count());
    }

    public function test_the_courier_form_checks_its_numbers(): void
    {
        $this->actingAs($this->admin);
        $base = ['name' => 'X', 'cod_percent' => 1, 'is_active' => true];

        $this->post('/admin/couriers', $base + ['rates' => ['outside' => ['first_kg' => 100]]])->assertSessionHasErrors('rates.outside.extra_kg');
        $this->post('/admin/couriers', $base + ['rates' => ['outside' => ['first_kg' => -5, 'extra_kg' => 1]]])->assertSessionHasErrors('rates.outside.first_kg');
        $this->post('/admin/couriers', ['cod_percent' => 50, 'is_active' => true, 'rates' => []] + ['name' => ''])->assertSessionHasErrors(['name', 'cod_percent']);
        $this->assertSame(0, Courier::count());
    }

    public function test_the_courier_screens_are_for_the_owner_side_only(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->actingAs($staff)->get('/admin/couriers')->assertRedirect('/');
        $this->post('/admin/couriers', ['name' => 'X', 'cod_percent' => 0, 'is_active' => true, 'rates' => []])->assertRedirect('/');
        $this->assertSame(0, Courier::count());

        $noStock = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Orders only', 'permissions' => ['orders']])->id]);
        $this->actingAs($noStock)->get('/admin/weights')->assertRedirect('/');
    }

    public function test_rates_nobody_has_looked_at_for_three_months_raise_a_reminder_that_a_confirmation_clears(): void
    {
        $this->assertFalse(CourierController::isStale());   // no couriers, nothing to check

        $this->actingAs($this->admin)->post('/admin/couriers', ['name' => 'Speed Fast', 'cod_percent' => 0, 'is_active' => true, 'rates' => ['outside' => ['first_kg' => 1, 'extra_kg' => 1]]]);
        $this->assertFalse(CourierController::isStale());   // saving counts as looking

        Setting::write('delivery.rates_checked_on', now()->subDays(100)->toDateString());
        $this->assertTrue(CourierController::isStale());
        $this->get('/admin/couriers')->assertInertia(fn (Assert $p) => $p->where('stale', true));
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('alerts', fn ($a) => collect($a)->contains(fn ($x) => $x['title'] === 'Are the courier rates still correct?')));

        $this->post('/admin/couriers/confirm')->assertSessionHasNoErrors();
        $this->assertFalse(CourierController::isStale());
        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('alerts', fn ($a) => ! collect($a)->contains(fn ($x) => $x['title'] === 'Are the courier rates still correct?')));
    }

    public function test_the_dashboard_counts_medicines_without_a_weight_once_there_are_couriers(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('alerts', fn ($a) => ! collect($a)->contains(fn ($x) => $x['title'] === '{n} medicines have no weight')));

        $this->courier('Speed Fast', 0, ['outside' => [1, 1]]);
        Setting::write('delivery.rates_checked_on', today()->toDateString());

        $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('alerts', fn ($a) => collect($a)->contains(fn ($x) => $x['title'] === '{n} medicines have no weight' && $x['params']['n'] === 1)));
    }

    public function test_weights_are_set_in_bulk_with_a_filter_for_those_missing(): void
    {
        $this->actingAs($this->admin);

        $this->get('/admin/weights?missing=1')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.name', 'Light')->where('without', 1));
        $this->get('/admin/weights?q=Sq')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));

        $this->put("/admin/weights/{$this->light->id}", ['weight_g' => 450, 'fixed_in' => 60, 'fixed_out' => 120])->assertSessionHasNoErrors();
        $this->assertSame(450, $this->light->fresh()->weight_g);
        $this->assertSame('120.00', (string) $this->light->fresh()->delivery_fixed_out);

        $this->put("/admin/weights/{$this->light->id}", ['weight_g' => 450, 'fixed_in' => 200, 'fixed_out' => 100])->assertSessionHasErrors('fixed_out');
        $this->put("/admin/weights/{$this->light->id}", ['weight_g' => 0])->assertSessionHasErrors('weight_g');
        $this->put("/admin/weights/{$this->light->id}", ['weight_g' => null, 'fixed_in' => null, 'fixed_out' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->light->fresh()->weight_g);
    }

    public function test_the_rounding_rule_is_a_setting_with_three_choices(): void
    {
        $this->actingAs($this->admin);
        $base = ['orders_weekly_off' => [5], 'orders_holidays' => [], 'orders_night_from' => 22, 'orders_night_to' => 8, 'delivery_free_over' => 0, 'delivery_advance_over' => 0, 'delivery_shop_district' => 'Dhaka', 'delivery_shop_area' => 'Mirpur',
            'delivery_outside_enabled' => true, 'delivery_charge_inside' => 60, 'delivery_charge_outside' => 120, 'orders_edit_minutes' => 20, 'orders_stale_hours' => 72];

        $this->put('/admin/settings/orders', $base + ['delivery_round_mode' => 'up_half'])->assertSessionHasNoErrors();
        $this->assertSame('up_half', Setting::read('delivery.round_mode'));
        $this->assertSame('Mirpur', Setting::read('delivery.shop_area'));
        $this->put('/admin/settings/orders', $base + ['delivery_round_mode' => 'nonsense'])->assertSessionHasErrors('delivery_round_mode');
    }
}
