<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Preorder;
use App\Models\Role;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 25: every order in one list with filters, settling what is owed, and all of a customer's transactions on one page. */
class AllOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Karim Pharmacy', 'phone' => '01711111111']);
    }

    private function memo(array $over = []): Invoice
    {
        static $n = 0;
        $n++;

        return Invoice::create(array_merge([
            'kind' => 'paikari', 'channel' => 'counter', 'status' => 'final', 'customer_id' => $this->shop->id, 'number' => 'M2026-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'total' => 1000, 'paid_amount' => 1000, 'finalised_at' => now(), 'opened_at' => now(),
        ], $over));
    }

    public function test_the_list_shows_counter_and_online_memos_and_leaves_out_open_drafts(): void
    {
        $this->memo();
        $this->memo(['channel' => 'online', 'status' => 'placed', 'number' => null, 'total' => 0]);
        Invoice::create(['kind' => 'retail', 'channel' => 'counter', 'status' => 'draft', 'label' => 'Customer 1', 'opened_at' => now()]);

        $this->actingAs($this->staff)->get('/admin/invoices')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/invoices')->has('rows.data', 2));
    }

    public function test_it_filters_by_kind_channel_status_date_and_search(): void
    {
        $this->memo(['kind' => 'retail', 'customer_id' => null, 'label' => 'Customer 7']);
        $this->memo(['kind' => 'wholesale', 'channel' => 'online', 'status' => 'verified']);
        $old = $this->memo(['opened_at' => now()->subDays(40)]);
        $this->actingAs($this->staff);

        $this->get('/admin/invoices?kind=retail')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.kind', 'retail'));
        $this->get('/admin/invoices?channel=online')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
        $this->get('/admin/invoices?status=verified')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
        $this->get('/admin/invoices?from='.now()->subDays(5)->toDateString())->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        $this->get('/admin/invoices?q=Karim')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        $this->get('/admin/invoices?q=01711111111')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
        $this->get('/admin/invoices?q='.$old->number)->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
        $this->get('/admin/invoices?kind=nonsense')->assertInertia(fn (Assert $p) => $p->has('rows.data', 3));
    }

    public function test_a_guest_order_is_found_by_the_name_or_phone_they_gave(): void
    {
        Invoice::create(['kind' => 'retail', 'channel' => 'online', 'status' => 'placed', 'placed_at' => now(), 'opened_at' => now(), 'ship_to' => ['name' => 'Hasan Ali', 'phone' => '01799999999'], 'public_token' => str_repeat('a', 40)]);

        $this->actingAs($this->staff)->get('/admin/invoices?q=01799999999')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.customer', 'Hasan Ali'));
        $this->get('/admin/invoices?q=Hasan')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
    }

    public function test_what_is_still_owed_can_be_listed_and_summed(): void
    {
        $this->memo(['total' => 1000, 'paid_amount' => 400]);
        $this->memo(['total' => 500, 'paid_amount' => 500]);
        $this->memo(['total' => 200, 'paid_amount' => 0, 'status' => 'cancelled']);

        $this->actingAs($this->admin)->get('/admin/invoices?due=1')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.due', 600)->where('owed', 600)->where('canSettle', true));
    }

    public function test_only_those_who_handle_orders_see_the_list_and_only_report_viewers_settle(): void
    {
        $this->get('/admin/invoices')->assertRedirect();
        $this->actingAs($this->shop)->get('/admin/invoices')->assertRedirect('/');

        $this->actingAs($this->staff)->get('/admin/invoices')->assertInertia(fn (Assert $p) => $p->where('canSettle', false));
        $due = $this->memo(['total' => 500, 'paid_amount' => 0]);
        $this->post("/admin/invoices/{$due->id}/payment", ['amount' => 100, 'method' => 'cash'])->assertRedirect('/');
        $this->assertSame('0.00', (string) $due->fresh()->paid_amount);

        $noOrders = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Stock only', 'permissions' => ['stock']])->id]);
        $this->actingAs($noOrders)->get('/admin/invoices')->assertRedirect('/');
    }

    public function test_a_payment_is_added_capped_at_what_is_owed_and_logged(): void
    {
        $due = $this->memo(['total' => 1000, 'paid_amount' => 400]);
        $this->actingAs($this->admin);

        $this->post("/admin/invoices/{$due->id}/payment", ['amount' => 700, 'method' => 'cash'])->assertSessionHasErrors('payment');
        $this->post("/admin/invoices/{$due->id}/payment", ['amount' => 0, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post("/admin/invoices/{$due->id}/payment", ['amount' => 100, 'method' => 'barter'])->assertSessionHasErrors('method');

        $this->post("/admin/invoices/{$due->id}/payment", ['amount' => 250, 'method' => 'bkash'])->assertSessionHasNoErrors();
        $this->assertSame('650.00', (string) $due->fresh()->paid_amount);

        $this->post("/admin/invoices/{$due->id}/payment", ['amount' => 350, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('1000.00', (string) $due->fresh()->paid_amount);
        $this->post("/admin/invoices/{$due->id}/payment", ['amount' => 1, 'method' => 'cash'])->assertSessionHasErrors('payment');

        $log = ActivityLog::where('action', 'invoice.payment')->orderBy('id')->firstOrFail();
        $this->assertSame(250.0, (float) $log->changes['amount']);
        $this->assertSame('bkash', $log->changes['method']);
        $this->assertSame(2, ActivityLog::where('action', 'invoice.payment')->count());
    }

    public function test_a_payment_on_a_memo_that_is_not_finished_is_refused(): void
    {
        $open = $this->memo(['status' => 'placed', 'number' => null, 'total' => 500, 'paid_amount' => 0]);

        $this->actingAs($this->admin)->post("/admin/invoices/{$open->id}/payment", ['amount' => 100, 'method' => 'cash'])->assertSessionHasErrors('payment');
        $this->assertSame('0.00', (string) $open->fresh()->paid_amount);
    }

    public function test_the_customer_page_lists_every_kind_of_transaction_together_newest_first(): void
    {
        $company = Company::create(['name' => 'Square']);
        $napa = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 300, 'is_active' => true]);
        $old = $this->memo(['finalised_at' => now()->subDays(3), 'total' => 800, 'paid_amount' => 300]);
        $new = $this->memo(['channel' => 'online', 'status' => 'placed', 'number' => null, 'placed_at' => now()->subHour(), 'finalised_at' => null]);
        $ret = SaleReturn::create(['customer_id' => $this->shop->id, 'invoice_id' => $old->id, 'kind' => 'paikari', 'resolution' => 'credit', 'status' => 'requested', 'reason' => 'x', 'reason_code' => 'other', 'amount' => 100, 'created_by' => $this->admin->id]);
        $ret->forceFill(['created_at' => now()->subDays(2)])->save();
        $pre = Preorder::create(['customer_id' => $this->shop->id, 'status' => 'requested', 'promised_days' => 3]);
        $pre->lines()->create(['medicine_id' => $napa->id, 'unit' => 'box', 'quantity' => 2]);
        $pre->forceFill(['created_at' => now()->subMinutes(10)])->save();
        CreditEntry::create(['user_id' => $this->shop->id, 'amount' => 100, 'reason' => 'return', 'sector' => 'pharmacy']);

        $this->actingAs($this->admin)->get("/admin/customers/{$this->shop->id}")->assertInertia(fn (Assert $p) => $p
            ->has('transactions', 5)
            ->where('transactions.0.type', 'credit')->where('transactions.1.type', 'preorder')->where('transactions.1.title', 'Napa 500 mg')
            ->where('transactions.2.type', 'memo')->where('transactions.2.link', "/admin/orders/{$new->id}")
            ->where('transactions.3.type', 'return')->where('transactions.4.type', 'memo')->where('transactions.4.detail', 'paikari · counter · due'));
    }

    public function test_one_customers_transactions_never_include_another_customers(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->memo(['customer_id' => $other->id]);

        $this->actingAs($this->admin)->get("/admin/customers/{$this->shop->id}")->assertInertia(fn (Assert $p) => $p->has('transactions', 0));
    }
}
