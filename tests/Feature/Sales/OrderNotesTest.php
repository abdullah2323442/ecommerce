<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 23: every buyer can add a note, a voice note, a photo and the time they need it by, when ordering and after. */
class OrderNotesTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $napa;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20]]]);
        $stock->setMasterSync(true);
    }

    private function voice(string $name = 'voice.webm'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_repeat('a', 2000));
    }

    private function details(array $over = []): array
    {
        return array_merge(['name' => 'Hasan Ali', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5, Road 3', 'delivery_mode' => 'delivery'], $over);
    }

    private function place(array $over = []): Invoice
    {
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $this->post('/checkout', $this->details($over))->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ with the order

    public function test_a_guest_sends_a_voice_note_a_photo_and_a_time_with_the_order(): void
    {
        $order = $this->place(['note' => 'Ring the bell', 'wanted_time' => '09:30', 'voice' => $this->voice(), 'photo' => UploadedFile::fake()->image('strip.jpg', 800, 600)]);

        $this->assertSame(['Ring the bell', '09:30'], [$order->note, $order->wanted_time]);
        Storage::assertExists($order->voice_path);
        Storage::assertExists($order->photo_path);
        $this->assertStringStartsWith('order-voice/', $order->voice_path);
        $this->assertStringStartsWith('order-photos/', $order->photo_path);
    }

    public function test_an_order_with_none_of_it_is_just_as_welcome(): void
    {
        $order = $this->place();

        $this->assertNull($order->voice_path);
        $this->assertNull($order->photo_path);
        $this->assertNull($order->wanted_time);
    }

    public function test_a_big_photo_is_made_smaller(): void
    {
        $order = $this->place(['photo' => UploadedFile::fake()->image('big.jpg', 3000, 2000)]);

        $this->assertSame(1600, getimagesizefromstring(Storage::get($order->photo_path))[0]);
    }

    public function test_a_bad_time_a_wrong_kind_of_file_or_a_huge_one_is_turned_back_before_anything_is_placed(): void
    {
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);

        $this->post('/checkout', $this->details(['wanted_time' => '9.30 am']))->assertSessionHasErrors('wanted_time');
        $this->post('/checkout', $this->details(['voice' => UploadedFile::fake()->create('voice.exe', 10)]))->assertSessionHasErrors('voice');
        $this->post('/checkout', $this->details(['voice' => UploadedFile::fake()->create('voice.mp3', 6000)]))->assertSessionHasErrors('voice');
        $this->post('/checkout', $this->details(['photo' => UploadedFile::fake()->create('rx.pdf', 10)]))->assertSessionHasErrors('photo');

        $this->assertSame(0, Invoice::count());
    }

    // ------------------------------------------------------------------ staff

    public function test_staff_see_the_time_and_can_play_the_voice_note_and_open_the_photo(): void
    {
        $order = $this->place(['wanted_time' => '09:30', 'voice' => $this->voice(), 'photo' => UploadedFile::fake()->image('strip.jpg')]);

        $this->actingAs($this->staff)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.wanted_time', '09:30')->where('order.has_voice', true)->where('order.has_photo', true));
        $this->get("/admin/orders/{$order->id}/attachment/voice")->assertOk();
        $this->get("/admin/orders/{$order->id}/attachment/photo")->assertOk();
    }

    public function test_the_files_are_private_to_staff_who_handle_orders(): void
    {
        $order = $this->place(['voice' => $this->voice()]);

        auth()->logout();
        $this->get("/admin/orders/{$order->id}/attachment/voice")->assertRedirect();

        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->get("/admin/orders/{$order->id}/attachment/voice")->assertRedirect('/');

        $counterOnly = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);
        $this->actingAs($counterOnly)->get("/admin/orders/{$order->id}/attachment/voice")->assertRedirect('/');
    }

    public function test_a_file_that_was_never_sent_is_not_there(): void
    {
        $order = $this->place();

        $this->actingAs($this->staff)->get("/admin/orders/{$order->id}/attachment/voice")->assertRedirect('/');
    }

    // ------------------------------------------------------------------ afterwards

    public function test_the_buyer_adds_to_a_placed_order_from_the_private_link(): void
    {
        $order = $this->place(['note' => 'Ring the bell']);

        $this->post("/orders/{$order->public_token}/note", ['note' => 'Before ten please', 'wanted_time' => '10:00', 'voice' => $this->voice()])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('Ring the bell | Before ten please', $order->note);
        $this->assertSame('10:00', $order->wanted_time);
        Storage::assertExists($order->voice_path);
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_add_note', true)->where('order.has_voice', true)->where('order.wanted_time', '10:00'));
    }

    public function test_a_new_voice_note_replaces_the_old_one_and_the_old_file_goes(): void
    {
        $order = $this->place(['voice' => $this->voice()]);
        $old = $order->voice_path;

        $this->post("/orders/{$order->public_token}/note", ['voice' => $this->voice('again.webm')]);

        $this->assertNotSame($old, $order->fresh()->voice_path);
        Storage::assertMissing($old);
        Storage::assertExists($order->fresh()->voice_path);
    }

    public function test_nothing_can_be_added_once_the_order_is_finished_and_an_empty_add_is_refused(): void
    {
        $order = $this->place();

        $this->post("/orders/{$order->public_token}/note", [])->assertSessionHasErrors('order');

        $order->update(['status' => 'final']);
        $this->post("/orders/{$order->public_token}/note", ['note' => 'Too late'])->assertSessionHasErrors('order');
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_add_note', false));
        $this->assertNull($order->fresh()->wanted_time);
    }

    public function test_a_note_is_cut_to_the_limit_and_the_link_must_be_the_real_one(): void
    {
        $order = $this->place();

        $this->post("/orders/{$order->public_token}/note", ['note' => str_repeat('x', 301)])->assertSessionHasErrors('note');
        $this->post('/orders/'.str_repeat('z', 40).'/note', ['note' => 'hello'])->assertRedirect('/');
    }

    // ------------------------------------------------------------------ a shop

    public function test_a_shop_sends_them_with_its_order_and_adds_more_later_to_its_own_order_only(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 10])->id]);
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($shop);
        $this->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 2])->assertSessionHasNoErrors();
        $this->post('/paikari/order', ['delivery_mode' => 'delivery', 'note' => 'Morning', 'wanted_time' => '08:00', 'voice' => $this->voice()])->assertSessionHasNoErrors();

        $order = Invoice::where('customer_id', $shop->id)->firstOrFail();
        $this->assertSame('08:00', $order->wanted_time);
        Storage::assertExists($order->voice_path);

        $this->post("/paikari/orders/{$order->id}/note", ['note' => 'And a receipt'])->assertSessionHasNoErrors();
        $this->assertSame('Morning | And a receipt', $order->fresh()->note);
        $this->get("/paikari/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.can_note', true)->where('order.has_voice', true));

        $this->actingAs($other)->post("/paikari/orders/{$order->id}/note", ['note' => 'Not mine'])->assertRedirect('/');
        $this->assertSame('Morning | And a receipt', $order->fresh()->note);
    }
}
