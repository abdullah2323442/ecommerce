<?php

namespace Tests\Feature\Public;

use App\Models\Company;
use App\Models\Medicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** One search for the whole shop, not only the pharmacy. */
class SiteSearchTest extends TestCase
{
    use RefreshDatabase;

    private function med(string $brand): Medicine
    {
        return Medicine::create([
            'company_id' => Company::firstOrCreate(['name' => 'Square'])->id, 'brand_name' => $brand, 'generic_name' => 'Paracetamol', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_piece' => 1.2, 'is_active' => true,
        ]);
    }

    public function test_it_opens_for_everyone_and_asks_for_two_letters(): void
    {
        $this->get('/search')->assertOk()->assertInertia(fn (Assert $p) => $p->component('search')->where('q', '')->where('medicines', [])->where('products', []));
        $this->get('/search?q=n')->assertInertia(fn (Assert $p) => $p->where('medicines', []));
    }

    public function test_it_finds_medicines(): void
    {
        $this->med('Napa');

        $this->get('/search?q=napa')->assertInertia(fn (Assert $p) => $p->has('medicines', 1)->where('medicines.0.brand_name', 'Napa'));
    }

    public function test_a_very_long_search_is_cut_and_markup_is_harmless(): void
    {
        $this->get('/search?q='.str_repeat('a', 500))->assertOk()->assertInertia(fn (Assert $p) => $p->where('q', str_repeat('a', 80)));
        $this->get('/search?q='.urlencode('<script>alert(1)</script>'))->assertOk();
    }
}
