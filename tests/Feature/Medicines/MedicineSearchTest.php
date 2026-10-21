<?php

namespace Tests\Feature\Medicines;

use App\Models\Company;
use App\Models\Medicine;
use App\Services\Medicines\BanglaTransliterator;
use App\Services\Medicines\MedicineSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicineSearchTest extends TestCase
{
    use RefreshDatabase;

    private Company $square;

    private Company $beacon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->square = Company::create(['name' => 'Square Pharmaceuticals PLC']);
        $this->beacon = Company::create(['name' => 'Beacon Pharmaceuticals PLC']);
    }

    private function med(string $brand, ?string $generic = null, array $extra = []): Medicine
    {
        return Medicine::create(array_merge([
            'company_id' => $this->square->id,
            'brand_name' => $brand,
            'generic_name' => $generic,
            'strength' => '500 mg',
            'form' => 'tablet',
            'packaging_type' => 'box',
            'mrp_piece' => 2,
            'is_active' => true,
        ], $extra));
    }

    private function names(string $q, int $limit = 30): array
    {
        return app(MedicineSearch::class)->search($q, $limit)->pluck('brand_name')->all();
    }

    public function test_finds_by_brand_name(): void
    {
        $this->med('Napa', 'Paracetamol');
        $this->med('Ace', 'Paracetamol');

        $this->assertSame(['Napa'], $this->names('napa'));
    }

    public function test_finds_by_generic_name(): void
    {
        $this->med('Napa', 'Paracetamol');
        $this->med('Seclo', 'Omeprazole');

        $this->assertSame(['Napa'], $this->names('paracetamol'));
    }

    public function test_exact_then_prefix_then_contains_ordering(): void
    {
        $this->med('Napa Extra', 'Paracetamol + Caffeine');
        $this->med('Napa', 'Paracetamol');
        $this->med('Supernapa', 'Paracetamol');

        $this->assertSame(['Napa', 'Napa Extra', 'Supernapa'], $this->names('napa'));
    }

    public function test_among_equal_name_matches_the_one_in_stock_comes_first(): void
    {
        $a = $this->med('Zolpro A');
        $b = $this->med('Zolpro B');
        $c = $this->med('Zolpro C');
        app(\App\Services\Stock\StockService::class)->receive(\App\Models\StockSource::create(['name' => 'Src', 'type' => 'company', 'default_percent' => 10]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $c->id, 'units' => 5]],
        ]);

        $names = app(MedicineSearch::class)->search('zolpro')->pluck('brand_name')->all();

        $this->assertSame('Zolpro C', $names[0], 'the medicine we hold is listed first');
        $this->assertSame(['Zolpro C', 'Zolpro A', 'Zolpro B'], $names, 'the others keep their usual order');
    }

    public function test_tolerates_a_misspelling(): void
    {
        $this->med('Napa', 'Paracetamol');
        $this->med('Seclo', 'Omeprazole');

        $this->assertContains('Napa', $this->names('nappa'));
        $this->assertContains('Seclo', $this->names('seklo'));
    }

    public function test_bengali_script_finds_the_latin_name(): void
    {
        $this->med('Napa', 'Paracetamol');
        $this->med('Seclo', 'Omeprazole');

        $this->assertContains('Napa', $this->names('নাপা'));
    }

    public function test_transliteration_basics(): void
    {
        $this->assertSame('napa', BanglaTransliterator::toLatin('নাপা'));
        $this->assertTrue(BanglaTransliterator::containsBangla('নাপা'));
        $this->assertFalse(BanglaTransliterator::containsBangla('napa'));
    }

    public function test_inactive_medicines_are_hidden_from_new_searches(): void
    {
        $this->med('Oldmed', 'Foo', ['is_active' => false]);

        $this->assertSame([], $this->names('oldmed'));
    }

    public function test_short_queries_return_nothing(): void
    {
        $this->med('Napa');

        $this->assertSame([], $this->names('n'));
    }

    public function test_like_wildcards_in_the_query_are_not_treated_as_patterns(): void
    {
        $this->med('Napa');

        $this->assertSame([], $this->names('%%'));
    }

    public function test_alternatives_share_generic_strength_and_form_cheapest_first(): void
    {
        $napa = $this->med('Napa', 'Paracetamol', ['mrp_piece' => 1.2]);
        $this->med('Ace', 'Paracetamol', ['company_id' => $this->beacon->id, 'mrp_piece' => 1.0]);
        $this->med('Fast', 'Paracetamol', ['mrp_piece' => 2.5]);
        $this->med('Ace 100', 'Paracetamol', ['strength' => '100 mg']);
        $this->med('Seclo', 'Omeprazole');

        $alt = app(MedicineSearch::class)->alternatives($napa)->pluck('brand_name')->all();

        $this->assertSame(['Ace', 'Fast'], $alt);
    }

    public function test_suggest_endpoint_never_exposes_stock_or_internal_fields(): void
    {
        $this->med('Napa', 'Paracetamol');

        $json = $this->getJson('/medicines/suggest?q=napa')->assertOk()->json('data.0');

        $this->assertSame('Napa', $json['brand_name']);
        foreach (['stock', 'quantity', 'purchase_price', 'legacy_id', 'source_url'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $json);
        }
    }

    public function test_suggest_requires_a_query(): void
    {
        $this->getJson('/medicines/suggest')->assertStatus(422);
    }

    public function test_search_is_open_to_guests(): void
    {
        $this->med('Napa');

        $this->getJson('/medicines/suggest?q=napa')->assertOk();
    }
}
