<?php

namespace Tests\Feature\Public;

use App\Models\Company;
use App\Models\Medicine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\ResolvesInertiaProps;
use Tests\TestCase;

class FormPagesTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesInertiaProps;

    private function med(string $brand, string $form, array $extra = []): Medicine
    {
        $company = Company::firstOrCreate(['name' => 'Square']);

        return Medicine::create(array_merge([
            'company_id' => $company->id, 'brand_name' => $brand, 'strength' => '500 mg', 'form' => $form,
            'packaging_type' => 'box', 'mrp_piece' => 1, 'is_active' => true,
        ], $extra));
    }

    public function test_a_form_page_lists_only_that_form_a_to_z(): void
    {
        $this->med('Zeta', 'tablet');
        $this->med('Alpha', 'tablet');
        $this->med('Seclo', 'capsule');
        $this->med('Hidden', 'tablet', ['is_active' => false]);

        $this->get('/forms/tablet')->assertInertia(fn (Assert $page) => $page
            ->component('forms/show')
            ->where('medicines.total', 2)
            ->where('medicines.data.0.brand_name', 'Alpha')
            ->where('medicines.data.1.brand_name', 'Zeta'));
    }

    public function test_multi_word_forms_use_a_slug(): void
    {
        $this->med('Visine', 'eye drop');

        $this->get('/forms/eye-drop')->assertOk()->assertInertia(fn (Assert $page) => $page->where('form.name', 'eye drop'));
    }

    public function test_an_unknown_form_redirects_home_like_any_unknown_page(): void
    {
        $this->med('Napa', 'tablet');

        $this->get('/forms/nonsense')->assertRedirect('/');
    }

    public function test_a_form_with_only_inactive_medicines_does_not_exist(): void
    {
        $this->med('Old', 'lozenge', ['is_active' => false]);

        $this->get('/forms/lozenge')->assertRedirect('/');
    }

    public function test_form_page_paginates_and_never_exposes_private_fields(): void
    {
        foreach (range(1, 35) as $n) {
            $this->med(sprintf('Med %02d', $n), 'tablet');
        }

        $this->get('/forms/tablet')->assertInertia(fn (Assert $page) => $page->where('medicines.total', 35)->has('medicines.data', 30));

        $payload = $this->inertiaPayload('/forms/tablet', withoutTranslations: true);
        foreach (['stock', 'purchase', 'legacy_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $payload);
        }
    }
}
