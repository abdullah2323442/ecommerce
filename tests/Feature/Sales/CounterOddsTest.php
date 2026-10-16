<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 8, 12: a counter sale shows on the shop's page, the return days count from receipt, a draft can change customer. */
class CounterOddsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $karim;

    private User $rahim;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->karim = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Karim Pharmacy', 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 10])->id]);
        $this->rahim = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Medico', 'tier_id' => Tier::create(['name' => 'C', 'discount_percent' => 20])->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20]],
        ]);
        $stock->setMasterSync(true);
    }

    private function draftFor(User $shop): Invoice
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'paikari', 'customer_id' => $shop->id]);
        $draft = Invoice::latest('id')->firstOrFail();
        $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 2])->assertSessionHasNoErrors();

        return $draft;
    }

    public function test_a_sale_made_at_the_counter_is_on_the_shops_own_page_and_the_shop_can_open_it(): void
    {
        $draft = $this->draftFor($this->karim);
        $this->post("/admin/counter/{$draft->id}/finalise")->assertSessionHasNoErrors();

        $this->actingAs($this->karim)->get('/paikari')->assertInertia(fn (Assert $p) => $p->has('orders', 1)->where('orders.0.status', 'final')->where('orders.0.number', $draft->fresh()->number));
        $this->get("/paikari/orders/{$draft->id}")->assertOk();
    }

    public function test_an_open_counter_draft_is_not_on_the_shops_page_and_nobody_else_sees_a_shops_sale(): void
    {
        $draft = $this->draftFor($this->karim);

        $this->actingAs($this->karim)->get('/paikari')->assertInertia(fn (Assert $p) => $p->has('orders', 0));
        $this->get("/paikari/orders/{$draft->id}")->assertRedirect('/');

        $this->post("/admin/counter/{$draft->id}/finalise");
        $this->actingAs($this->rahim)->get('/paikari')->assertInertia(fn (Assert $p) => $p->has('orders', 0));
        $this->get("/paikari/orders/{$draft->id}")->assertRedirect('/');
    }

    public function test_the_return_days_count_from_when_the_goods_were_received(): void
    {
        $draft = $this->draftFor($this->karim);
        $this->post("/admin/counter/{$draft->id}/finalise");
        $draft->refresh();
        $line = $draft->lines()->firstOrFail();
        $payload = ['invoice_id' => $draft->id, 'items' => [['line_id' => $line->id, 'unit' => 'box', 'quantity' => 1]], 'reason_code' => 'damaged', 'reason' => 'Torn', 'resolution' => 'exchange'];

        // The memo is ten days old, so the window is over.
        $draft->update(['finalised_at' => now()->subDays(10)]);
        $this->actingAs($this->karim)->post('/paikari/returns', $payload)->assertSessionHasErrors('return');

        // But it only arrived yesterday: the days count from then.
        $draft->update(['sent_at' => now()->subDays(2), 'delivered_at' => now()->subDay()]);
        $this->post('/paikari/returns', $payload)->assertSessionHasNoErrors();

        $this->get('/paikari/returns')->assertInertia(fn (Assert $p) => $p->where('memos.0.lines.0.returnable', true));
    }

    public function test_a_late_receipt_closes_the_window_even_for_a_new_memo(): void
    {
        $draft = $this->draftFor($this->karim);
        $this->post("/admin/counter/{$draft->id}/finalise");
        $draft->refresh()->update(['sent_at' => now()->subDays(12), 'delivered_at' => now()->subDays(8)]);

        $this->actingAs($this->karim)->get('/paikari/returns')->assertInertia(fn (Assert $p) => $p->where('memos.0.lines.0.returnable', false));
    }

    public function test_a_draft_can_change_customer_and_the_lines_are_priced_at_the_new_rate(): void
    {
        $draft = $this->draftFor($this->karim);   // 10% off 300 = 270 a box
        $this->assertSame('270.00', (string) $draft->lines()->firstOrFail()->unit_price);

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/customer", ['customer_id' => $this->rahim->id])->assertSessionHasNoErrors();

        $draft->refresh();
        $this->assertSame($this->rahim->id, $draft->customer_id);
        $this->assertSame('240.00', (string) $draft->lines()->firstOrFail()->unit_price);   // 20% off
    }

    public function test_a_line_with_its_own_price_keeps_it_when_the_customer_changes(): void
    {
        $draft = $this->draftFor($this->karim);
        $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1, 'special_percent' => 4]);

        $this->put("/admin/counter/{$draft->id}/customer", ['customer_id' => $this->rahim->id])->assertSessionHasNoErrors();

        $special = $draft->lines()->where('unit', 'strip')->firstOrFail();
        $this->assertSame('96.00', (string) $special->unit_price);   // 4% off 100, not the new tier
    }

    public function test_the_new_customer_must_be_the_same_kind_and_the_draft_still_open(): void
    {
        $draft = $this->draftFor($this->karim);
        $bulk = User::factory()->create(['account_type' => AccountType::Wholesaler]);
        $plain = User::factory()->create(['account_type' => AccountType::Public]);

        $this->put("/admin/counter/{$draft->id}/customer", ['customer_id' => $bulk->id])->assertSessionHasErrors('invoice');
        $this->put("/admin/counter/{$draft->id}/customer", ['customer_id' => $plain->id])->assertSessionHasErrors('invoice');
        $this->assertSame($this->karim->id, $draft->fresh()->customer_id);

        $this->post("/admin/counter/{$draft->id}/finalise");
        $this->put("/admin/counter/{$draft->id}/customer", ['customer_id' => $this->rahim->id])->assertSessionHasErrors('invoice');
    }

    public function test_a_retail_draft_has_no_customer_to_change_and_only_counter_staff_can_do_it(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $retail = Invoice::latest('id')->firstOrFail();
        $this->put("/admin/counter/{$retail->id}/customer", ['customer_id' => $this->karim->id])->assertSessionHasErrors('invoice');

        $draft = $this->draftFor($this->karim);
        $this->actingAs($this->rahim)->put("/admin/counter/{$draft->id}/customer", ['customer_id' => $this->rahim->id])->assertRedirect('/');
        $this->assertSame($this->karim->id, $draft->fresh()->customer_id);
    }
}
