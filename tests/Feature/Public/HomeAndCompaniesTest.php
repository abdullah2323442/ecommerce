<?php

namespace Tests\Feature\Public;

use App\Models\Company;
use App\Models\Medicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ResolvesInertiaProps;
use Tests\TestCase;

class HomeAndCompaniesTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesInertiaProps;

    private function med(Company $company, string $brand, array $extra = []): Medicine
    {
        return Medicine::create(array_merge([
            'company_id' => $company->id, 'brand_name' => $brand, 'generic_name' => 'Paracetamol',
            'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_piece' => 1.2, 'is_active' => true,
        ], $extra));
    }

    public function test_home_is_open_to_guests_and_shows_real_counts(): void
    {
        $square = Company::create(['name' => 'Square', 'logo_path' => 'company_logos/square.webp']);
        $this->med($square, 'Napa');
        $this->med($square, 'Ace');
        $this->med($square, 'Oldmed', ['is_active' => false]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('stats.medicines', 2)
            ->where('stats.companies', 1)
            ->where('stats.generics', 1)
            ->has('companies', 1));
    }

    public function test_home_works_with_an_empty_database(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.medicines', 0)->where('forms', [])->where('companies', []));
    }

    public function test_home_lists_the_forms_with_counts(): void
    {
        $square = Company::create(['name' => 'Square']);
        $this->med($square, 'Napa', ['form' => 'tablet']);
        $this->med($square, 'Ace', ['form' => 'tablet']);
        $this->med($square, 'Seclo', ['form' => 'capsule']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('forms.0.slug', 'tablet')->where('forms.0.count', 2)
            ->where('forms.1.slug', 'capsule')->where('forms.1.count', 1));
    }

    public function test_home_never_mentions_a_restricted_section(): void
    {
        // Golden rule G1: nothing public may hint that paikari or wholesale exist.
        $square = Company::create(['name' => 'Square', 'logo_path' => 'company_logos/s.webp']);
        $this->med($square, 'Napa');

        // The resolved data a guest really receives, including the translation dictionary.
        $payload = $this->inertiaPayload('/');

        // Whole words only: "mpo" would otherwise match inside "component".
        foreach (['paikari', 'wholesale', 'mpo', 'craftsman'] as $word) {
            $this->assertSame(0, preg_match('/\b'.$word.'\b/u', $payload), "A guest's page data mentions \"{$word}\".");
        }
    }

    public function test_company_directory_lists_only_active_companies_that_have_medicines(): void
    {
        $square = Company::create(['name' => 'Square']);
        Company::create(['name' => 'Empty Co']);
        $hidden = Company::create(['name' => 'Hidden Co', 'is_active' => false]);
        $this->med($square, 'Napa');
        $this->med($hidden, 'Secret');

        $this->get('/companies')->assertInertia(fn (Assert $page) => $page
            ->component('companies/index')
            ->has('companies.data', 1)
            ->where('companies.data.0.name', 'Square')
            ->where('companies.data.0.count', 1));
    }

    public function test_company_directory_search_filters_by_name(): void
    {
        foreach (['Square', 'Beacon'] as $name) {
            $this->med(Company::create(['name' => $name]), $name.' med');
        }

        $this->get('/companies?q=beac')->assertInertia(fn (Assert $page) => $page
            ->has('companies.data', 1)->where('companies.data.0.name', 'Beacon'));
    }

    public function test_company_page_lists_its_active_medicines_only_with_public_fields(): void
    {
        $square = Company::create(['name' => 'Square']);
        $beacon = Company::create(['name' => 'Beacon']);
        $this->med($square, 'Napa');
        $this->med($square, 'Oldmed', ['is_active' => false]);
        $this->med($beacon, 'Ace');

        $this->get("/companies/{$square->id}")->assertInertia(fn (Assert $page) => $page
            ->component('companies/show')
            ->where('medicines.total', 1)
            ->where('medicines.data.0.brand_name', 'Napa'));

        // Data fields only: the dictionary legitimately contains the sentence "We will try to stock it".
        $payload = $this->inertiaPayload("/companies/{$square->id}", withoutTranslations: true);
        foreach (['stock', 'purchase', 'legacy_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    public function test_an_inactive_company_has_no_page(): void
    {
        $hidden = Company::create(['name' => 'Hidden Co', 'is_active' => false]);

        $this->get("/companies/{$hidden->id}")->assertRedirect('/');
    }

    public function test_company_page_paginates(): void
    {
        $square = Company::create(['name' => 'Square']);
        for ($i = 1; $i <= 45; $i++) {
            $this->med($square, sprintf('Med %02d', $i));
        }

        // 40 per page: a company's list is scanned, not read, so a longer page means fewer taps.
        $this->get("/companies/{$square->id}")->assertInertia(fn (Assert $page) => $page
            ->where('medicines.total', 45)->has('medicines.data', 40));
        $this->get("/companies/{$square->id}?page=2")->assertInertia(fn (Assert $page) => $page->has('medicines.data', 5));
    }
}
