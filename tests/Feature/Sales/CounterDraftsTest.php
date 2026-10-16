<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec 4.3.5 (DRF-5, 7, 9): the draft count, who has each draft, a shop with two drafts, moving a line, and the same medicine added twice. */
class CounterDraftsTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private User $ben;

    private User $shop;

    private Medicine $napa;

    private Medicine $seclo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Ana']);
        $this->ben = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Ben']);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Pharmacy', 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 10])->id]);
        $company = Company::create(['name' => 'Square']);
        $mk = fn (string $n) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $n, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10,
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $mk('Napa');
        $this->seclo = $mk('Seclo');
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 30], ['medicine_id' => $this->seclo->id, 'units' => 30]],
        ]);
        $stock->setMasterSync(true);
    }

    private function draft(User $by, string $kind = 'retail', ?User $customer = null): Invoice
    {
        return app(InvoiceService::class)->open($kind, $by, $customer);
    }

    public function test_the_list_counts_open_drafts_and_says_who_has_each(): void
    {
        $a = $this->draft($this->ana);
        $this->draft($this->ana);
        $this->draft($this->ben);

        $this->actingAs($this->ana)->get('/admin/counter')->assertInertia(fn (Assert $p) => $p->has('drafts', 3)
            ->where('drafts.0.staff', 'Ana')->where('drafts.2.staff', 'Ben')
            ->where('byStaff', [['name' => 'Ana', 'drafts' => 2], ['name' => 'Ben', 'drafts' => 1]]));
        $this->assertSame('Ana', $a->openedBy->name);
    }

    public function test_a_shop_with_two_drafts_open_is_pointed_out(): void
    {
        $this->draft($this->ana, 'paikari', $this->shop);
        $this->draft($this->ben, 'paikari', $this->shop);
        $this->draft($this->ben);

        $this->actingAs($this->ana)->get('/admin/counter')->assertInertia(fn (Assert $p) => $p
            ->where('drafts.0.same_customer', 2)->where('drafts.1.same_customer', 2)->where('drafts.2.same_customer', 1));
    }

    public function test_adding_the_same_medicine_again_says_so(): void
    {
        $d = $this->draft($this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$d->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);

        $res = $this->actingAs($this->ana)->post("/admin/counter/{$d->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);

        $res->assertSessionHas('status');
        $this->assertStringContainsString('Napa', session('status'));
        $this->assertSame(1, $d->lines()->count());
        $this->assertSame('3.00', (string) $d->lines()->first()->quantity);
    }

    public function test_a_new_medicine_or_a_new_pack_gives_no_such_notice(): void
    {
        $d = $this->draft($this->ana);

        $this->actingAs($this->ana)->post("/admin/counter/{$d->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1])->assertSessionMissing('status');
        $this->actingAs($this->ana)->post("/admin/counter/{$d->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1])->assertSessionMissing('status');
        $this->actingAs($this->ana)->post("/admin/counter/{$d->id}/lines", ['medicine_id' => $this->seclo->id, 'unit' => 'strip', 'quantity' => 1])->assertSessionMissing('status');
        $this->assertSame(3, $d->lines()->count());
    }

    public function test_asking_for_a_separate_line_keeps_two_lines_and_no_notice(): void
    {
        $d = $this->draft($this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$d->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);

        $this->actingAs($this->ana)->post("/admin/counter/{$d->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1, 'separate' => 1])->assertSessionMissing('status');
        $this->assertSame(2, $d->lines()->count());
    }

    public function test_a_line_moves_to_another_open_draft_of_the_same_kind(): void
    {
        $from = $this->draft($this->ana);
        $to = $this->draft($this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$from->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $line = $from->lines()->firstOrFail();

        $this->actingAs($this->ana)->post("/admin/counter/{$from->id}/lines/{$line->id}/move", ['to' => $to->id])->assertSessionHasNoErrors();

        $this->assertSame(0, $from->lines()->count());
        $this->assertSame($line->id, $to->lines()->firstOrFail()->id);
        $this->assertSame($from->id, $line->fresh()->from_invoice_id);
    }

    public function test_the_moved_line_takes_the_new_customers_price(): void
    {
        $retail = $this->draft($this->ana);
        $other = $this->draft($this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$retail->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $line = $retail->lines()->firstOrFail();
        $this->assertSame('100.00', (string) $line->unit_price);

        $this->actingAs($this->ana)->post("/admin/counter/{$retail->id}/lines/{$line->id}/move", ['to' => $other->id]);

        $this->assertSame('100.00', (string) $line->fresh()->unit_price, 'a retail draft to a retail draft: the same price');
    }

    public function test_a_line_cannot_go_to_a_draft_of_another_kind_to_itself_or_a_finished_sale(): void
    {
        $from = $this->draft($this->ana);
        $shopDraft = $this->draft($this->ana, 'paikari', $this->shop);
        $this->actingAs($this->ana)->post("/admin/counter/{$from->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $line = $from->lines()->firstOrFail();

        $this->actingAs($this->ana)->post("/admin/counter/{$from->id}/lines/{$line->id}/move", ['to' => $shopDraft->id])->assertSessionHasErrors('invoice');
        $this->actingAs($this->ana)->post("/admin/counter/{$from->id}/lines/{$line->id}/move", ['to' => $from->id])->assertSessionHasErrors('invoice');

        $done = $this->draft($this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$done->id}/lines", ['medicine_id' => $this->seclo->id, 'unit' => 'strip', 'quantity' => 1]);
        app(InvoiceService::class)->finalise($done->fresh(), $this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$from->id}/lines/{$line->id}/move", ['to' => $done->id])->assertSessionHasErrors('invoice');

        $this->assertSame($from->id, $line->fresh()->invoice_id);
    }

    public function test_a_line_of_another_draft_cannot_be_moved_through_this_one(): void
    {
        $a = $this->draft($this->ana);
        $b = $this->draft($this->ana);
        $c = $this->draft($this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$a->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $line = $a->lines()->firstOrFail();

        $this->actingAs($this->ana)->post("/admin/counter/{$b->id}/lines/{$line->id}/move", ['to' => $c->id])->assertRedirect('/');
        $this->assertSame($a->id, $line->fresh()->invoice_id);
    }

    public function test_only_counter_staff_move_lines(): void
    {
        $a = $this->draft($this->ana);
        $b = $this->draft($this->ana);
        $this->actingAs($this->ana)->post("/admin/counter/{$a->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $line = $a->lines()->firstOrFail();

        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post("/admin/counter/{$a->id}/lines/{$line->id}/move", ['to' => $b->id])->assertRedirect('/');
        $this->assertSame($a->id, $line->fresh()->invoice_id);
    }

    public function test_the_counter_search_puts_what_is_in_stock_first(): void
    {
        $company = Company::first();
        $empty = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Tonic Alpha', 'strength' => '1', 'form' => 'syrup', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true]);
        $stocked = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Tonic Zeta', 'strength' => '1', 'form' => 'syrup', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true]);
        app(StockService::class)->receive(StockSource::firstOrFail(), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $stocked->id, 'units' => 5]]]);
        $d = $this->draft($this->ana);

        $names = collect($this->actingAs($this->ana)->get("/admin/counter/{$d->id}/search?q=Tonic")->json('results'))->pluck('name')->all();

        $this->assertSame(['Tonic Zeta', 'Tonic Alpha'], $names);
        $this->assertNotNull($empty->id);
    }
}
