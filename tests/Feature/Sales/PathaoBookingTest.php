<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Courier;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Couriers\CourierBooking;
use App\Services\Couriers\PathaoProvider;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Sales\Deliveries;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Spec ch 20: book a parcel with Pathao (their city and zone, a login token that is reused), follow it, and keep the buyer told. */
class PathaoBookingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://api-hermes.pathao.com/aladdin/api/v1';

    private User $admin;

    private User $staff;

    private Courier $courier;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        SafeUrl::resolveUsing(fn () => ['8.8.8.8']);
        Cache::flush();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->courier = Courier::create(['name' => 'Pathao', 'provider' => 'pathao', 'cod_percent' => 1, 'is_active' => true]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true, 'weight_g' => 400,
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

    private function connect(bool $on = true, string $mode = 'live'): void
    {
        app(Integrations::class)->save('courier:pathao', ['client_id' => 'CID-111', 'client_secret' => 'SECRET-222', 'username' => 'shop@example.com', 'password' => 'PW-333', 'store_id' => '77'], $on, $mode, [], $this->admin);
    }

    private function order(string $district = 'Dhaka', string $area = 'Mirpur'): Invoice
    {
        auth()->logout();
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $this->post('/checkout', ['name' => 'Hasan Ali', 'phone' => '+8801711000000', 'district' => $district, 'area' => $area, 'address' => 'House 5, Road 2', 'how_to_find' => 'Red gate', 'delivery_mode' => 'delivery']);
        $order = Invoice::latest('id')->firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();
        $order->forceFill(['courier_id' => $this->courier->id])->save();
        auth()->logout();

        return $order->fresh();
    }

    /** Pathao's side: login, lists, booking and progress. */
    private string $theirWord = 'Pending';

    private function fakePathao(array $order = [], array $extra = []): void
    {
        Http::fake($extra + [
            self::BASE.'/issue-token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 432000, 'access_token' => 'TOKEN-AAA', 'refresh_token' => 'R'], 200),
            self::BASE.'/city-list' => Http::response(['data' => ['data' => [['city_id' => 1, 'city_name' => 'Dhaka'], ['city_id' => 2, 'city_name' => 'Chittagong'], ['city_id' => 3, 'city_name' => 'Sylhet']]]], 200),
            self::BASE.'/cities/1/zone-list' => Http::response(['data' => ['data' => [['zone_id' => 10, 'zone_name' => 'Dhanmondi'], ['zone_id' => 11, 'zone_name' => 'Mirpur']]]], 200),
            self::BASE.'/cities/2/zone-list' => Http::response(['data' => ['data' => [['zone_id' => 20, 'zone_name' => 'Pahartali']]]], 200),
            self::BASE.'/stores' => Http::response(['data' => ['data' => [['store_id' => 77, 'store_name' => 'My shop']]]], 200),
            self::BASE.'/orders' => Http::response(['message' => 'Order Created Successfully', 'type' => 'success', 'code' => 200, 'data' => $order + ['consignment_id' => 'DL121224VS8TTJ', 'merchant_order_id' => 'x', 'order_status' => 'Pending', 'delivery_fee' => 80]], 200),
            self::BASE.'/orders/*/info' => fn () => Http::response(['data' => ['consignment_id' => 'DL121224VS8TTJ', 'order_status' => $this->theirWord]], 200),
        ]);
    }

    private function book(Invoice $o, array $place = [])
    {
        return $this->actingAs($this->staff)->post("/admin/orders/{$o->id}/book-courier", $place);
    }

    // ------------------------------------------------------------------ booking

    public function test_booking_sends_the_parcel_the_way_pathao_asks_and_saves_their_number(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order();

        $this->book($o)->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => $r->url() === self::BASE.'/orders' && $r->hasHeader('Authorization', 'Bearer TOKEN-AAA')
            && $r['store_id'] === 77 && $r['merchant_order_id'] === 'ORD'.$o->id && $r['recipient_name'] === 'Hasan Ali' && $r['recipient_phone'] === '01711000000'
            && $r['recipient_city'] === 1 && $r['recipient_zone'] === 11 && $r['delivery_type'] === 48 && $r['item_type'] === 2 && $r['item_weight'] == 0.5
            && $r['amount_to_collect'] === (int) round(app(Deliveries::class)->due($o->fresh())) && str_contains($r['recipient_address'], 'House 5'));

        $o = $o->fresh();
        $this->assertSame('DL121224VS8TTJ', $o->courier_consignment);
        $this->assertSame('pending', $o->courier_status);
        $this->assertNotNull($o->sent_at);
        $this->assertSame('DL121224VS8TTJ', $o->tracking);
    }

    public function test_the_login_token_is_asked_for_once_and_then_reused(): void
    {
        $this->connect();
        $this->fakePathao();

        $this->order();
        $a = $this->order();
        $b = $this->order();
        $this->book($a);
        // The second booking uses the same token without asking again.
        $b->forceFill(['courier_consignment' => null])->save();
        $this->book($b);

        Http::assertSentCount(count(Http::recorded()));
        $logins = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/issue-token'))->count();
        $this->assertSame(1, $logins);
    }

    public function test_a_stale_token_is_renewed_once_and_the_call_repeated(): void
    {
        $this->connect();
        $o = $this->order();
        $calls = 0;
        $this->fakePathao([], [
            self::BASE.'/orders' => function () use (&$calls) {
                return ++$calls === 1 ? Http::response(['message' => 'Unauthenticated'], 401) : Http::response(['data' => ['consignment_id' => 'C9', 'order_status' => 'Pending']], 200);
            },
        ]);

        $this->book($o)->assertSessionHasNoErrors();

        $this->assertSame('C9', $o->fresh()->courier_consignment);
        $this->assertSame(2, $calls);
    }

    public function test_old_district_spellings_find_pathaos_city(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order('Chattogram', 'Pahartali');

        $this->book($o)->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => $r->url() === self::BASE.'/orders' && $r['recipient_city'] === 2 && $r['recipient_zone'] === 20);
    }

    public function test_when_the_zone_cannot_be_found_staff_are_asked_to_choose_and_nothing_is_booked(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order('Dhaka', 'Some village');

        $this->book($o)->assertSessionHasErrors('courier');

        $this->assertNull($o->fresh()->courier_consignment);
        Http::assertNotSent(fn ($r) => $r->url() === self::BASE.'/orders');
    }

    public function test_staff_can_choose_the_city_and_zone_themselves(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order('Dhaka', 'Some village');

        $this->book($o, ['city_id' => 1, 'zone_id' => 10])->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => $r->url() === self::BASE.'/orders' && $r['recipient_city'] === 1 && $r['recipient_zone'] === 10);
        $this->assertNotNull($o->fresh()->courier_consignment);
    }

    public function test_the_order_page_lists_pathaos_places_and_the_guess(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order();

        $r = $this->actingAs($this->staff)->getJson("/admin/orders/{$o->id}/courier-places")->assertOk();

        $r->assertJsonPath('city_id', 1)->assertJsonPath('zone_id', 11)->assertJsonCount(3, 'cities')->assertJsonCount(2, 'zones');
        $this->actingAs($this->staff)->getJson("/admin/orders/{$o->id}/courier-places?city=2")->assertJsonPath('zones.0.name', 'Pahartali');
        $this->actingAs($this->staff)->get("/admin/orders/{$o->id}")->assertInertia(fn ($p) => $p->where('order.courier_panel.places', true));
    }

    public function test_places_are_only_for_staff_and_only_for_pathao_orders(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order();

        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->getJson("/admin/orders/{$o->id}/courier-places")->assertRedirect('/');

        $o->forceFill(['courier_id' => null])->save();
        $this->actingAs($this->staff)->getJson("/admin/orders/{$o->id}/courier-places")->assertNotFound();
    }

    // ------------------------------------------------------------------ safety

    public function test_a_parcel_is_booked_once_only(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order();

        $this->book($o)->assertSessionHasNoErrors();
        $this->book($o)->assertSessionHasErrors('courier');

        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => $p[0]->url() === self::BASE.'/orders')->count());
    }

    public function test_testing_mode_books_nothing(): void
    {
        $this->connect(true, 'test');
        $this->fakePathao();
        $o = $this->order();

        $this->book($o)->assertSessionHasErrors('courier');

        Http::assertNothingSent();
    }

    public function test_pathao_refusing_the_keys_or_being_down_gives_a_plain_sentence(): void
    {
        $this->connect();
        $o = $this->order();

        Http::fake([self::BASE.'/issue-token' => Http::response(['message' => 'invalid client SECRET-222'], 400)]);
        $r = $this->book($o)->assertSessionHasErrors('courier');
        $this->assertStringNotContainsString('SECRET', json_encode(session('errors')->getBag('default')->all()));

        Cache::flush();
        Http::fake(fn () => throw new ConnectionException('dns secret-host'));
        $this->book($o)->assertSessionHasErrors('courier');
        $this->assertNull($o->fresh()->courier_consignment);
    }

    public function test_pathao_not_taking_the_parcel_is_not_booked(): void
    {
        $this->connect();
        $o = $this->order();
        $this->fakePathao([], [self::BASE.'/orders' => Http::response(['message' => 'The given data was invalid.', 'errors' => ['recipient_phone' => ['bad']]], 422)]);

        $this->book($o)->assertSessionHasErrors('courier');

        $this->assertNull($o->fresh()->courier_consignment);
        $this->assertNull($o->fresh()->sent_at);
    }

    public function test_a_buyer_with_a_very_short_address_is_told_what_pathao_needs(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order();
        $o->forceFill(['ship_to' => ['name' => 'Al', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'x']])->save();

        $this->book($o, ['city_id' => 1, 'zone_id' => 11])->assertSessionHasErrors('courier');

        Http::assertNotSent(fn ($r) => $r->url() === self::BASE.'/orders');
    }

    // ------------------------------------------------------------------ progress

    public function test_pathaos_words_become_ours(): void
    {
        foreach (['Pending' => 'pending', 'Pickup Requested' => 'pending', 'In Transit' => 'pending', 'Assigned for Delivery' => 'pending', 'Delivered' => 'delivered', 'Delivered Payment Invoice' => 'delivered',
            'Partial Delivery' => 'partial_delivered', 'Return' => 'cancelled', 'Paid Return' => 'cancelled', 'Pickup Cancelled' => 'cancelled', 'On Hold' => 'hold', 'Delivery Failed' => 'hold', '' => 'unknown'] as $theirs => $ours) {
            $this->assertSame($ours, PathaoProvider::normalise($theirs), $theirs);
        }
    }

    public function test_asking_where_it_is_updates_the_order_and_delivered_completes_it(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order();
        $this->book($o);

        $this->theirWord = 'Delivered';
        $this->actingAs($this->staff)->post("/admin/orders/{$o->id}/courier-status")->assertSessionHasNoErrors();

        $o = $o->fresh();
        $this->assertSame('delivered', $o->courier_status);
        $this->assertNotNull($o->delivered_at);
    }

    public function test_the_sweep_follows_pathao_parcels(): void
    {
        $this->connect();
        $this->fakePathao();
        $o = $this->order();
        $this->book($o);

        $this->theirWord = 'On Hold';

        $this->assertSame(1, app(CourierBooking::class)->sweep());
        $this->assertSame('hold', $o->fresh()->courier_status);
    }

    // ------------------------------------------------------------------ connections

    public function test_the_test_button_proves_the_login_and_the_store(): void
    {
        $this->connect();
        $this->fakePathao();

        $this->assertTrue(app(Integrations::class)->test('courier:pathao', [], $this->admin)['ok']);

        Cache::flush();
        app(Integrations::class)->save('courier:pathao', ['store_id' => '999'], true, 'live', [], $this->admin);
        $r = app(Integrations::class)->test('courier:pathao', [], $this->admin);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Store ID', $r['note']);
    }

    public function test_the_keys_stay_secret_and_pathao_can_now_be_switched_on(): void
    {
        $this->connect();

        $row = Integration::where('key', 'courier:pathao')->firstOrFail();
        $this->assertTrue($row->enabled);
        $this->actingAs($this->admin)->get('/admin/connections')->assertOk();
        $this->assertStringNotContainsString('SECRET-222', $this->actingAs($this->admin)->get('/admin/connections')->getContent());
        $this->assertStringNotContainsString('PW-333', $this->actingAs($this->admin)->get('/admin/connections')->getContent());
        $this->assertContains('pathao', collect(app(CourierBooking::class)->providers())->pluck('key')->all());
    }
}
