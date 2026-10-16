<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CounterHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
        $stock->setMasterSync(true);
    }

    public function test_only_staff_and_admin_reach_the_counter(): void
    {
        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Wholesaler] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get('/admin/counter')->assertRedirect('/');
        }
        $this->get('/admin/counter')->assertRedirect();
        $this->actingAs($this->staff)->get('/admin/counter')->assertOk();
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Admin]))->get('/admin/counter')->assertOk();
    }

    public function test_a_retail_sale_from_start_to_memo(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail'])->assertRedirect();
        $invoice = Invoice::firstOrFail();

        $this->get("/admin/counter/{$invoice->id}/search?q=Na")->assertOk()
            ->assertJsonPath('results.0.name', 'Napa')->assertJsonPath('results.0.units.0.unit', 'box');

        $this->post("/admin/counter/{$invoice->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 3])->assertRedirect();
        $this->put("/admin/counter/{$invoice->id}/discount", ['percent' => 2])->assertSessionHasNoErrors();
        $this->post("/admin/counter/{$invoice->id}/finalise")->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('final', $invoice->status);
        $this->assertSame('M'.now()->year.'-000001', $invoice->number);
        $this->assertSame('294.00', (string) $invoice->total);

        $this->get("/admin/counter/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
            ->component('admin/counter/show')->where('invoice.number', 'M'.now()->year.'-000001')->where('invoice.totals.total', 294));
    }

    public function test_staff_cannot_exceed_the_discount_limit_and_see_the_reason(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $invoice = Invoice::firstOrFail();

        $this->put("/admin/counter/{$invoice->id}/discount", ['percent' => 30])->assertSessionHasErrors('invoice');
    }

    public function test_a_shop_sale_needs_a_shop_customer(): void
    {
        $this->actingAs($this->staff);
        $this->post('/admin/counter', ['kind' => 'paikari'])->assertSessionHasErrors('customer_id');

        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Pharmacy']);
        $this->get('/admin/counter/customers?q=Rahim')->assertJsonPath('results.0.id', $shop->id)->assertJsonPath('results.0.open_invoice', null);

        $this->post('/admin/counter', ['kind' => 'paikari', 'customer_id' => $shop->id])->assertRedirect();
        $this->get('/admin/counter/customers?q=Rahim')->assertJsonPath('results.0.open_invoice', Invoice::firstOrFail()->id);

        $public = User::factory()->create(['account_type' => AccountType::Public]);
        $this->post('/admin/counter', ['kind' => 'paikari', 'customer_id' => $public->id])->assertSessionHasErrors('customer_id');
    }

    public function test_cancelling_a_draft_puts_the_goods_back_and_the_counter_shows_no_stock_figures(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $invoice = Invoice::firstOrFail();
        $this->post("/admin/counter/{$invoice->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 4]);

        $json = $this->get("/admin/counter/{$invoice->id}/search?q=Napa")->getContent();
        $this->assertStringNotContainsStringIgnoringCase('stock', $json);
        $this->assertStringNotContainsStringIgnoringCase('cost', $json);

        $this->post("/admin/counter/{$invoice->id}/cancel")->assertRedirect('/admin/counter');
        $this->assertEqualsWithDelta(10.0, app(StockService::class)->onHand($this->napa), 1e-6);
    }

    public function test_asking_for_more_than_exists_is_refused_in_plain_words(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $invoice = Invoice::firstOrFail();

        $this->post("/admin/counter/{$invoice->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 50])->assertSessionHasErrors('invoice');
        $this->assertSame(0, $invoice->lines()->count());
    }
}
