<?php

namespace Tests\Feature\Ledger;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PurchaseInvoice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The credit ledger, customers' side (spec ch 28 note 12, ch 06): who owes us, their statement, and payments that fill the oldest memo first. */
class ReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Karim Pharmacy', 'phone' => '01711111111']);
    }

    private function memo(array $over = []): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'kind' => 'paikari', 'channel' => 'counter', 'status' => 'final', 'customer_id' => $this->shop->id, 'number' => 'M2026-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'total' => 1000, 'paid_amount' => 0, 'finalised_at' => now(), 'opened_at' => now(),
        ], $over));
    }

    public function test_the_ledger_lists_each_customer_with_what_they_owe_biggest_first(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'name' => 'Rahim Traders']);
        $this->memo(['total' => 1000, 'paid_amount' => 400]);
        $this->memo(['total' => 500]);
        $this->memo(['total' => 3000, 'customer_id' => $other->id]);
        $this->memo(['total' => 900, 'paid_amount' => 900]); // paid: not listed
        $this->memo(['total' => 700, 'status' => 'cancelled']); // cancelled: not listed

        $this->actingAs($this->admin)->get('/admin/ledger')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/ledger/index')
            ->where('total', 4100)
            ->has('rows', 2)
            ->where('rows.0.name', 'Rahim Traders')->where('rows.0.owed', 3000)
            ->where('rows.1.name', 'Karim Pharmacy')->where('rows.1.owed', 1100)->where('rows.1.memos', 2));
    }

    public function test_a_customer_is_late_when_their_oldest_memo_is_older_than_the_limit(): void
    {
        $this->memo(['finalised_at' => now()->subDays(45)]);
        $fresh = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Nila Medico']);
        $this->memo(['customer_id' => $fresh->id, 'finalised_at' => now()->subDays(3)]);

        $this->actingAs($this->admin)->get('/admin/ledger')->assertInertia(fn (Assert $p) => $p
            ->where('rows.0.name', 'Karim Pharmacy')->where('rows.0.late', true)->where('rows.0.oldest_days', 45)
            ->where('rows.1.late', false)->where('late.customers', 1)->where('late.owed', 1000));

        Setting::write('ledger.overdue_days', 60);
        $this->get('/admin/ledger')->assertInertia(fn (Assert $p) => $p->where('late.customers', 0)->where('overdueDays', 60));
    }

    public function test_a_memo_with_no_customer_is_counted_apart_and_never_listed_under_a_name(): void
    {
        $this->memo(['customer_id' => null, 'label' => 'Customer 7', 'total' => 250]);

        $this->actingAs($this->admin)->get('/admin/ledger')->assertInertia(fn (Assert $p) => $p->has('rows', 0)->where('total', 0)->where('noCustomer.count', 1)->where('noCustomer.owed', 250));
    }

    public function test_the_list_can_be_searched_by_name_or_phone(): void
    {
        $this->memo();
        $other = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Nila Medico', 'phone' => '01722222222']);
        $this->memo(['customer_id' => $other->id]);

        $this->actingAs($this->admin)->get('/admin/ledger?q=Nila')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.name', 'Nila Medico'));
        $this->get('/admin/ledger?q=01711')->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.name', 'Karim Pharmacy'));
    }

    public function test_a_payment_fills_the_oldest_memo_first_and_each_part_is_kept(): void
    {
        $old = $this->memo(['total' => 600, 'finalised_at' => now()->subDays(20)]);
        $new = $this->memo(['total' => 800, 'finalised_at' => now()->subDays(2)]);

        $this->actingAs($this->admin)->post("/admin/ledger/{$this->shop->id}/payment", ['amount' => 900, 'method' => 'bkash', 'note' => 'Sent from the shop phone'])->assertSessionHasNoErrors();

        $this->assertSame('600.00', (string) $old->fresh()->paid_amount);
        $this->assertSame('300.00', (string) $new->fresh()->paid_amount);
        $this->assertSame([600.0, 300.0], InvoicePayment::orderBy('id')->get()->map(fn ($p) => (float) $p->amount)->all());
        $this->assertSame('Sent from the shop phone', InvoicePayment::first()->note);
        $this->assertSame($this->admin->id, InvoicePayment::first()->received_by);
        $this->assertSame(2, ActivityLog::where('action', 'invoice.payment')->count());
        $this->assertSame(900.0, (float) ActivityLog::where('action', 'ledger.payment')->firstOrFail()->changes['amount']);
    }

    public function test_a_payment_is_never_more_than_is_owed_or_zero_or_by_an_unknown_way(): void
    {
        $this->memo(['total' => 500]);
        $this->actingAs($this->admin);
        $url = "/admin/ledger/{$this->shop->id}/payment";

        $this->post($url, ['amount' => 501, 'method' => 'cash'])->assertSessionHasErrors('payment');
        $this->post($url, ['amount' => 0, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post($url, ['amount' => 100, 'method' => 'barter'])->assertSessionHasErrors('method');
        $this->assertSame(0, InvoicePayment::count());

        $this->post($url, ['amount' => 500, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->post($url, ['amount' => 1, 'method' => 'cash'])->assertSessionHasErrors('payment');
    }

    public function test_the_statement_shows_open_memos_oldest_first_and_the_payments_made(): void
    {
        $a = $this->memo(['total' => 600, 'finalised_at' => now()->subDays(10)]);
        $this->memo(['total' => 800, 'finalised_at' => now()->subDays(1)]);
        $this->actingAs($this->admin)->post("/admin/ledger/{$this->shop->id}/payment", ['amount' => 100, 'method' => 'cash']);

        $this->get("/admin/ledger/{$this->shop->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/ledger/show')
            ->where('customer.name', 'Karim Pharmacy')->where('account.owed', 1300)
            ->where('account.open.0.number', $a->number)->where('account.open.0.paid', 100)->where('account.open.0.due', 500)
            ->has('account.open', 2)
            ->where('account.payments.0.amount', 100)->where('account.payments.0.memo', $a->number)->where('account.payments.0.by', $this->admin->name));
    }

    public function test_a_payment_on_one_memo_from_all_orders_is_kept_in_the_ledger_too(): void
    {
        $memo = $this->memo(['total' => 1000, 'paid_amount' => 400]);

        $this->actingAs($this->admin)->post("/admin/invoices/{$memo->id}/payment", ['amount' => 250, 'method' => 'bkash'])->assertSessionHasNoErrors();

        $this->assertSame('650.00', (string) $memo->fresh()->paid_amount);
        $row = InvoicePayment::firstOrFail();
        $this->assertSame([$memo->id, $this->shop->id, 250.0, 'bkash'], [$row->invoice_id, $row->customer_id, (float) $row->amount, $row->method]);
    }

    public function test_only_those_who_may_see_the_reports_open_the_ledger_or_take_a_payment(): void
    {
        $this->memo();
        $this->get('/admin/ledger')->assertRedirect('/login');

        $plain = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->actingAs($plain)->get('/admin/ledger')->assertRedirect('/');
        $this->actingAs($plain)->get("/admin/ledger/{$this->shop->id}")->assertRedirect('/');
        $this->actingAs($plain)->post("/admin/ledger/{$this->shop->id}/payment", ['amount' => 100, 'method' => 'cash'])->assertRedirect('/');
        $this->actingAs($this->shop)->get('/admin/ledger')->assertRedirect('/');
        $this->assertSame(0, InvoicePayment::count());

        $reports = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Accounts', 'permissions' => ['reports']])->id]);
        $this->actingAs($reports)->get('/admin/ledger')->assertOk();
    }

    public function test_the_customer_page_says_what_they_owe_but_only_to_those_who_may_see_money(): void
    {
        $this->memo(['total' => 1000, 'paid_amount' => 250]);

        $this->actingAs($this->admin)->get("/admin/customers/{$this->shop->id}")->assertInertia(fn (Assert $p) => $p->where('owed', 750));

        $wholesale = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Customers only', 'permissions' => ['customers']])->id]);
        $this->actingAs($wholesale)->get("/admin/customers/{$this->shop->id}")->assertInertia(fn (Assert $p) => $p->where('owed', null));
    }

    public function test_the_owners_home_screen_warns_about_late_customers_and_late_company_dues(): void
    {
        $this->memo(['total' => 900, 'finalised_at' => now()->subDays(50)]);
        PurchaseInvoice::create([
            'stock_source_id' => StockSource::create(['name' => 'Square direct', 'type' => 'company'])->id, 'invoice_number' => 'X-1', 'invoice_date' => now()->subDays(30)->toDateString(),
            'total_cost' => 400, 'paid_amount' => 0, 'due_date' => now()->subDays(2)->toDateString(),
        ]);

        $titles = collect($this->actingAs($this->admin)->get('/dashboard')->assertOk()->viewData('page')['props']['alerts'] ?? [])->pluck('title');

        $this->assertTrue($titles->contains('{n} customer is late with payment'));
        $this->assertTrue($titles->contains('{n} company invoice is past its due day'));
    }
}
