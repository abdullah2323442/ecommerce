<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Couriers\CourierBooking;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 20: book a parcel with the courier company once, follow it, and let its messages and our asking keep the order up to date. */
class CourierBookingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://portal.steadfast.com.bd/api/v1';

    private User $admin;

    private User $staff;

    private Courier $courier;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        // No network in tests: the courier's name is pretended to be a public address.
        SafeUrl::resolveUsing(fn () => ['8.8.8.8']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Office Staff']);
        $this->courier = Courier::create(['name' => 'Steadfast', 'provider' => 'steadfast', 'cod_percent' => 1, 'is_active' => true]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 50]],
        ]);
        $stock->setMasterSync(true);
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveUsing(null);
        parent::tearDown();
    }

    private function connect(bool $on = true, string $mode = 'live', array $more = []): void
    {
        app(Integrations::class)->save('courier:steadfast', $more + ['api_key' => 'KEY-111', 'secret_key' => 'SECRET-222', 'webhook_token' => 'HOOK-333', 'base_url' => self::BASE], $on, $mode, [], $this->admin);
    }

    /** A guest order, checked by staff and ready to go by the courier. */
    private function order(int $strips = 2): Invoice
    {
        auth()->logout();
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => $strips]);
        $this->post('/checkout', ['name' => 'Hasan Ali', 'phone' => '+8801711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5, Road 2', 'how_to_find' => 'Red gate', 'delivery_mode' => 'delivery']);
        $order = Invoice::latest('id')->firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();
        $order->forceFill(['courier_id' => $this->courier->id])->save();
        auth()->logout();

        return $order->fresh();
    }

    private function fakeBooking(array $consignment = []): void
    {
        Http::fake([self::BASE.'/create_order' => Http::response(['status' => 200, 'message' => 'Consignment has been created successfully.', 'consignment' => $consignment + [
            'consignment_id' => 1424107, 'invoice' => 'x', 'tracking_code' => '15BAEB8A', 'status' => 'in_review',
        ]], 200)]);
    }

    private function book(Invoice $o)
    {
        return $this->actingAs($this->staff)->post("/admin/orders/{$o->id}/book-courier");
    }

    // ------------------------------------------------------------------ booking

    public function test_booking_sends_the_parcel_in_the_form_the_company_asks_for_and_saves_their_number(): void
    {
        $this->connect();
        $this->fakeBooking();
        $o = $this->order();
        $o->update(['paid_amount' => 60]);   // an advance: the courier collects only the rest

        $this->book($o)->assertSessionHasNoErrors();

        Http::assertSent(function ($r) use ($o) {
            return $r->url() === self::BASE.'/create_order' && $r->method() === 'POST'
                && $r->header('Api-Key') === ['KEY-111'] && $r->header('Secret-Key') === ['SECRET-222']
                && $r['invoice'] === 'ORD'.$o->id && $r['recipient_name'] === 'Hasan Ali' && $r['recipient_phone'] === '01711000000'
                && $r['recipient_address'] === 'House 5, Road 2, Red gate, Mirpur, Dhaka' && (float) $r['cod_amount'] === round((float) ($o->subtotal ?: $o->total) + (float) $o->delivery_charge - 60, 2);
        });
        $o->refresh();
        $this->assertSame('1424107', $o->courier_consignment);
        $this->assertSame('15BAEB8A', $o->tracking, 'the company\'s tracking code is the tracking note');
        $this->assertSame('in_review', $o->courier_status);
        $this->assertNotNull($o->sent_at, 'taking it to the courier is what sent means');
        $this->assertNotNull($o->courier_booked_at);
        $this->assertSame(1, ActivityLog::where('action', 'order.booked')->count());
    }

    public function test_a_parcel_is_booked_once_only(): void
    {
        $this->connect();
        $this->fakeBooking();
        $o = $this->order();

        $this->book($o)->assertSessionHasNoErrors();
        $this->book($o)->assertSessionHasErrors('courier');

        Http::assertSentCount(1);
        $this->assertSame(1, ActivityLog::where('action', 'order.booked')->count());
    }

    public function test_real_parcels_are_booked_only_when_the_connection_is_live(): void
    {
        $this->connect(true, 'test');
        Http::fake();
        $o = $this->order();

        $this->book($o)->assertSessionHasErrors('courier');

        Http::assertNothingSent();
        $this->assertNull($o->fresh()->courier_consignment);
        $this->assertNull($o->fresh()->sent_at);
    }

    public function test_it_cannot_be_booked_while_the_company_is_off_or_not_tied_to_the_courier(): void
    {
        Http::fake();
        $o = $this->order();
        $this->book($o)->assertSessionHasErrors('courier');

        $this->connect();
        $this->courier->update(['provider' => null]);
        $this->book($o)->assertSessionHasErrors('courier');

        Http::assertNothingSent();
    }

    public function test_an_order_that_is_not_checked_yet_cannot_be_booked(): void
    {
        $this->connect();
        Http::fake();
        auth()->logout();
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->post('/checkout', ['name' => 'A', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery']);
        $o = Invoice::latest('id')->firstOrFail();
        $o->forceFill(['courier_id' => $this->courier->id])->save();

        $this->book($o)->assertSessionHasErrors('courier');

        Http::assertNothingSent();
    }

    public function test_a_medicine_that_must_stay_cold_cannot_go_by_courier(): void
    {
        $this->connect();
        Http::fake();
        $this->napa->forceFill(['is_cold_chain' => true])->save();
        $o = $this->order();

        $this->book($o)->assertSessionHasErrors('courier');

        Http::assertNothingSent();
    }

    public function test_when_the_company_says_no_nothing_is_saved_and_the_order_is_not_sent(): void
    {
        $this->connect();
        Http::fake([self::BASE.'/create_order' => Http::response(['status' => 400, 'message' => 'The recipient phone must be 11 digits SECRET-LEAK'], 200)]);
        $o = $this->order();

        $res = $this->book($o)->assertSessionHasErrors('courier');

        $this->assertNull($o->fresh()->courier_consignment);
        $this->assertNull($o->fresh()->sent_at);
        $this->assertStringNotContainsString('SECRET-LEAK', json_encode(session('errors')->all()), 'what they wrote back is never shown');
    }

    public function test_a_company_that_cannot_be_reached_is_told_calmly(): void
    {
        $this->connect();
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $o = $this->order();

        $this->book($o)->assertSessionHasErrors('courier');

        $this->assertNull($o->fresh()->courier_consignment);
    }

    public function test_wrong_keys_are_said_to_be_wrong(): void
    {
        $this->connect();
        Http::fake([self::BASE.'/create_order' => Http::response(['message' => 'Unauthorized'], 401)]);
        $o = $this->order();

        $this->book($o)->assertSessionHasErrors('courier');
        $this->assertNull($o->fresh()->courier_consignment);
    }

    public function test_an_address_that_points_inside_is_never_called(): void
    {
        $this->connect(true, 'live', ['base_url' => 'https://127.0.0.1/api']);
        Http::fake();
        $o = $this->order();

        $this->book($o)->assertSessionHasErrors('courier');

        Http::assertNothingSent();
    }

    public function test_only_people_who_handle_orders_can_book(): void
    {
        $this->connect();
        Http::fake();
        $o = $this->order();
        $blind = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::where('name', 'Product manager')->value('id')]);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($blind)->post("/admin/orders/{$o->id}/book-courier")->assertRedirect('/');
        $this->actingAs($buyer)->post("/admin/orders/{$o->id}/book-courier")->assertRedirect('/');
        $this->post("/admin/orders/{$o->id}/book-courier")->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_the_order_page_tells_staff_what_to_do_or_why_not(): void
    {
        $o = $this->order();
        $page = fn () => $this->actingAs($this->staff)->get("/admin/orders/{$o->id}")->viewData('page')['props']['order']['courier_panel'];

        $this->assertStringContainsString('not switched on', $page()['blocked']);
        $this->connect(true, 'test');
        $this->assertStringContainsString('testing mode', $page()['blocked']);
        $this->connect(true, 'live');
        $this->assertNull($page()['blocked']);
        $this->assertFalse($page()['booked']);
        $this->assertGreaterThan(0, $page()['collect']);

        $this->fakeBooking();
        $this->book($o);
        $this->assertTrue($page()['booked']);
        $this->assertSame('1424107', $page()['consignment']);
        $this->assertSame('With the courier', $page()['status_label']);
    }

    public function test_an_order_with_no_company_courier_shows_no_booking_panel(): void
    {
        $o = $this->order();
        $this->courier->update(['provider' => null]);

        $this->actingAs($this->staff)->get("/admin/orders/{$o->id}")->assertInertia(fn (Assert $p) => $p->where('order.courier_panel', null));
    }

    // ------------------------------------------------------------------ following the parcel

    private function booked(): Invoice
    {
        $this->connect();
        $this->fakeBooking();
        $o = $this->order();
        $this->book($o);

        return $o->fresh();
    }

    public function test_asking_the_company_updates_the_progress(): void
    {
        $o = $this->booked();
        Http::fake([self::BASE.'/status_by_cid/1424107' => Http::response(['status' => 200, 'delivery_status' => 'hold'], 200)]);

        $this->actingAs($this->staff)->post("/admin/orders/{$o->id}/courier-status")->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => $r->url() === self::BASE.'/status_by_cid/1424107' && $r->method() === 'GET' && $r->header('Api-Key') === ['KEY-111']);
        $this->assertSame('hold', $o->fresh()->courier_status);
        $this->assertGreaterThan(0, Notice::where('kind', 'courier_problem')->count(), 'a parcel on hold comes to the office');
    }

    public function test_delivered_by_the_company_counts_as_delivered(): void
    {
        $o = $this->booked();
        Http::fake([self::BASE.'/status_by_cid/*' => Http::response(['status' => 200, 'delivery_status' => 'delivered'], 200)]);

        $this->actingAs($this->staff)->post("/admin/orders/{$o->id}/courier-status");

        $o->refresh();
        $this->assertNotNull($o->delivered_at);
        $this->assertSame('courier', $o->delivered_by);
    }

    public function test_delivered_does_not_override_a_buyer_who_said_it_did_not_come(): void
    {
        $o = $this->booked();
        $o->update(['not_received_at' => now()]);
        Http::fake([self::BASE.'/status_by_cid/*' => Http::response(['status' => 200, 'delivery_status' => 'delivered'], 200)]);

        $this->actingAs($this->staff)->post("/admin/orders/{$o->id}/courier-status");

        $this->assertNull($o->fresh()->delivered_at, 'the office looks into it');
        $this->assertSame('delivered', $o->fresh()->courier_status);
    }

    public function test_a_returned_parcel_comes_to_the_office_and_is_not_marked_delivered(): void
    {
        $o = $this->booked();

        app(CourierBooking::class)->apply($o->fresh(), 'cancelled');

        $this->assertNull($o->fresh()->delivered_at);
        $this->assertGreaterThan(0, Notice::where('kind', 'courier_problem')->count());
    }

    public function test_the_same_word_again_changes_nothing(): void
    {
        $o = $this->booked();
        $at = $o->courier_status_at;

        $this->travel(5)->minutes();
        app(CourierBooking::class)->apply($o->fresh(), 'in_review');

        $this->assertEquals($at, $o->fresh()->courier_status_at);
    }

    public function test_the_sweep_asks_about_parcels_on_their_way_only(): void
    {
        $open = $this->booked();
        $delivered = $this->booked();
        $delivered->forceFill(['courier_consignment' => '999', 'delivered_at' => now()])->save();
        $old = $this->booked();
        $old->forceFill(['courier_consignment' => '888', 'courier_booked_at' => now()->subDays(40)])->save();
        Http::fake([self::BASE.'/status_by_cid/*' => Http::response(['status' => 200, 'delivery_status' => 'partial_delivered'], 200)]);

        $changed = app(CourierBooking::class)->sweep();

        $this->assertSame(1, $changed);
        Http::assertSentCount(1);
        $this->assertSame('partial_delivered', $open->fresh()->courier_status);
    }

    public function test_the_sweep_survives_a_company_that_is_down(): void
    {
        $this->booked();
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->assertSame(0, app(CourierBooking::class)->sweep());
    }

    public function test_the_buyer_sees_a_plain_word_and_never_their_internal_number(): void
    {
        $o = $this->booked();
        $o->update(['courier_status' => 'partial_delivered']);

        $props = $this->get("/orders/{$o->public_token}")->viewData('page')['props'];

        $this->assertSame('Part delivered', $props['order']['courier_status']);
        $this->assertStringNotContainsString('1424107', json_encode($props), 'the company\'s consignment number is for the office');
        $this->assertSame('15BAEB8A', $props['order']['tracking']);
    }

    // ------------------------------------------------------------------ the company's own messages

    private function hook(array $data, ?string $token = 'HOOK-333')
    {
        return $this->postJson('/webhooks/courier/steadfast', $data, $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    public function test_the_company_can_tell_us_the_progress_with_the_password_we_gave_them(): void
    {
        $o = $this->booked();

        $this->hook(['consignment_id' => 1424107, 'invoice' => 'ORD'.$o->id, 'status' => 'delivered', 'cod_amount' => 500, 'updated_at' => '2026-10-16 10:00:00'])->assertOk();

        $this->assertSame('delivered', $o->fresh()->courier_status);
        $this->assertNotNull($o->fresh()->delivered_at);
    }

    public function test_a_wrong_or_missing_password_changes_nothing(): void
    {
        $o = $this->booked();

        $this->hook(['consignment_id' => 1424107, 'status' => 'delivered'], 'WRONG')->assertStatus(401);
        $this->hook(['consignment_id' => 1424107, 'status' => 'delivered'], null)->assertStatus(401);

        $this->assertSame('in_review', $o->fresh()->courier_status);
    }

    public function test_with_no_password_saved_the_address_does_not_exist(): void
    {
        $this->connect(true, 'live', ['webhook_token' => '']);

        $this->hook(['consignment_id' => 1, 'status' => 'delivered'], 'anything')->assertNotFound();
    }

    public function test_with_the_company_off_the_address_does_not_exist(): void
    {
        $this->connect(false);

        $this->hook(['consignment_id' => 1, 'status' => 'delivered'])->assertNotFound();
    }

    public function test_a_parcel_that_is_not_ours_is_politely_ignored(): void
    {
        $this->booked();

        $this->hook(['consignment_id' => 555555, 'status' => 'delivered'])->assertOk()->assertJson(['message' => 'Ignored.']);
    }

    public function test_the_invoice_reference_must_match_ours(): void
    {
        $o = $this->booked();

        $this->hook(['consignment_id' => 1424107, 'invoice' => 'ORD999999', 'status' => 'delivered'])->assertOk()->assertJson(['message' => 'Ignored.']);

        $this->assertSame('in_review', $o->fresh()->courier_status);
    }

    public function test_missing_fields_are_refused_and_no_page_token_is_needed(): void
    {
        $this->booked();

        $this->hook(['status' => 'delivered'])->assertStatus(422);
        $this->hook(['consignment_id' => 1424107])->assertStatus(422);
    }

    public function test_a_long_made_up_status_is_refused(): void
    {
        $this->booked();

        $this->hook(['consignment_id' => 1424107, 'status' => str_repeat('x', 100)])->assertStatus(422);
    }

    // ------------------------------------------------------------------ the owner's side

    public function test_the_test_button_proves_the_keys_with_a_harmless_call(): void
    {
        $this->connect();
        Http::fake([self::BASE.'/get_balance' => Http::response(['status' => 200, 'current_balance' => 1500], 200)]);

        $this->actingAs($this->admin)->post('/admin/connections/courier:steadfast/test')->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/get_balance') && $r->header('Secret-Key') === ['SECRET-222']);
        $row = Integration::where('key', 'courier:steadfast')->firstOrFail();
        $this->assertTrue($row->test_ok);
        $this->assertSame('Connected.', $row->test_note);
    }

    public function test_wrong_keys_show_in_the_test(): void
    {
        $this->connect();
        Http::fake([self::BASE.'/get_balance' => Http::response(['message' => 'Unauthorized'], 401)]);

        $this->actingAs($this->admin)->post('/admin/connections/courier:steadfast/test')->assertSessionHasNoErrors();

        $this->assertFalse(Integration::where('key', 'courier:steadfast')->firstOrFail()->test_ok);
    }

    public function test_the_connections_page_gives_the_address_to_hand_the_company_and_never_a_key(): void
    {
        $this->connect();

        $page = $this->actingAs($this->admin)->get('/admin/connections')->viewData('page')['props'];
        $steadfast = collect(collect($page['types'])->firstWhere('type', 'courier')['providers'])->firstWhere('key', 'courier:steadfast');

        $this->assertTrue($steadfast['ready']);
        $this->assertSame(route('courier.webhook', 'steadfast'), $steadfast['redirect_uri']);
        foreach (['KEY-111', 'SECRET-222', 'HOOK-333'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($page));
        }
    }

    public function test_the_courier_screen_ties_a_courier_to_a_company_that_exists(): void
    {
        $this->connect();
        $body = ['name' => 'Steadfast', 'cod_percent' => 1, 'is_active' => true, 'rates' => ['own' => null, 'district' => null, 'outside' => null]];

        $this->actingAs($this->admin)->put("/admin/couriers/{$this->courier->id}", $body + ['provider' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->courier->fresh()->provider);

        $this->actingAs($this->admin)->put("/admin/couriers/{$this->courier->id}", $body + ['provider' => 'steadfast'])->assertSessionHasNoErrors();
        $this->assertSame('steadfast', $this->courier->fresh()->provider);

        $this->actingAs($this->admin)->put("/admin/couriers/{$this->courier->id}", $body + ['provider' => 'bogus'])->assertSessionHasErrors('provider');
        $this->actingAs($this->admin)->get('/admin/couriers')->assertInertia(fn (Assert $p) => $p->where('providers.0.key', 'steadfast')->where('providers.0.connected', true));
    }

    public function test_only_steadfast_and_pathao_are_offered(): void
    {
        $this->assertSame(['steadfast', 'pathao'], collect(app(CourierBooking::class)->providers())->pluck('key')->all());
        $this->assertNull(config('integrations.providers.courier:redx'));
    }
}
