<?php

namespace Tests\Feature\Pricing;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\CompanyRate;
use App\Models\Medicine;
use App\Models\MedicineRate;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** What customers pay, set for a whole company or for one medicine (spec ch 04, layers 2 and 3). */
class SellingRateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $shop;

    private Company $square;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 15])->id]);
        $this->square = Company::create(['name' => 'Square']);
        $this->napa = Medicine::create(['company_id' => $this->square->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'packaging_type' => 'box', 'mrp_box' => 100, 'mrp_piece' => 10, 'is_active' => true]);
    }

    private function percentFor(): float
    {
        return app(PriceResolver::class)->resolve($this->shop->fresh(), $this->napa->fresh())->percent;
    }

    public function test_a_company_rate_beats_the_tier(): void
    {
        $this->assertSame(15.0, $this->percentFor());

        $this->actingAs($this->admin)->put("/admin/selling-rates/company/{$this->square->id}", ['percent' => 20])->assertSessionHasNoErrors();

        $this->assertSame(20.0, $this->percentFor());
        $this->assertSame(PriceResolver::LAYER_COMPANY, app(PriceResolver::class)->resolve($this->shop->fresh(), $this->napa->fresh())->layer);
    }

    public function test_a_medicine_rate_beats_the_company_rate(): void
    {
        CompanyRate::create(['company_id' => $this->square->id, 'discount_percent' => 20]);

        $this->actingAs($this->admin)->put("/admin/selling-rates/medicine/{$this->napa->id}", ['percent' => 25])->assertSessionHasNoErrors();

        $this->assertSame(25.0, $this->percentFor());
    }

    public function test_a_medicine_taka_price_is_for_one_piece(): void
    {
        $this->actingAs($this->admin)->put("/admin/selling-rates/medicine/{$this->napa->id}", ['fixed_price' => 10])->assertSessionHasErrors('fixed_price');
        $this->actingAs($this->admin)->put("/admin/selling-rates/medicine/{$this->napa->id}", ['fixed_price' => 8])->assertSessionHasNoErrors();

        $this->assertSame(20.0, $this->percentFor(), 'eight taka where ten is printed is 20% off');
    }

    public function test_an_empty_value_removes_the_rate(): void
    {
        CompanyRate::create(['company_id' => $this->square->id, 'discount_percent' => 20]);
        MedicineRate::create(['medicine_id' => $this->napa->id, 'discount_percent' => 25]);

        $this->actingAs($this->admin)->put("/admin/selling-rates/medicine/{$this->napa->id}", ['percent' => ''])->assertSessionHasNoErrors();
        $this->assertSame(20.0, $this->percentFor(), 'back to the company rate');

        $this->actingAs($this->admin)->put("/admin/selling-rates/company/{$this->square->id}", ['percent' => ''])->assertSessionHasNoErrors();
        $this->assertSame(15.0, $this->percentFor(), 'back to the tier');
    }

    public function test_a_rate_that_sells_below_cost_needs_a_yes(): void
    {
        app(StockService::class)->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 5]],
        ]);

        $this->actingAs($this->admin)->put("/admin/selling-rates/company/{$this->square->id}", ['percent' => 50])->assertSessionHasErrors('loss');
        $this->assertDatabaseCount('company_rates', 0);

        $this->actingAs($this->admin)->put("/admin/selling-rates/company/{$this->square->id}", ['percent' => 50, 'force_loss' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('company_rates', 1);
    }

    public function test_the_tiers_page_lists_the_rates(): void
    {
        CompanyRate::create(['company_id' => $this->square->id, 'discount_percent' => 20]);

        $this->actingAs($this->admin)->get('/admin/tiers')->assertInertia(fn (Assert $p) => $p
            ->has('companies', 1)
            ->has('companyRates', 1)
            ->where('companyRates.0.name', 'Square')
            ->has('medicineRates', 0));
    }

    public function test_only_the_owner_can_set_selling_rates(): void
    {
        $this->actingAs($this->shop)->put("/admin/selling-rates/company/{$this->square->id}", ['percent' => 90])->assertRedirect('/');

        $this->assertDatabaseCount('company_rates', 0);
    }
}
