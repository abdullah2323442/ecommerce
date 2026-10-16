<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Quote;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\BulkOrder;
use App\Services\Sales\Quotes;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14, Phase 2: bulk order upload and custom quotation for a bulk buyer. */
class BulkAndQuotesTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine'], 'business_name' => 'Rahim Pharma']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $company = Company::create(['name' => 'Square']);
        $this->napa = $this->medicine($company, 'Napa', '500 mg');
        $this->medicine($company, 'Napa', '665 mg');
        $this->medicine($company, 'Seclo', '20 mg', 'piece');
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => Medicine::all()->map(fn ($m) => ['medicine_id' => $m->id, 'units' => 50])->all(),
        ]);
        $stock->setMasterSync(true);
    }

    private function medicine(Company $c, string $name, string $strength, string $pack = 'box'): Medicine
    {
        return Medicine::create(['company_id' => $c->id, 'brand_name' => $name, 'strength' => $strength, 'form' => 'tablet', 'packaging_type' => $pack, 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true]);
    }

    private function csv(string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('list.csv', $body);
    }

    // ------------------------------------------------------------------ bulk upload

    public function test_a_list_fills_the_order_and_reports_what_it_could_not_add(): void
    {
        $cart = app(\App\Services\Sales\InvoiceService::class)->open('wholesale', $this->buyer, $this->buyer, 'online');

        $result = app(BulkOrder::class)->import($cart, $this->buyer, "name,quantity,unit\nNapa 500mg,5,box\nSeclo 20mg,3\nNapa,2\nNothing here,1\nNapa 500mg,0\nNapa 665 mg,2,carton\n");

        $this->assertSame(2, $result['added']);
        $reasons = collect($result['problems'])->pluck('reason', 'line');
        $this->assertStringContainsString('Several products', $reasons[4]);   // "Napa" alone is a guess between two strengths
        $this->assertStringContainsString('could not find', $reasons[5]);
        $this->assertStringContainsString('more than zero', $reasons[6]);
        $this->assertStringContainsString('piece, strip or box', $reasons[7]);
        $this->assertSame(2, $cart->lines()->count());
        $this->assertSame('piece', $cart->lines()->where('medicine_id', Medicine::where('brand_name', 'Seclo')->value('id'))->value('unit'));
    }

    public function test_the_same_product_twice_adds_up_and_semicolons_and_tabs_work(): void
    {
        $cart = app(\App\Services\Sales\InvoiceService::class)->open('wholesale', $this->buyer, $this->buyer, 'online');

        $result = app(BulkOrder::class)->import($cart, $this->buyer, "\xEF\xBB\xBFNapa 500mg;2;box\nNapa 500mg\t3\tbox\n");

        $this->assertSame(2, $result['added']);
        $this->assertEquals(5, (float) $cart->lines()->first()->quantity);
    }

    public function test_an_empty_or_huge_list_is_refused(): void
    {
        $cart = app(\App\Services\Sales\InvoiceService::class)->open('wholesale', $this->buyer, $this->buyer, 'online');

        try {
            app(BulkOrder::class)->import($cart, $this->buyer, "name,quantity\n");
            $this->fail('empty');
        } catch (StockException) {
        }

        $this->expectException(StockException::class);
        app(BulkOrder::class)->import($cart, $this->buyer, str_repeat("Napa 500mg,1,box\n", 301));
    }

    public function test_upload_through_the_page_puts_lines_in_the_order_and_shows_the_result(): void
    {
        $this->actingAs($this->buyer)->post('/wholesale/bulk', ['list' => $this->csv("Napa 500mg,4,box\nGhost,1\n")])->assertSessionHasNoErrors()->assertSessionHas('bulk.added', 1);

        $cart = Invoice::where('customer_id', $this->buyer->id)->where('status', 'draft')->firstOrFail();
        $this->assertSame(1, $cart->lines()->count());
    }

    public function test_only_a_bulk_buyer_with_medicine_can_upload(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'categories' => ['medicine']]);
        $wood = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['wood']]);

        $this->actingAs($shop)->post('/paikari/bulk', ['list' => $this->csv('Napa 500mg,1')])->assertRedirect('/');   // a shop has no such route
        $this->actingAs($shop)->post('/wholesale/bulk', ['list' => $this->csv('Napa 500mg,1')])->assertRedirect('/');
        $this->actingAs($wood)->post('/wholesale/bulk', ['list' => $this->csv('Napa 500mg,1')])->assertRedirect('/');
        $this->assertSame(0, Invoice::count());
    }

    public function test_a_file_that_is_not_a_list_is_refused(): void
    {
        $this->actingAs($this->buyer)->post('/wholesale/bulk', ['list' => UploadedFile::fake()->create('x.exe', 10)])->assertSessionHasErrors('list');
    }

    // ------------------------------------------------------------------ quotation

    public function test_a_buyer_asks_staff_answer_and_the_buyer_accepts(): void
    {
        $quotes = app(Quotes::class);
        $quote = $quotes->request($this->buyer, 'Napa 500mg', '200 boxes');
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'quote')->count());

        $quotes->answer($quote, $this->staff, 54000, 7, 'Includes delivery');
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'quote_answered')->count());
        $this->assertTrue($quote->fresh()->isOpenToAccept());

        $done = $quotes->respond($quote->fresh(), $this->buyer, 'accept');

        $this->assertSame('accepted', $done->status);
        $this->assertSame(2, Notice::where('user_id', $this->staff->id)->where('kind', 'quote')->count());
    }

    public function test_a_price_that_has_run_out_cannot_be_accepted(): void
    {
        $quotes = app(Quotes::class);
        $quote = $quotes->answer($quotes->request($this->buyer, 'Napa', null), $this->staff, 100, 1);

        $this->travel(3)->days();

        $this->assertFalse($quote->fresh()->isOpenToAccept());
        $this->expectException(StockException::class);
        $quotes->respond($quote->fresh(), $this->buyer, 'accept');
    }

    public function test_declining_cancelling_and_other_peoples_quotes(): void
    {
        $quotes = app(Quotes::class);
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine']]);
        $quote = $quotes->answer($quotes->request($this->buyer, 'Napa', null), $this->staff, 100, 5);

        try {
            $quotes->respond($quote->fresh(), $other, 'accept');
            $this->fail('not theirs');
        } catch (StockException) {
        }

        $this->assertSame('declined', $quotes->respond($quote->fresh(), $this->buyer, 'decline')->status);
        $this->assertSame('cancelled', $quotes->cancel($quotes->request($this->buyer, 'Seclo', null), $this->buyer)->status);
    }

    public function test_a_request_needs_words_and_there_is_a_limit_of_open_ones(): void
    {
        $quotes = app(Quotes::class);
        foreach (range(1, 5) as $i) {
            $quotes->request($this->buyer, "Thing $i", null);
        }

        $this->expectException(StockException::class);
        $quotes->request($this->buyer, 'One more', null);
    }

    public function test_the_pages(): void
    {
        $this->actingAs($this->buyer)->post('/wholesale/quotes', ['request' => 'Napa 500mg', 'quantity' => '200 boxes'])->assertSessionHasNoErrors();
        $quote = Quote::firstOrFail();

        $this->actingAs($this->staff)->get('/admin/quotes')->assertInertia(fn (Assert $p) => $p->component('admin/quotes')->has('asking', 1)->where('asking.0.buyer', 'Rahim Pharma'));
        $this->actingAs($this->staff)->post("/admin/quotes/{$quote->id}/answer", ['total' => 54000, 'days' => 7])->assertSessionHasNoErrors();

        $this->actingAs($this->buyer)->get('/wholesale/quotes')->assertInertia(fn (Assert $p) => $p->component('wholesale/quotes')->where('quotes.0.total', 54000)->where('quotes.0.can_accept', true));
        $this->actingAs($this->buyer)->post("/wholesale/quotes/{$quote->id}/respond", ['answer' => 'accept'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $quote->fresh()->status);

        $this->actingAs($this->staff)->get('/admin/quotes')->assertInertia(fn (Assert $p) => $p->has('accepted', 1));
    }

    public function test_others_cannot_reach_the_buyers_quotes_or_ours(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $quote = app(Quotes::class)->request($this->buyer, 'Napa', null);

        $this->actingAs($shop)->get('/wholesale/quotes')->assertRedirect('/');
        $this->actingAs($this->buyer)->get('/admin/quotes')->assertRedirect('/');
        $this->actingAs($this->buyer)->post("/admin/quotes/{$quote->id}/answer", ['total' => 1, 'days' => 1])->assertRedirect('/');
    }
}
