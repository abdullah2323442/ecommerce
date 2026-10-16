<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\QuickNote;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickNoteMoreAndPrescriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private Medicine $napa;

    private Medicine $seclo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Pharmacy']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => '20 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make('Napa');
        $this->seclo = $make('Seclo');
        $stock = app(StockService::class);
        $source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]);
        $stock->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 50], ['medicine_id' => $this->seclo->id, 'units' => 50]]]);
        $stock->setMasterSync(true);
    }

    // ---- quick note

    public function test_a_phone_order_note_becomes_a_draft_at_the_shops_prices_and_is_done(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter/notes', ['customer_id' => $this->shop->id, 'note' => '10 Napa, 5 Seclo, will collect'])->assertSessionHasNoErrors();
        $note = QuickNote::firstOrFail();

        $this->get('/admin/counter')->assertInertia(fn (Assert $p) => $p->where('notes.0.customer', 'Rahim Pharmacy'));

        $this->post("/admin/counter/notes/{$note->id}/draft")->assertRedirect();
        $draft = Invoice::firstOrFail();
        $this->assertSame($this->shop->id, $draft->customer_id);
        $this->assertSame('10 Napa, 5 Seclo, will collect', $draft->note);
        $this->assertNotNull($note->fresh()->done_at);

        $this->get('/admin/counter')->assertInertia(fn (Assert $p) => $p->where('notes', []));
        $this->post("/admin/counter/notes/{$note->id}/draft")->assertRedirect('/');   // already used
    }

    public function test_a_note_needs_a_shop_and_only_staff_can_write_one(): void
    {
        $public = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($this->staff)->post('/admin/counter/notes', ['customer_id' => $public->id, 'note' => 'x'])->assertSessionHasErrors('customer_id');
        $this->actingAs($this->shop)->post('/admin/counter/notes', ['customer_id' => $this->shop->id, 'note' => 'x'])->assertRedirect('/');
        $this->assertSame(0, QuickNote::count());
    }

    // ---- add more after the memo

    private function checkedOrder(): Invoice
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 2]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);
        $order = Invoice::firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");

        return $order->fresh();
    }

    public function test_adding_more_after_the_memo_reopens_the_check_for_the_new_line_only(): void
    {
        $order = $this->checkedOrder();
        $this->assertSame('verified', $order->status);
        $stockAfterFirst = app(StockService::class)->onHand($this->napa);

        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/add", ['medicine_id' => $this->seclo->id, 'unit' => 'box', 'quantity' => 3])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('placed', $order->status);
        $this->assertSame(2, $order->lines()->count());
        $this->assertEqualsWithDelta($stockAfterFirst, app(StockService::class)->onHand($this->napa), 1e-6, 'checked lines keep their stock');
        $this->assertEqualsWithDelta(50.0, app(StockService::class)->onHand($this->seclo), 1e-6, 'the new line takes nothing yet');

        $new = $order->lines()->where('medicine_id', $this->seclo->id)->first();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/complete")->assertSessionHasErrors('order'); // new line unchecked
        $this->post("/admin/orders/{$order->id}/lines/{$new->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();

        $this->assertSame('verified', $order->fresh()->status);
        $this->assertSame('1500.00', (string) $order->fresh()->total); // 5 boxes at 300
    }

    public function test_more_cannot_be_added_to_a_finished_or_someone_elses_order(): void
    {
        $order = $this->checkedOrder();
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($other)->post("/paikari/orders/{$order->id}/add", ['medicine_id' => $this->seclo->id, 'unit' => 'box', 'quantity' => 1])->assertRedirect('/');

        $this->actingAs($this->staff)->post("/admin/counter/{$order->id}/finalise", ['payment_method' => 'cash']);
        $this->actingAs($this->shop)->post("/paikari/orders/{$order->id}/add", ['medicine_id' => $this->seclo->id, 'unit' => 'box', 'quantity' => 1])->assertSessionHasErrors('order');
    }

    // ---- prescriptions

    public function test_a_shop_sends_a_prescription_and_only_staff_can_open_it(): void
    {
        $this->actingAs($this->shop)->post('/paikari/prescriptions', ['file' => UploadedFile::fake()->image('rx.jpg'), 'note' => 'for patient'])->assertSessionHasNoErrors();
        $rx = Prescription::firstOrFail();
        Storage::disk('local')->assertExists($rx->path);

        $this->actingAs($this->staff)->get('/admin/prescriptions')->assertInertia(fn (Assert $p) => $p->where('unseen', 1)->where('items.0.shop', 'Rahim Pharmacy'));
        $this->get("/admin/prescriptions/{$rx->id}/file")->assertOk();

        $this->actingAs($this->shop)->get("/admin/prescriptions/{$rx->id}/file")->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get("/admin/prescriptions/{$rx->id}/file")->assertRedirect('/');
        $this->assertStringNotContainsString('storage/', $rx->path, 'never a public path');
    }

    public function test_the_unseen_count_drops_when_staff_mark_it_and_late_ones_are_flagged(): void
    {
        $this->actingAs($this->shop)->post('/paikari/prescriptions', ['file' => UploadedFile::fake()->image('rx.png')]);
        $rx = Prescription::firstOrFail();
        $rx->forceFill(['created_at' => now()->subMinutes(45)])->save();

        $this->actingAs($this->staff)->get('/admin/prescriptions')->assertInertia(fn (Assert $p) => $p->where('items.0.late', true));
        $this->post("/admin/prescriptions/{$rx->id}/seen");
        $this->get('/admin/prescriptions')->assertInertia(fn (Assert $p) => $p->where('unseen', 0)->where('items.0.late', false));
    }

    public function test_only_pictures_and_pdfs_are_accepted(): void
    {
        $this->actingAs($this->shop)->post('/paikari/prescriptions', ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
        $this->assertSame(0, Prescription::count());
    }
}
