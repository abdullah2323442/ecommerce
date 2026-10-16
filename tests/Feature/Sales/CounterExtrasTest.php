<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\OfferProduct;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CounterExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $admin;

    private Medicine $napa;

    private Medicine $napx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        Tier::create(['name' => 'C', 'discount_percent' => 4]);
        Tier::create(['name' => 'A', 'discount_percent' => 12]);
        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name, string $strength) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => $strength, 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make('Napa', '500 mg');
        $this->napx = $make('Napx', '500 mg');
        $stock = app(StockService::class);
        $source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        $stock->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 30], ['medicine_id' => $this->napx->id, 'units' => 30]]]);
        $stock->setMasterSync(true);
    }

    public function test_a_new_shop_is_made_at_the_counter_with_the_default_tier_and_a_draft_opens(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter/shops', ['name' => 'Karim Pharmacy', 'phone' => '01811000000', 'area' => 'Mirpur'])->assertRedirect();

        $shop = User::where('phone', '01811000000')->firstOrFail();
        $this->assertSame(AccountType::Paikari, $shop->account_type);
        $this->assertSame('Mirpur', $shop->area);
        $this->assertSame('C', $shop->tier->name, 'the lowest tier is the default for now');

        $draft = Invoice::firstOrFail();
        $this->assertSame($shop->id, $draft->customer_id);
        $this->assertSame('paikari', $draft->kind);
    }

    public function test_the_new_shop_cannot_be_signed_into_and_a_phone_is_used_once(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter/shops', ['name' => 'Karim', 'phone' => '01811000000', 'area' => 'Mirpur']);
        $this->post('/admin/counter/shops', ['name' => 'Other Karim', 'phone' => '01811000000', 'area' => 'Uttara'])->assertSessionHasErrors('phone');

        $this->assertSame(1, User::where('phone', '01811000000')->count());
        $this->assertFalse(auth()->guard('web')->validate(['email' => null, 'password' => 'password']));
    }

    public function test_only_staff_and_admin_can_add_a_shop(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->post('/admin/counter/shops', ['name' => 'X', 'phone' => '01900000000', 'area' => 'Y'])->assertRedirect('/');
        $this->assertSame(0, User::where('phone', '01900000000')->count());
    }

    public function test_search_shows_area_beside_the_name_and_matches_on_it(): void
    {
        User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Pharmacy', 'phone' => '01711111111', 'area' => 'Dhanmondi']);
        User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Pharmacy', 'phone' => '01722222222', 'area' => 'Gulshan']);

        $res = $this->actingAs($this->staff)->get('/admin/counter/customers?q=Rahim')->assertOk();
        $this->assertSame(['Dhanmondi', 'Gulshan'], collect($res->json('results'))->pluck('area')->sort()->values()->all());

        $this->get('/admin/counter/customers?q=Gulshan')->assertJsonCount(1, 'results');
    }

    public function test_scanning_a_box_adds_one_and_scanning_again_adds_another(): void
    {
        MedicineBarcode::create(['medicine_id' => $this->napa->id, 'code' => '8901234567890']);
        $draft = app(InvoiceService::class)->open('retail', $this->staff);

        $this->actingAs($this->staff)->post("/admin/counter/{$draft->id}/scan", ['code' => '8901234567890'])->assertSessionHasNoErrors();
        $this->post("/admin/counter/{$draft->id}/scan", ['code' => '8901234567890']);

        $line = $draft->lines()->first();
        $this->assertSame(1, $draft->lines()->count());
        $this->assertSame('2.00', (string) $line->quantity);
        $this->assertSame('box', $line->unit);
    }

    public function test_an_unknown_barcode_is_refused_in_plain_words(): void
    {
        $draft = app(InvoiceService::class)->open('retail', $this->staff);
        $this->actingAs($this->staff)->post("/admin/counter/{$draft->id}/scan", ['code' => '000'])->assertSessionHasErrors('invoice');
        $this->assertSame(0, $draft->lines()->count());
    }

    public function test_search_warns_when_two_names_are_nearly_the_same(): void
    {
        $draft = app(InvoiceService::class)->open('retail', $this->staff);

        $this->actingAs($this->staff)->get("/admin/counter/{$draft->id}/search?q=Nap")
            ->assertJsonPath('results.0.similar', true)->assertJsonPath('results.1.similar', true)->assertJsonPath('results.0.strength', '500 mg');
    }

    public function test_the_most_sold_medicines_are_one_click_away(): void
    {
        $inv = app(InvoiceService::class);
        foreach ([$this->napa, $this->napa, $this->napx] as $m) {
            $d = $inv->open('retail', $this->staff);
            $inv->addLine($d, $m, 'box', 1, $this->staff);
            $inv->finalise($d, $this->staff);
        }
        $draft = $inv->open('retail', $this->staff);

        $this->actingAs($this->staff)->get("/admin/counter/{$draft->id}")->assertInertia(fn (Assert $p) => $p->where('popular.0.name', 'Napa')->where('popular.1.name', 'Napx'));
    }

    public function test_an_offer_given_at_the_counter_lands_in_the_shops_offer_section(): void
    {
        $this->actingAs($this->admin)->post('/admin/offer-products', ['medicine_id' => $this->napa->id, 'percent' => 15, 'min_boxes' => 5, 'step_percent' => 0, 'visible_to' => ['public'], 'stock_mode' => 'in_stock']);
        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::first()->id]);
        $inv = app(InvoiceService::class);
        $draft = $inv->open('paikari', $this->staff, $shop);
        $inv->addOfferLine($draft, OfferProduct::firstOrFail()->fresh('medicine'), 6, $this->staff);

        $this->actingAs($this->admin)->get("/admin/customers/{$shop->id}")->assertInertia(fn (Assert $p) => $p->where('offersTaken.0.name', 'Napa')->where('offersTaken.0.boxes', 6));
    }

    public function test_an_unknown_barcode_is_asked_about_then_linked_and_remembered(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $draft = \App\Models\Invoice::latest('id')->firstOrFail();

        $this->actingAs($this->staff)->post("/admin/counter/{$draft->id}/scan", ['code' => '999 111 222'])->assertSessionHasErrors(['invoice', 'unknown_barcode']);
        $this->assertSame(0, $draft->lines()->count());

        $this->actingAs($this->staff)->post("/admin/counter/{$draft->id}/scan", ['code' => '999 111 222', 'medicine_id' => $this->napa->id])->assertSessionHasNoErrors();
        $this->assertSame(1, $draft->lines()->count(), 'linked, and the box is on the draft');
        $this->assertSame($this->napa->id, \App\Models\MedicineBarcode::where('code', '999111222')->value('medicine_id'));

        $this->actingAs($this->staff)->post("/admin/counter/{$draft->id}/scan", ['code' => '999111222'])->assertSessionHasNoErrors();
        $this->assertSame('2.00', (string) $draft->lines()->first()->quantity, 'next time the code is known');
    }

    public function test_a_code_already_linked_is_not_relinked_by_a_later_pick(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $draft = \App\Models\Invoice::latest('id')->firstOrFail();
        \App\Models\MedicineBarcode::create(['medicine_id' => $this->napa->id, 'code' => '123456789']);
        $other = \App\Models\Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Other', 'strength' => '1', 'form' => 'tablet', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true]);

        $this->actingAs($this->staff)->post("/admin/counter/{$draft->id}/scan", ['code' => '123456789', 'medicine_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame($this->napa->id, $draft->lines()->firstOrFail()->medicine_id, 'the code stays on its own medicine');
        $this->assertSame(1, \App\Models\MedicineBarcode::where('code', '123456789')->count());
    }
}
