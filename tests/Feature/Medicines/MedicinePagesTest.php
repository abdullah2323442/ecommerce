<?php

namespace Tests\Feature\Medicines;

use App\Models\Company;
use App\Models\Medicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ResolvesInertiaProps;
use Tests\TestCase;

class MedicinePagesTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesInertiaProps;

    private function med(string $brand, array $extra = []): Medicine
    {
        $company = Company::firstOrCreate(['name' => 'Square Pharmaceuticals PLC']);

        return Medicine::create(array_merge([
            'company_id' => $company->id,
            'brand_name' => $brand,
            'generic_name' => 'Paracetamol',
            'strength' => '500 mg',
            'form' => 'tablet',
            'packaging_type' => 'box',
            'mrp_piece' => 1.2,
            'is_active' => true,
        ], $extra));
    }

    public function test_with_nothing_typed_every_active_medicine_is_listed_a_to_z_in_pages(): void
    {
        foreach (range(1, 35) as $i) {
            $this->med(sprintf('Brand %02d', $i));
        }
        $this->med('Aaa hidden', ['is_active' => false]);
        $this->med('Aaa first');

        $this->get('/medicines')->assertInertia(fn (Assert $page) => $page
            ->where('browse.total', 36)->has('browse.data', 30)->where('browse.data.0.brand_name', 'Aaa first')->where('browse.data.1.brand_name', 'Brand 01'));

        $this->get('/medicines?page=2')->assertInertia(fn (Assert $page) => $page->has('browse.data', 6)->where('browse.data.5.brand_name', 'Brand 35'));
    }

    public function test_a_search_shows_matches_and_not_the_full_list(): void
    {
        $this->med('Napa');

        $this->get('/medicines?q=napa')->assertInertia(fn (Assert $page) => $page->where('browse', null)->has('results.data'));
    }

    public function test_the_full_list_leaks_no_stock_or_cost(): void
    {
        $this->med('Napa');

        $payload = json_encode($this->get('/medicines')->viewData('page')['props']['browse']);

        foreach (['stock', 'purchase', 'legacy_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }

    public function test_index_renders_for_a_guest_without_a_query(): void
    {
        $this->get('/medicines')->assertInertia(fn (Assert $page) => $page
            ->component('medicines/index')
            ->where('query', '')
            ->where('results', null));
    }

    public function test_index_returns_server_side_results_for_a_query(): void
    {
        $this->med('Napa');

        $this->get('/medicines?q=napa')->assertInertia(fn (Assert $page) => $page
            ->component('medicines/index')
            ->where('results.total', 1)
            ->has('results.data', 1)
            ->where('results.data.0.brand_name', 'Napa'));
    }

    public function test_show_page_lists_cheaper_alternatives_of_the_same_medicine(): void
    {
        $napa = $this->med('Napa', ['mrp_piece' => 1.5]);
        $this->med('Ace', ['mrp_piece' => 1.0]);

        $this->get("/medicines/{$napa->id}")->assertInertia(fn (Assert $page) => $page
            ->component('medicines/show')
            ->where('medicine.brand_name', 'Napa')
            ->has('alternatives', 1)
            ->where('alternatives.0.brand_name', 'Ace'));
    }

    public function test_an_inactive_medicine_has_no_page(): void
    {
        $old = $this->med('Oldmed', ['is_active' => false]);

        $this->get("/medicines/{$old->id}")->assertRedirect('/');
    }

    public function test_page_payload_never_contains_stock_or_purchase_data(): void
    {
        $napa = $this->med('Napa');

        $payload = $this->inertiaPayload("/medicines/{$napa->id}", withoutTranslations: true);

        foreach (['stock', 'purchase', 'legacy_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }
}
