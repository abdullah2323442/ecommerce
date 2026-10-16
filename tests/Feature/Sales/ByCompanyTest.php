<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 29-A: a shop that orders company by company opens a company and sees everything it makes, at the shop's own prices. */
class ByCompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private Company $square;

    private Company $beacon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'categories' => ['medicine'], 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 10])->id]);
        $this->square = Company::create(['name' => 'Square']);
        $this->beacon = Company::create(['name' => 'Beacon']);

        foreach (['Napa', 'Seclo', 'Neotack'] as $name) {
            $this->medicine($this->square, $name);
        }
        $this->medicine($this->beacon, 'Bextram');
        $this->medicine($this->beacon, 'Hidden', false);
    }

    private function medicine(Company $company, string $name, bool $active = true): Medicine
    {
        return Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => $active,
        ]);
    }

    public function test_the_portal_lists_the_companies_that_have_products(): void
    {
        Company::create(['name' => 'Empty Pharma']);
        $this->medicine(Company::create(['name' => 'Retired']), 'Gone', false);

        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->has('companies', 2)->where('companies.0.name', 'Beacon')->where('companies.1.name', 'Square'));
    }

    public function test_choosing_a_company_lists_all_its_products_at_the_shops_own_rate(): void
    {
        $this->actingAs($this->shop)->getJson("/paikari/search?company={$this->square->id}")->assertOk()
            ->assertJson(['more' => false])
            ->assertJsonCount(3, 'results')
            ->assertJsonPath('results.0.name', 'Napa')
            ->assertJsonPath('results.0.units.0.price', 270)   // 10% off 300
            ->assertJsonPath('results.0.company', 'Square');
    }

    public function test_another_companys_products_and_inactive_ones_are_not_in_the_list(): void
    {
        $names = collect($this->actingAs($this->shop)->getJson("/paikari/search?company={$this->beacon->id}")->json('results'))->pluck('name');

        $this->assertSame(['Bextram'], $names->all());
    }

    public function test_a_long_company_list_comes_in_pages_of_forty(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->medicine($this->beacon, sprintf('Beta %02d', $i));
        }

        $first = $this->actingAs($this->shop)->getJson("/paikari/search?company={$this->beacon->id}")->assertJson(['more' => true]);
        $this->assertCount(40, $first->json('results'));

        $second = $this->getJson("/paikari/search?company={$this->beacon->id}&page=2")->assertJson(['more' => false]);
        $this->assertCount(6, $second->json('results'));   // 46 products in all
        $this->assertSame([], array_intersect(array_column($first->json('results'), 'id'), array_column($second->json('results'), 'id')));
    }

    public function test_the_name_search_still_works_and_needs_two_letters(): void
    {
        $this->actingAs($this->shop)->getJson('/paikari/search?q=na')->assertJsonCount(1, 'results');
        $this->getJson('/paikari/search?q=n')->assertJsonCount(0, 'results');
        $this->getJson('/paikari/search')->assertJsonCount(0, 'results');
    }

    public function test_a_bulk_buyer_without_the_medicine_category_sees_no_companies_and_no_products(): void
    {
        $wood = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);
        $timber = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine']]);

        $this->actingAs($wood)->get('/wholesale')->assertInertia(fn (Assert $p) => $p->has('companies', 0));
        $this->getJson("/wholesale/search?company={$this->square->id}")->assertJsonCount(0, 'results');

        $this->actingAs($timber)->get('/wholesale')->assertInertia(fn (Assert $p) => $p->has('companies', 2));
        $this->getJson("/wholesale/search?company={$this->square->id}")->assertJsonCount(3, 'results');
    }

    public function test_a_visitor_and_a_public_account_cannot_use_it(): void
    {
        $this->getJson("/paikari/search?company={$this->square->id}")->assertUnauthorized();

        $plain = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($plain)->get("/paikari/search?company={$this->square->id}")->assertRedirect('/');
    }

    public function test_a_product_found_this_way_goes_into_the_cart_as_a_normal_order_line(): void
    {
        $napa = Medicine::where('brand_name', 'Napa')->firstOrFail();

        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $napa->id, 'unit' => 'box', 'quantity' => 2])->assertSessionHasNoErrors();

        $this->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('cart.lines.0.name', 'Napa')->where('cart.lines.0.unit_price', 270));
    }
}
