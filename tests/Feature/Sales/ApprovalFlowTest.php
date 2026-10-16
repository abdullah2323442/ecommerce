<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\ApprovalRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\ApprovalService;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    private Medicine $napa;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin, 'name' => 'Owner']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Robiul']);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 10])->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->invoices = app(InvoiceService::class);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [ // cost 240
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20]],
        ]);
        $stock->setMasterSync(true);
    }

    private function retailDraft(): Invoice
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $this->invoices->addLine($draft, $this->napa, 'box', 2, $this->staff);

        return $draft;
    }

    public function test_staff_stop_at_their_limit_and_the_admin_is_told_about_the_try(): void
    {
        $draft = $this->retailDraft();

        $this->actingAs($this->staff)->put("/admin/counter/{$draft->id}/discount", ['percent' => 8])->assertSessionHasErrors('invoice');

        $notice = Notice::where('user_id', $this->admin->id)->where('kind', 'staff_attempt')->firstOrFail();
        $this->assertStringContainsString('Robiul', $notice->body);
        $this->assertStringContainsString('8', $notice->body);
    }

    public function test_the_admin_one_tap_yes_applies_the_discount_and_records_who_approved_it(): void
    {
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8])->assertSessionHasNoErrors();
        $req = ApprovalRequest::firstOrFail();

        $this->assertSame('0.00', (string) $draft->fresh()->end_discount_percent, 'nothing yet');
        $this->assertSame(1, Notice::where('user_id', $this->admin->id)->where('kind', 'approval_request')->count());

        $this->actingAs($this->admin)->post("/admin/approvals/{$req->id}/approve")->assertSessionHasNoErrors();

        $this->assertSame('8.00', (string) $draft->fresh()->end_discount_percent);
        $this->assertSame('approved', $req->fresh()->status);
        $this->assertTrue($req->fresh()->applied);
        $log = ActivityLog::where('action', 'approval.approved')->firstOrFail();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'approval_yes')->count());
    }

    public function test_no_leaves_the_rate_alone(): void
    {
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8]);
        $req = ApprovalRequest::firstOrFail();

        $this->actingAs($this->admin)->post("/admin/approvals/{$req->id}/reject");
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame('0.00', (string) $draft->fresh()->end_discount_percent);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'approval_no')->count());
    }

    public function test_an_unanswered_request_cancels_itself_after_the_set_time(): void
    {
        Carbon::setTestNow('2026-10-13 10:00');
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8]);
        $req = ApprovalRequest::firstOrFail();

        Carbon::setTestNow('2026-10-13 10:09');
        $this->assertSame(1, app(ApprovalService::class)->pendingCount());

        Carbon::setTestNow('2026-10-13 10:11');
        $this->assertSame(0, app(ApprovalService::class)->pendingCount());
        $this->assertSame('expired', $req->fresh()->status);

        $this->actingAs($this->admin)->post("/admin/approvals/{$req->id}/approve")->assertSessionHasErrors('approval');
        $this->assertSame('0.00', (string) $draft->fresh()->end_discount_percent);
        Carbon::setTestNow();
    }

    public function test_the_expiry_time_is_a_setting_and_a_command_does_it_too(): void
    {
        Setting::write('approvals.expire_minutes', 1);
        Carbon::setTestNow('2026-10-13 10:00');
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8]);

        Carbon::setTestNow('2026-10-13 10:02');
        $this->artisan('approvals:expire')->assertSuccessful();
        $this->assertSame('expired', ApprovalRequest::firstOrFail()->status);
        Carbon::setTestNow();
    }

    public function test_asking_twice_refreshes_the_same_request(): void
    {
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8]);
        $this->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 9]);

        $this->assertSame(1, ApprovalRequest::count());
        $this->assertSame('9.00', (string) ApprovalRequest::first()->value);
        $this->assertSame(1, Notice::where('kind', 'approval_request')->count(), 'the admin is not pinged twice');
    }

    public function test_a_shop_invoice_percent_works_the_same_way(): void
    {
        $draft = $this->invoices->open('paikari', $this->staff, $this->shop);
        $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->staff);
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'invoice_percent', 'value' => 15]);

        $this->actingAs($this->admin)->post('/admin/approvals/'.ApprovalRequest::first()->id.'/approve');

        $this->assertSame('15.00', (string) $draft->fresh()->invoice_percent);
        $this->assertSame('255.00', (string) $draft->lines()->first()->unit_price);
    }

    public function test_a_price_below_cost_on_an_online_order_goes_through_approval_too(): void
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);
        $order = Invoice::firstOrFail();
        $line = $order->lines()->first();

        $this->actingAs($this->staff)->put("/admin/orders/{$order->id}/lines/{$line->id}/price", ['price' => 200])->assertSessionHasErrors('order');
        $this->post('/admin/approvals', ['invoice_id' => $order->id, 'kind' => 'line_price', 'value' => 200, 'line_id' => $line->id])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/admin/approvals/'.ApprovalRequest::first()->id.'/approve');
        $this->assertSame('200.00', (string) $line->fresh()->unit_price);
    }

    public function test_approving_after_the_draft_is_finished_is_recorded_but_not_applied(): void
    {
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8]);
        $this->invoices->finalise($draft, $this->staff);

        $this->actingAs($this->admin)->post('/admin/approvals/'.ApprovalRequest::first()->id.'/approve')->assertSessionHasNoErrors();
        $this->assertFalse(ApprovalRequest::first()->applied);
        $this->assertSame('0.00', (string) $draft->fresh()->end_discount_percent);
    }

    public function test_only_the_admin_decides_and_the_admin_never_needs_to_ask(): void
    {
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8]);
        $req = ApprovalRequest::firstOrFail();

        $this->actingAs($this->staff)->post("/admin/approvals/{$req->id}/approve")->assertRedirect('/');
        $this->actingAs($this->staff)->get('/admin/approvals')->assertRedirect('/');
        $this->actingAs($this->shop)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8])->assertRedirect('/');
        $this->actingAs($this->admin)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8])->assertSessionHasErrors('invoice');
        $this->assertSame('pending', $req->fresh()->status);
    }

    public function test_a_per_staff_limit_beats_the_role_wide_one(): void
    {
        $draft = $this->retailDraft();
        $this->staff->forceFill(['discount_limit' => 10])->save();

        $this->actingAs($this->staff->fresh())->put("/admin/counter/{$draft->id}/discount", ['percent' => 8])->assertSessionHasNoErrors();
        $this->assertSame('8.00', (string) $draft->fresh()->end_discount_percent);

        $this->put("/admin/counter/{$draft->id}/discount", ['percent' => 12])->assertSessionHasErrors('invoice');
    }

    public function test_the_admin_sees_the_pending_count_and_the_staff_screen_sees_the_answer(): void
    {
        $draft = $this->retailDraft();
        $this->actingAs($this->staff)->post('/admin/approvals', ['invoice_id' => $draft->id, 'kind' => 'end_discount', 'value' => 8]);

        $this->actingAs($this->admin)->get('/admin/approvals')->assertInertia(fn (Assert $p) => $p->where('pending.0.staff', 'Robiul')->where('pending.0.value', 8)->where('auth.pending', 1));
        $this->actingAs($this->staff)->get("/admin/counter/{$draft->id}")->assertInertia(fn (Assert $p) => $p->where('approvals.0.status', 'pending')->where('isAdmin', false)->where('auth.pending', 0));
    }
}
