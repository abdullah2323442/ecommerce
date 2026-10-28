<?php

namespace Tests\Feature\Ledger;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\PurchaseInvoice;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The credit ledger, companies' side (spec ch 28 note 12): a purchase left unpaid is owed until its due day, and payments fill what falls due first. */
class PayablesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StockSource $square;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->square = StockSource::create(['name' => 'Square direct', 'type' => 'company', 'default_percent' => 12]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'mrp_box' => 100, 'mrp_piece' => 1, 'is_active' => true,
        ]);
    }

    /** A purchase of 10 boxes at 88 a box is 880 taka. */
    private function receive(array $over = [], ?StockSource $source = null)
    {
        static $n = 0;
        $n++;

        return $this->actingAs($this->admin)->post('/admin/stock/receive', array_merge([
            'source_id' => ($source ?? $this->square)->id, 'invoice_number' => 'INV-'.$n, 'invoice_date' => now()->toDateString(),
            'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ], $over));
    }

    private function owe(float $total, float $paid, ?string $due, ?StockSource $source = null, ?string $date = null): PurchaseInvoice
    {
        static $m = 0;
        $m++;

        return PurchaseInvoice::create([
            'stock_source_id' => ($source ?? $this->square)->id, 'invoice_number' => 'OWE-'.$m, 'invoice_date' => $date ?? now()->toDateString(),
            'total_cost' => $total, 'paid_amount' => $paid, 'due_date' => $due,
        ]);
    }

    public function test_a_purchase_is_paid_in_full_unless_the_buyer_says_otherwise(): void
    {
        $this->receive()->assertSessionHasNoErrors();

        $p = PurchaseInvoice::firstOrFail();
        $this->assertSame(['880.00', '880.00'], [(string) $p->total_cost, (string) $p->paid_amount]);
        $this->assertNull($p->due_date);
        $this->assertSame(0.0, $p->owed());
    }

    public function test_part_paid_purchase_is_owed_to_the_company_by_its_due_day(): void
    {
        $due = now()->addDays(15)->toDateString();
        $this->receive(['paid_now' => 300, 'due_date' => $due])->assertSessionHasNoErrors();

        $p = PurchaseInvoice::firstOrFail();
        $this->assertSame('300.00', (string) $p->paid_amount);
        $this->assertSame($due, $p->due_date->toDateString());
        $this->assertSame(580.0, $p->owed());

        $this->get('/admin/payables')->assertInertia(fn (Assert $page) => $page->where('total', 580)->where('rows.0.name', 'Square direct')->where('rows.0.owed', 580)->where('rows.0.next_due', $due));
    }

    public function test_something_left_unpaid_needs_a_due_day_and_cannot_be_more_than_the_total(): void
    {
        $this->receive(['paid_now' => 0])->assertSessionHasErrors('due_date');
        $this->receive(['paid_now' => 900, 'due_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('paid_now');
        $this->receive(['paid_now' => 100, 'due_date' => now()->subDay()->toDateString()])->assertSessionHasErrors('due_date');
        $this->assertSame(0, PurchaseInvoice::count());

        $this->receive(['paid_now' => 880])->assertSessionHasNoErrors(); // paid in full needs no day
        $this->assertSame(1, PurchaseInvoice::count());
    }

    public function test_the_list_shows_each_source_with_what_is_late_most_overdue_first(): void
    {
        $other = StockSource::create(['name' => 'Beximco direct', 'type' => 'company', 'default_percent' => 10]);
        $this->owe(1000, 0, now()->addDays(10)->toDateString());
        $this->owe(500, 100, now()->subDays(5)->toDateString(), $other);
        $this->owe(200, 200, now()->subDays(40)->toDateString(), $other); // paid, never listed

        $this->actingAs($this->admin)->get('/admin/payables')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/payables/index')->where('total', 1400)->where('late.invoices', 1)->where('late.owed', 400)
            ->where('rows.0.name', 'Beximco direct')->where('rows.0.late_owed', 400)->where('rows.0.owed', 400)->where('rows.0.invoices', 1)
            ->where('rows.1.name', 'Square direct')->where('rows.1.late_owed', 0));
    }

    public function test_a_payment_fills_the_invoice_that_falls_due_first_and_each_part_is_kept(): void
    {
        $later = $this->owe(700, 0, now()->addDays(30)->toDateString());
        $sooner = $this->owe(400, 0, now()->addDays(3)->toDateString());
        $undated = $this->owe(100, 0, null);

        $this->actingAs($this->admin)->post("/admin/payables/{$this->square->id}/payment", ['amount' => 600, 'method' => 'bank', 'note' => 'Cheque 4471'])->assertSessionHasNoErrors();

        $this->assertSame(0.0, $sooner->fresh()->owed());
        $this->assertSame(500.0, $later->fresh()->owed()); // 700 less the 200 left after the first invoice
        $this->assertSame(100.0, $undated->fresh()->owed()); // undated falls last
        $this->assertSame([400.0, 200.0], SupplierPayment::orderBy('id')->get()->map(fn ($p) => (float) $p->amount)->all());
        $this->assertSame('Cheque 4471', SupplierPayment::first()->note);
        $this->assertSame(600.0, (float) ActivityLog::where('action', 'payables.payment')->firstOrFail()->changes['amount']);
    }

    public function test_a_payment_is_never_more_than_we_owe_or_zero_or_by_an_unknown_way(): void
    {
        $this->owe(500, 0, now()->addDays(3)->toDateString());
        $this->actingAs($this->admin);
        $url = "/admin/payables/{$this->square->id}/payment";

        $this->post($url, ['amount' => 501, 'method' => 'cash'])->assertSessionHasErrors('payment');
        $this->post($url, ['amount' => 0, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post($url, ['amount' => 100, 'method' => 'barter'])->assertSessionHasErrors('method');
        $this->assertSame(0, SupplierPayment::count());

        $this->post($url, ['amount' => 500, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->post($url, ['amount' => 1, 'method' => 'cash'])->assertSessionHasErrors('payment');
    }

    public function test_the_statement_shows_open_invoices_by_due_day_and_the_payments_made(): void
    {
        $sooner = $this->owe(400, 0, now()->subDays(2)->toDateString());
        $this->owe(700, 0, now()->addDays(20)->toDateString());
        $this->actingAs($this->admin)->post("/admin/payables/{$this->square->id}/payment", ['amount' => 150, 'method' => 'cash']);

        $this->get("/admin/payables/{$this->square->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/payables/show')->where('source.name', 'Square direct')->where('account.owed', 950)->has('account.open', 2)
            ->where('account.open.0.number', $sooner->invoice_number)->where('account.open.0.owed', 250)->where('account.open.0.late', true)
            ->where('account.open.1.late', false)
            ->where('account.payments.0.amount', 150)->where('account.payments.0.invoice', $sooner->invoice_number));
    }

    public function test_what_was_entered_before_the_ledger_existed_counts_as_paid(): void
    {
        $this->assertTrue(Schema::hasColumns('purchase_invoices', ['paid_amount', 'due_date']));
        $this->owe(300, 300, null);

        $this->actingAs($this->admin)->get('/admin/payables')->assertInertia(fn (Assert $p) => $p->where('total', 0)->has('rows', 0));
    }

    public function test_purchase_money_is_the_owners_alone(): void
    {
        $this->owe(500, 0, now()->addDays(3)->toDateString());
        $this->get('/admin/payables')->assertRedirect('/login');

        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Wholesaler, AccountType::Staff] as $type) {
            $u = User::factory()->create(['account_type' => $type]);
            $this->actingAs($u)->get('/admin/payables')->assertRedirect('/');
            $this->actingAs($u)->post("/admin/payables/{$this->square->id}/payment", ['amount' => 100, 'method' => 'cash'])->assertRedirect('/');
        }

        $this->assertSame(0, SupplierPayment::count());

        $stock = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Stock', 'permissions' => ['stock']])->id]);
        $this->actingAs($stock)->get('/admin/payables')->assertOk();
    }
}
