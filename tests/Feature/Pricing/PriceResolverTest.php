<?php

namespace Tests\Feature\Pricing;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\CompanyRate;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\MedicineRate;
use App\Models\Tier;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\TierAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Worked examples from docs/overview.md §7.1 are the acceptance criteria for the pricing engine.
 */
class PriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Medicine $medicine;

    private Tier $tierB;

    private User $karim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Company K']);
        $this->medicine = Medicine::create([
            'company_id' => $this->company->id,
            'brand_name' => 'Napa',
            'packaging_type' => 'box',
            'mrp_piece' => 100,
            'is_active' => true,
        ]);
        $this->tierB = Tier::create(['name' => 'B', 'discount_percent' => 15]);
        $this->karim = User::factory()->create([
            'account_type' => AccountType::Paikari,
            'tier_id' => $this->tierB->id,
        ]);
    }

    private function resolver(): PriceResolver
    {
        return app(PriceResolver::class);
    }

    public function test_nothing_set_anywhere_uses_the_tier_rate(): void
    {
        // Case 1 from the spec: 15%.
        $result = $this->resolver()->resolve($this->karim, $this->medicine);

        $this->assertSame(15.0, $result->percent);
        $this->assertSame(PriceResolver::LAYER_TIER, $result->layer);
    }

    public function test_company_rate_overrides_the_tier(): void
    {
        // Case 2 from the spec: company set to 20% -> 20%.
        CompanyRate::create(['company_id' => $this->company->id, 'discount_percent' => 20]);

        $this->assertSame(20.0, $this->resolver()->resolve($this->karim, $this->medicine)->percent);
    }

    public function test_medicine_override_beats_the_company_rate(): void
    {
        // Case 3 from the spec: this medicine set to 12% -> 12%.
        CompanyRate::create(['company_id' => $this->company->id, 'discount_percent' => 20]);
        MedicineRate::create(['medicine_id' => $this->medicine->id, 'discount_percent' => 12]);

        $result = $this->resolver()->resolve($this->karim, $this->medicine);

        $this->assertSame(12.0, $result->percent);
        $this->assertSame(PriceResolver::LAYER_MEDICINE, $result->layer);
    }

    public function test_manual_customer_rate_beats_everything(): void
    {
        // Case 4 from the spec: manual 25% for Mr. Karim -> 25%.
        CompanyRate::create(['company_id' => $this->company->id, 'discount_percent' => 20]);
        MedicineRate::create(['medicine_id' => $this->medicine->id, 'discount_percent' => 12]);
        CustomerRate::create(['user_id' => $this->karim->id, 'medicine_id' => $this->medicine->id, 'discount_percent' => 25]);

        $result = $this->resolver()->resolve($this->karim, $this->medicine);

        $this->assertSame(25.0, $result->percent);
        $this->assertSame(PriceResolver::LAYER_MANUAL, $result->layer);
    }

    public function test_a_manual_rate_for_someone_else_does_not_apply(): void
    {
        $other = User::factory()->create(['tier_id' => $this->tierB->id]);
        CustomerRate::create(['user_id' => $other->id, 'medicine_id' => $this->medicine->id, 'discount_percent' => 50]);

        $this->assertSame(15.0, $this->resolver()->resolve($this->karim, $this->medicine)->percent);
    }

    public function test_taka_amount_is_converted_to_a_percentage_of_mrp(): void
    {
        // Spec §3.5: a 100-taka medicine at 90 taka is 10% off.
        CustomerRate::create(['user_id' => $this->karim->id, 'medicine_id' => $this->medicine->id, 'fixed_price' => 90]);

        $result = $this->resolver()->resolve($this->karim, $this->medicine);

        $this->assertSame(10.0, $result->percent);
    }

    public function test_net_price_is_mrp_minus_the_layer_discount(): void
    {
        // MRP 100, tier B 15% -> 85.00
        $this->assertSame(85.0, $this->resolver()->netPrice($this->karim, $this->medicine, 100.0));
    }

    public function test_a_tier_change_never_touches_a_manual_rate(): void
    {
        // Spec PRC-2 / G11: a manual agreement must survive a tier move.
        CustomerRate::create(['user_id' => $this->karim->id, 'medicine_id' => $this->medicine->id, 'discount_percent' => 22]);
        $tierA = Tier::create(['name' => 'A', 'discount_percent' => 10]);

        app(TierAssigner::class)->assign($this->karim, $tierA, null);

        $this->assertSame(22.0, $this->resolver()->resolve($this->karim->refresh(), $this->medicine)->percent);
        $this->assertDatabaseHas('customer_rates', ['user_id' => $this->karim->id, 'discount_percent' => 22]);
    }

    public function test_a_tier_change_takes_effect_and_is_logged(): void
    {
        $tierA = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);

        app(TierAssigner::class)->assign($this->karim, $tierA, $admin);

        $this->assertSame(10.0, $this->resolver()->resolve($this->karim->refresh(), $this->medicine)->percent);
        $this->assertDatabaseHas('tier_changes', [
            'user_id' => $this->karim->id,
            'changed_by' => $admin->id,
            'from_tier_id' => $this->tierB->id,
            'to_tier_id' => $tierA->id,
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.tier_changed', 'actor_id' => $admin->id]);
    }

    public function test_customer_without_any_rate_pays_mrp(): void
    {
        $nobody = User::factory()->create(['account_type' => AccountType::Public, 'tier_id' => null]);

        $this->assertSame(0.0, $this->resolver()->resolve($nobody, $this->medicine)->percent);
    }
}
