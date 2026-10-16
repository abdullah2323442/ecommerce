<?php

namespace Tests\Feature\Public;

use App\Models\Company;
use App\Models\Medicine;
use App\Services\Medicines\PriceComparison;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The price comparison states facts about identical generic + strength + form, one brand per company.
 * These tests pin the rules that stop it from misleading anyone.
 */
class PriceComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function brand(int $n, float $price, array $extra = []): Medicine
    {
        $company = Company::firstOrCreate(['name' => "Company {$n}"]);

        return Medicine::create(array_merge([
            'company_id' => $company->id, 'brand_name' => "Brand {$n}", 'generic_name' => 'Azithromycin',
            'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_piece' => $price, 'is_active' => true,
        ], $extra));
    }

    private function compare(): ?array
    {
        return app(PriceComparison::class)->for('Azithromycin', '500 mg', 'tablet');
    }

    public function test_it_needs_enough_brands_to_say_anything(): void
    {
        foreach (range(1, 5) as $n) {
            $this->brand($n, $n);
        }

        $this->assertNull($this->compare());
    }

    public function test_it_shows_the_cheapest_four_and_the_dearest_three_in_ascending_order(): void
    {
        foreach (range(1, 10) as $n) {
            $this->brand($n, $n * 10);
        }

        $result = $this->compare();

        $this->assertSame(10, $result['brands_total']);
        $this->assertSame([10.0, 20.0, 30.0, 40.0, 80.0, 90.0, 100.0], array_column($result['items'], 'price'));
        $this->assertSame(10.0, $result['min']);
        $this->assertSame(100.0, $result['max']);
        $this->assertSame(10.0, $result['spread']);
    }

    public function test_it_counts_one_brand_per_company_using_the_cheapest(): void
    {
        foreach (range(1, 6) as $n) {
            $this->brand($n, $n * 10);
        }
        // Company 1 also sells a dearer second brand: it must not count twice.
        $this->brand(1, 500, ['brand_name' => 'Brand 1 Plus']);

        $result = $this->compare();

        $this->assertSame(6, $result['brands_total']);
        $this->assertNotContains('Brand 1 Plus', array_column($result['items'], 'brand'));
    }

    public function test_it_ignores_inactive_and_unpriced_medicines(): void
    {
        foreach (range(1, 6) as $n) {
            $this->brand($n, $n * 10);
        }
        $this->brand(7, 1, ['is_active' => false]);
        $this->brand(8, 0);
        $this->brand(9, 0, ['mrp_piece' => null]);

        $this->assertSame(6, $this->compare()['brands_total']);
    }

    public function test_it_compares_only_the_same_strength_and_form(): void
    {
        foreach (range(1, 6) as $n) {
            $this->brand($n, $n * 10);
        }
        $this->brand(7, 1, ['strength' => '250 mg']);
        $this->brand(8, 1, ['form' => 'capsule']);

        $this->assertSame(6, $this->compare()['brands_total']);
    }
}
