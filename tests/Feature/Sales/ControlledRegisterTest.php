<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\ControlledSale;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27: no sale of a controlled medicine without the buyer, prescription and doctor; a register to read and print. */
class ControlledRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $admin;

    private Medicine $diazepam;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Counter Rina']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square']);
        $this->diazepam = Medicine::create([
            'company_id' => $company->id, 'brand_name' => 'Diazepam', 'strength' => '5 mg', 'form' => 'tablet', 'is_controlled' => true,
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = Medicine::create([
            'company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->diazepam->id, 'units' => 10], ['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
        $stock->setMasterSync(true);
    }

    private function details(array $over = []): array
    {
        return array_merge(['buyer_name' => 'Hasan Ali', 'buyer_phone' => '01711000000', 'buyer_address' => null, 'prescription_no' => 'RX-1042', 'doctor_name' => 'Dr. Karim'], $over);
    }

    /** A retail draft with one strip of the given medicine, opened by the counter staff. */
    private function draftWith(Medicine $m): Invoice
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $invoice = Invoice::latest('id')->firstOrFail();
        $this->post("/admin/counter/{$invoice->id}/lines", ['medicine_id' => $m->id, 'unit' => 'strip', 'quantity' => 2])->assertSessionHasNoErrors();

        return $invoice;
    }

    public function test_a_controlled_sale_cannot_be_finished_without_the_details(): void
    {
        $invoice = $this->draftWith($this->diazepam);

        $this->post("/admin/counter/{$invoice->id}/finalise")->assertSessionHasErrors('invoice');
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_it_finishes_once_the_details_are_written(): void
    {
        $invoice = $this->draftWith($this->diazepam);
        $line = $invoice->lines()->firstOrFail();

        $this->put("/admin/controlled/{$line->id}", $this->details())->assertSessionHasNoErrors();
        $this->post("/admin/counter/{$invoice->id}/finalise")->assertSessionHasNoErrors();

        $this->assertSame('final', $invoice->fresh()->status);
        $this->assertDatabaseHas('controlled_sales', ['invoice_line_id' => $line->id, 'prescription_no' => 'RX-1042', 'doctor_name' => 'Dr. Karim', 'recorded_by' => $this->staff->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'controlled.recorded']);
    }

    public function test_every_required_detail_is_asked_for(): void
    {
        $line = $this->draftWith($this->diazepam)->lines()->firstOrFail();

        foreach (['buyer_name', 'prescription_no', 'doctor_name'] as $field) {
            $this->put("/admin/controlled/{$line->id}", $this->details([$field => '']))->assertSessionHasErrors($field);
        }

        $this->put("/admin/controlled/{$line->id}", $this->details(['buyer_phone' => null, 'buyer_address' => null]))->assertSessionHasErrors('controlled');
        $this->put("/admin/controlled/{$line->id}", $this->details(['buyer_phone' => null, 'buyer_address' => 'Mirpur, Dhaka']))->assertSessionHasNoErrors();
    }

    public function test_an_ordinary_medicine_needs_nothing_extra(): void
    {
        $invoice = $this->draftWith($this->napa);
        $line = $invoice->lines()->firstOrFail();

        $this->post("/admin/counter/{$invoice->id}/finalise")->assertSessionHasNoErrors();
        $this->assertSame('final', $invoice->fresh()->status);

        $this->put("/admin/controlled/{$line->id}", $this->details())->assertSessionHasErrors('controlled');
        $this->assertSame(0, ControlledSale::count());
    }

    public function test_the_counter_screen_lists_the_controlled_lines(): void
    {
        $invoice = $this->draftWith($this->diazepam);

        $this->get("/admin/counter/{$invoice->id}")->assertInertia(fn (Assert $p) => $p
            ->has('controlled', 1)->where('controlled.0.name', 'Diazepam 5 mg')->where('controlled.0.details', null));
    }

    public function test_the_register_shows_quantity_date_and_staff_from_the_sale_itself(): void
    {
        $invoice = $this->draftWith($this->diazepam);
        $this->put('/admin/controlled/'.$invoice->lines()->firstOrFail()->id, $this->details());
        $this->post("/admin/counter/{$invoice->id}/finalise");

        $this->actingAs($this->admin)->get('/admin/controlled')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/controlled')->has('entries', 1)
            ->where('entries.0.medicine', 'Diazepam 5 mg')->where('entries.0.quantity', 2)->where('entries.0.unit', 'strip')
            ->where('entries.0.staff', 'Counter Rina')->where('entries.0.buyer_name', 'Hasan Ali')->where('entries.0.memo', $invoice->fresh()->number));
    }

    public function test_the_register_can_be_filtered_by_medicine_and_date(): void
    {
        $invoice = $this->draftWith($this->diazepam);
        $this->put('/admin/controlled/'.$invoice->lines()->firstOrFail()->id, $this->details());
        $this->post("/admin/counter/{$invoice->id}/finalise");

        $this->actingAs($this->admin);
        $this->get('/admin/controlled?medicine='.$this->napa->id)->assertInertia(fn (Assert $p) => $p->has('entries', 0));
        $this->get('/admin/controlled?medicine='.$this->diazepam->id)->assertInertia(fn (Assert $p) => $p->has('entries', 1));
        $this->get('/admin/controlled?from='.now()->addDay()->toDateString().'&to='.now()->addDays(2)->toDateString())->assertInertia(fn (Assert $p) => $p->has('entries', 0));
    }

    public function test_only_those_who_see_reports_read_the_register(): void
    {
        $this->actingAs($this->staff)->get('/admin/controlled')->assertRedirect('/');
        $this->get('/admin/controlled/print')->assertRedirect('/');
        $this->get('/admin/controlled/export')->assertRedirect('/');

        $sub = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Sub-admin')->value('id')]);
        $this->actingAs($sub)->get('/admin/controlled')->assertOk();
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->get('/admin/controlled')->assertRedirect('/');
    }

    public function test_it_downloads_as_a_csv_and_prints_on_its_own_page(): void
    {
        $invoice = $this->draftWith($this->diazepam);
        $this->put('/admin/controlled/'.$invoice->lines()->firstOrFail()->id, $this->details());
        $this->post("/admin/counter/{$invoice->id}/finalise");

        $csv = $this->actingAs($this->admin)->get('/admin/controlled/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Hasan Ali', $csv);
        $this->assertStringContainsString('RX-1042', $csv);
        $this->assertStringContainsString('Counter Rina', $csv);

        $this->get('/admin/controlled/print')->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/controlled-print')->has('entries', 1));
    }

    public function test_a_finished_entry_changes_only_by_someone_who_sees_reports_and_the_old_words_are_kept(): void
    {
        $invoice = $this->draftWith($this->diazepam);
        $line = $invoice->lines()->firstOrFail();
        $this->put("/admin/controlled/{$line->id}", $this->details());
        $this->post("/admin/counter/{$invoice->id}/finalise");

        $this->put("/admin/controlled/{$line->id}", $this->details(['doctor_name' => 'Dr. Someone Else']))->assertSessionHasErrors('controlled');
        $this->assertSame('Dr. Karim', ControlledSale::firstOrFail()->doctor_name);

        $this->actingAs($this->admin)->put("/admin/controlled/{$line->id}", $this->details(['doctor_name' => 'Dr. Rahman']))->assertSessionHasNoErrors();
        $this->assertSame('Dr. Rahman', ControlledSale::firstOrFail()->doctor_name);
        $log = ActivityLog::where('action', 'controlled.edited')->firstOrFail();
        $this->assertSame('Dr. Karim', $log->changes['before']['doctor_name']);
        $this->assertSame('Dr. Rahman', $log->changes['after']['doctor_name']);
    }

    public function test_a_corrected_memo_keeps_its_entry_and_the_old_one_is_marked_cancelled(): void
    {
        $invoice = $this->draftWith($this->diazepam);
        $this->put('/admin/controlled/'.$invoice->lines()->firstOrFail()->id, $this->details());
        $this->post("/admin/counter/{$invoice->id}/finalise");

        $this->actingAs($this->admin)->post("/admin/counter/{$invoice->id}/correct")->assertSessionHasNoErrors();
        $new = Invoice::where('corrects_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(2, ControlledSale::count());

        // The corrected draft finishes without asking again.
        $this->post("/admin/counter/{$new->id}/finalise")->assertSessionHasNoErrors();

        $this->get('/admin/controlled')->assertInertia(fn (Assert $p) => $p->has('entries', 2)->where('entries.0.cancelled', true)->where('entries.1.cancelled', false));
    }

    public function test_an_online_order_with_a_controlled_medicine_asks_staff_for_the_details_before_it_finishes(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Karim Pharmacy', 'phone' => '01800000000']);
        $this->actingAs($shop)->post('/paikari/cart', ['medicine_id' => $this->diazepam->id, 'unit' => 'box', 'quantity' => 1]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);
        $order = Invoice::firstOrFail();
        $line = $order->lines()->firstOrFail();

        $this->actingAs($this->staff)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p
            ->has('controlled', 1)->where('controlledDefaults.buyer_name', 'Karim Pharmacy'));

        $this->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();

        $this->post("/admin/counter/{$order->id}/finalise")->assertSessionHasErrors('invoice');
        $this->put("/admin/controlled/{$line->id}", $this->details(['buyer_name' => 'Karim Pharmacy']))->assertSessionHasNoErrors();
        $this->post("/admin/counter/{$order->id}/finalise")->assertSessionHasNoErrors();
        $this->assertSame('final', $order->fresh()->status);
    }

    public function test_a_shop_or_visitor_cannot_write_to_the_register(): void
    {
        $line = $this->draftWith($this->diazepam)->lines()->firstOrFail();

        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Wholesaler] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->put("/admin/controlled/{$line->id}", $this->details())->assertRedirect('/');
        }
        $this->assertSame(0, ControlledSale::count());
    }
}
