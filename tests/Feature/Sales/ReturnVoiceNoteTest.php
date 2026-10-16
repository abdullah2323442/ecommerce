<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Role;
use App\Models\SaleReturn;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 12: a return needs a reason, and a voice note is allowed too. Staff can play it where they answer returns. */
class ReturnVoiceNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $buyer;

    private Invoice $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $napa->id, 'units' => 20]]]);
        $stock->setMasterSync(true);

        $this->actingAs($this->buyer);
        $this->post('/cart', ['medicine_id' => $napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $this->post('/checkout', ['name' => 'Rina', 'phone' => '01722222222', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery']);
        $this->order = Invoice::latest('id')->firstOrFail();

        $this->actingAs($this->staff);
        $this->post("/admin/orders/{$this->order->id}/lines/{$this->order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$this->order->id}/complete");
        $this->post("/admin/counter/{$this->order->id}/finalise")->assertSessionHasNoErrors();
    }

    private function request(array $extra = [])
    {
        $line = $this->order->lines()->firstOrFail();

        return $this->actingAs($this->buyer)->post('/my-orders/returns', array_merge([
            'invoice_id' => $this->order->id, 'items' => [['line_id' => $line->id, 'unit' => 'strip', 'quantity' => 1]], 'reason_code' => 'damaged', 'reason' => 'The strip was torn.', 'resolution' => 'credit',
        ], $extra));
    }

    public function test_a_buyer_leaves_a_voice_note_with_the_return_and_staff_play_it(): void
    {
        $this->request(['voice' => UploadedFile::fake()->createWithContent('voice.webm', str_repeat('a', 1500))])->assertSessionHasNoErrors();

        $return = SaleReturn::firstOrFail();
        $this->assertNotNull($return->voice_path);
        Storage::assertExists($return->voice_path);

        $this->actingAs($this->staff)->get('/admin/returns')->assertInertia(fn (Assert $p) => $p->where('queue.0.has_voice', true));
        $this->get("/admin/returns/{$return->id}/voice")->assertOk();
    }

    public function test_a_return_without_a_voice_note_works_as_before(): void
    {
        $this->request()->assertSessionHasNoErrors();

        $this->assertNull(SaleReturn::firstOrFail()->voice_path);
        $this->actingAs($this->staff)->get('/admin/returns')->assertInertia(fn (Assert $p) => $p->where('queue.0.has_voice', false));
        $this->get('/admin/returns/'.SaleReturn::firstOrFail()->id.'/voice')->assertRedirect('/');
    }

    public function test_only_audio_files_are_taken(): void
    {
        $this->request(['voice' => UploadedFile::fake()->create('voice.exe', 10)])->assertSessionHasErrors('voice');
        $this->assertSame(0, SaleReturn::count());
    }

    public function test_the_voice_note_is_private_to_staff_who_answer_returns(): void
    {
        $this->request(['voice' => UploadedFile::fake()->createWithContent('voice.webm', str_repeat('a', 1500))]);
        $return = SaleReturn::firstOrFail();

        $this->actingAs($this->buyer)->get("/admin/returns/{$return->id}/voice")->assertRedirect('/');
        $noReturns = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);
        $this->actingAs($noReturns)->get("/admin/returns/{$return->id}/voice")->assertRedirect('/');
    }
}
