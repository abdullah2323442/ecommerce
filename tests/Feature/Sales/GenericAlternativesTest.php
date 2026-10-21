<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\MedicineRate;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\OfferProducts;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 11 and 6.10: a shop searching a brand also sees other brands of the same generic that are offered or discounted; in-stock medicines come first. */
class GenericAlternativesTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'categories' => ['medicine'], 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 0])->id]);
        $this->company = Company::create(['name' => 'Square']);
    }

    private function medicine(string $name, ?string $generic, int $boxes = 10, array $more = []): Medicine
    {
        $m = Medicine::create($more + [
            'company_id' => $this->company->id, 'brand_name' => $name, 'generic_name' => $generic, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);

        if ($boxes > 0) {
            $stock = app(StockService::class);
            $stock->receive(StockSource::create(['name' => 'S'.$name, 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $m->id, 'units' => $boxes]]]);
            $stock->setMasterSync(true);
        }

        return $m;
    }

    private function search(string $q): array
    {
        return $this->actingAs($this->shop)->get('/paikari/search?q='.urlencode($q))->assertOk()->json('results');
    }

    public function test_another_brand_of_the_same_generic_with_a_discount_is_suggested_below(): void
    {
        $this->medicine('Napa', 'Paracetamol');
        $ace = $this->medicine('Ace', 'Paracetamol');
        MedicineRate::create(['medicine_id' => $ace->id, 'discount_percent' => 12]);

        $r = $this->search('Napa');

        $this->assertSame('Napa', $r[0]['name']);
        $this->assertCount(1, $r[0]['alternatives']);
        $this->assertSame('Ace', $r[0]['alternatives'][0]['name']);
        $this->assertSame($ace->id, $r[0]['alternatives'][0]['id']);
        $this->assertGreaterThan(0, $r[0]['alternatives'][0]['percent']);
    }

    public function test_an_offer_comes_first_and_at_most_three_are_shown(): void
    {
        $this->medicine('Napa', 'Paracetamol');
        foreach (['Ace', 'Fast', 'Reset', 'Tamen'] as $n) {
            $m = $this->medicine($n, 'Paracetamol');
            MedicineRate::create(['medicine_id' => $m->id, 'discount_percent' => 12]);
        }
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        app(OfferProducts::class)->start(Medicine::where('brand_name', 'Tamen')->firstOrFail(), ['percent' => 15], $admin);

        $alts = $this->search('Napa')[0]['alternatives'];

        $this->assertCount(3, $alts, 'four are possible, three are shown');
        $this->assertSame('Tamen', $alts[0]['name']);
        $this->assertTrue($alts[0]['offer']);
    }

    public function test_a_medicine_with_no_generic_or_with_no_other_brand_has_none(): void
    {
        $this->medicine('Solo', null);
        $this->medicine('Napa', 'Paracetamol');

        $this->assertSame([], $this->search('Solo')[0]['alternatives']);
        $this->assertSame([], $this->search('Napa')[0]['alternatives']);
    }

    public function test_a_brand_out_of_stock_or_switched_off_is_not_suggested(): void
    {
        $this->medicine('Napa', 'Paracetamol');
        $empty = $this->medicine('Empty', 'Paracetamol', 0);
        $off = $this->medicine('Off', 'Paracetamol');
        $off->update(['is_active' => false]);
        foreach ([$empty, $off] as $m) {
            MedicineRate::create(['medicine_id' => $m->id, 'discount_percent' => 12]);
        }

        $this->assertSame([], $this->search('Napa')[0]['alternatives']);
    }

    public function test_a_different_generic_is_not_an_alternative(): void
    {
        $this->medicine('Napa', 'Paracetamol');
        $sec = $this->medicine('Seclo', 'Omeprazole');
        MedicineRate::create(['medicine_id' => $sec->id, 'discount_percent' => 12]);

        $this->assertSame([], $this->search('Napa')[0]['alternatives']);
    }

    public function test_the_alternative_carries_no_stock_numbers(): void
    {
        $this->medicine('Napa', 'Paracetamol');
        $ace = $this->medicine('Ace', 'Paracetamol', 7);
        MedicineRate::create(['medicine_id' => $ace->id, 'discount_percent' => 12]);

        $a = $this->search('Napa')[0]['alternatives'][0];

        $this->assertEqualsCanonicalizing(['id', 'name', 'strength', 'company', 'unit', 'price', 'percent', 'offer'], array_keys($a));
    }

    public function test_medicines_in_stock_come_before_those_that_are_not(): void
    {
        $this->medicine('Tonic Zeta', 'X', 10);
        $this->medicine('Tonic Alpha', 'X', 0);
        $this->medicine('Tonic Mid', 'X', 5);

        $this->assertSame(['Tonic Mid', 'Tonic Zeta', 'Tonic Alpha'], array_column($this->search('Tonic'), 'name'), 'in stock first, each group by name');
    }
}
