<?php

namespace Tests\Feature\Sync;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Jobs\PushToPharmaTrack;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\Notice;
use App\Models\StockSource;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use App\Services\Sync\PharmaTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Item 612, full PharmaTrack sync: one stock for the pharmacy, with PharmaTrack writing here by password and this site telling PharmaTrack about online orders. */
class PharmaTrackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Medicine $napa;

    private Medicine $seclo;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        // Names become public addresses without the network.
        SafeUrl::resolveUsing(fn (string $host) => $host === 'localhost' ? ['127.0.0.1'] : ['93.184.216.34']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square']);
        $this->napa = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'packaging_type' => 'box', 'mrp_piece' => 10, 'mrp_box' => 100, 'is_active' => true]);
        $this->seclo = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Seclo', 'strength' => '20 mg', 'packaging_type' => 'box', 'mrp_piece' => 6, 'mrp_box' => 60, 'is_active' => true]);
        MedicineBarcode::create(['medicine_id' => $this->napa->id, 'code' => '8901234567890']);
        MedicineBarcode::create(['medicine_id' => $this->napa->id, 'code' => '8909999999999']);
        MedicineBarcode::create(['medicine_id' => $this->seclo->id, 'code' => '8905555555555']);
        $source = StockSource::create(['name' => 'Src', 'type' => 'company', 'default_percent' => 10]);
        app(StockService::class)->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10], ['medicine_id' => $this->seclo->id, 'units' => 4]]]);
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveUsing(null);
        parent::tearDown();
    }

    /** Switch the link on the way the owner does, and keep the password it made. */
    private function on(array $fields = [], string $mode = 'live'): void
    {
        app(Integrations::class)->save(PharmaTrack::KEY, $fields, true, $mode, [], $this->admin);
        $this->token = (string) Integration::where('key', PharmaTrack::KEY)->firstOrFail()->credentials['inbound_token'];
    }

    /** Add to the saved key and settings the way the owner's save would (a secret goes in encrypted). */
    private function keep(array $creds = [], ?array $settings = null): void
    {
        $row = Integration::where('key', PharmaTrack::KEY)->firstOrFail();
        $row->credentials = array_merge((array) $row->credentials, $creds);

        if ($settings !== null) {
            $row->settings = $settings;
        }

        $row->save();
        app(Integrations::class)->forget();
    }

    private function hdr(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json'];
    }

    private function onHand(Medicine $m): float
    {
        return app(StockService::class)->onHand($m);
    }

    // ------------------------------------------------------------------ the password

    public function test_switching_the_link_on_makes_the_password_and_nothing_is_accepted_before(): void
    {
        $this->getJson('/webhooks/pharmatrack/ping', $this->hdr('anything'))->assertUnauthorized();

        $this->on();

        $this->assertStringStartsWith('pt_', $this->token);
        $this->getJson('/webhooks/pharmatrack/ping', $this->hdr())->assertOk()->assertJson(['ok' => true]);
    }

    public function test_the_wrong_missing_or_old_password_is_refused_with_no_hint(): void
    {
        $this->on();

        $this->getJson('/webhooks/pharmatrack/stock')->assertUnauthorized();
        $this->getJson('/webhooks/pharmatrack/stock', $this->hdr('wrong'))->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);
        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'x'], $this->hdr(''))->assertUnauthorized();
        $this->assertSame(0, SyncEvent::count());
    }

    public function test_nothing_is_accepted_while_the_link_is_off_or_in_test_mode(): void
    {
        $this->on([], 'test');
        $this->getJson('/webhooks/pharmatrack/ping', $this->hdr())->assertUnauthorized();

        app(Integrations::class)->save(PharmaTrack::KEY, [], false, 'live', [], $this->admin);
        $this->getJson('/webhooks/pharmatrack/ping', $this->hdr())->assertUnauthorized();
    }

    public function test_the_owner_makes_a_new_password_and_the_old_one_stops_at_once(): void
    {
        $this->on();
        $old = $this->token;

        $r = $this->withSession(['auth.password_confirmed_at' => time()])->actingAs($this->admin)->post('/admin/pharmatrack/token')->assertSessionHas('pharmatrack_token');
        $new = session('pharmatrack_token');

        $this->assertNotSame($old, $new);
        $this->get('/admin/pharmatrack')->assertInertia(fn (Assert $p) => $p->where('token', $new)->where('link.has_token', true)); // shown the once
        $this->get('/admin/pharmatrack')->assertInertia(fn (Assert $p) => $p->where('token', null));
        $this->getJson('/webhooks/pharmatrack/ping', $this->hdr($old))->assertUnauthorized();
        $this->getJson('/webhooks/pharmatrack/ping', $this->hdr($new))->assertOk();
    }

    // ------------------------------------------------------------------ the stock

    public function test_pharmatrack_reads_the_stock_with_barcodes_and_the_unit_it_is_counted_in(): void
    {
        $this->on();

        $r = $this->getJson('/webhooks/pharmatrack/stock', $this->hdr())->assertOk();

        $napa = collect($r->json('data'))->firstWhere('medicine_id', $this->napa->id);
        $this->assertSame(10, (int) $napa['on_hand']);
        $this->assertSame('box', $napa['unit']);
        $this->assertEqualsCanonicalizing(['8901234567890', '8909999999999'], $napa['barcodes']);
        $this->assertSame(100.0, (float) $napa['mrp_unit']);
        $this->assertNull($r->json('next_page'));
    }

    public function test_the_stock_comes_a_page_at_a_time_and_only_what_moved_if_asked(): void
    {
        $this->on();

        $this->getJson('/webhooks/pharmatrack/stock?per_page=1', $this->hdr())->assertJsonCount(1, 'data')->assertJson(['next_page' => 2]);
        $this->getJson('/webhooks/pharmatrack/stock?per_page=1&page=2', $this->hdr())->assertJsonCount(1, 'data')->assertJson(['next_page' => null]);

        $this->travel(2)->hours();
        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'R1', 'lines' => [['barcode' => '8901234567890', 'units' => 1]]], $this->hdr())->assertOk();
        $moved = $this->getJson('/webhooks/pharmatrack/stock?updated_since='.urlencode(now()->subHour()->toIso8601String()), $this->hdr())->json('data');
        $this->assertSame([$this->napa->id], array_column($moved, 'medicine_id'));
    }

    // ------------------------------------------------------------------ sales made in PharmaTrack

    public function test_a_sale_made_in_pharmatrack_takes_the_goods_off_this_stock_by_barcode_or_id(): void
    {
        $this->on();

        $r = $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-1001', 'sold_at' => now()->toIso8601String(), 'lines' => [
            ['barcode' => ' 8901234567890 ', 'units' => 2, 'unit_price' => 95], ['medicine_id' => $this->seclo->id, 'units' => 1],
        ]], $this->hdr())->assertOk();

        $this->assertSame('ok', $r->json('status'));
        $this->assertSame(8.0, $this->onHand($this->napa));
        $this->assertSame(3.0, $this->onHand($this->seclo));
        $this->assertDatabaseHas('sync_events', ['direction' => 'in', 'kind' => 'sale', 'ref' => 'PT-1001', 'status' => 'ok']);
        $this->assertDatabaseHas('stock_movements', ['medicine_id' => $this->napa->id, 'type' => 'sale', 'units' => -2]);
    }

    public function test_the_same_sale_sent_twice_is_taken_off_once(): void
    {
        $this->on();
        $body = ['ref' => 'PT-1002', 'lines' => [['barcode' => '8901234567890', 'units' => 3]]];

        $this->postJson('/webhooks/pharmatrack/sales', $body, $this->hdr())->assertOk()->assertJson(['status' => 'ok']);
        $this->postJson('/webhooks/pharmatrack/sales', $body, $this->hdr())->assertOk()->assertJson(['status' => 'duplicate', 'earlier' => 'ok']);

        $this->assertSame(7.0, $this->onHand($this->napa));
        $this->assertSame(1, SyncEvent::count());
    }

    public function test_a_sale_is_never_refused_when_it_is_more_than_we_hold_what_there_is_is_taken_and_the_owner_told(): void
    {
        $this->on();
        Notice::query()->delete();

        $r = $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-1003', 'lines' => [['medicine_id' => $this->seclo->id, 'units' => 6]]], $this->hdr())->assertOk();

        $this->assertSame('partial', $r->json('status'));
        $this->assertSame(0.0, $this->onHand($this->seclo));
        $this->assertEquals(6, $r->json('short.0.sold'));
        $this->assertEquals(4, $r->json('short.0.here'));
        app()->setLocale('en');
        $this->assertStringContainsString('Short on', SyncEvent::firstOrFail()->summaryText());
        $this->assertSame(1, Notice::where('user_id', $this->admin->id)->where('kind', 'stock_mismatch')->count());
    }

    public function test_an_unknown_medicine_is_reported_and_the_rest_still_go(): void
    {
        $this->on();

        $r = $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-1004', 'lines' => [['barcode' => '0000000000000', 'units' => 1], ['barcode' => '8901234567890', 'units' => 1]]], $this->hdr())->assertOk();

        $this->assertSame('partial', $r->json('status'));
        $this->assertSame(['0000000000000'], $r->json('unknown'));
        $this->assertSame(9.0, $this->onHand($this->napa));
    }

    public function test_a_sale_below_cost_is_recorded_not_refused(): void
    {
        $this->on();

        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-1005', 'lines' => [['barcode' => '8901234567890', 'units' => 1, 'unit_price' => 1]]], $this->hdr())->assertOk()->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('stock_movements', ['medicine_id' => $this->napa->id, 'is_loss' => true]);
    }

    public function test_bad_messages_are_refused_with_the_reason_and_leave_the_stock_alone(): void
    {
        $this->on();

        foreach ([
            [],
            ['ref' => 'x'],
            ['ref' => 'x', 'lines' => []],
            ['ref' => str_repeat('r', 81), 'lines' => [['barcode' => '8901234567890', 'units' => 1]]],
            ['ref' => 'x', 'lines' => [['barcode' => '8901234567890', 'units' => 0]]],
            ['ref' => 'x', 'lines' => [['barcode' => '8901234567890', 'units' => 'lots']]],
            ['ref' => 'x', 'lines' => [['units' => 1]]],
        ] as $bad) {
            $this->postJson('/webhooks/pharmatrack/sales', $bad, $this->hdr())->assertStatus(422)->assertJsonStructure(['message']);
        }

        $this->assertSame(10.0, $this->onHand($this->napa));
    }

    public function test_a_message_that_failed_can_be_sent_again_once_the_cause_is_fixed(): void
    {
        $this->on();
        User::where('id', $this->admin->id)->update(['account_type' => AccountType::Public->value]); // no owner account to write under

        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-1006', 'lines' => [['barcode' => '8901234567890', 'units' => 1]]], $this->hdr())->assertStatus(422);
        $this->assertSame('failed', SyncEvent::firstOrFail()->status);

        User::where('id', $this->admin->id)->update(['account_type' => AccountType::Admin->value]);
        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-1006', 'lines' => [['barcode' => '8901234567890', 'units' => 1]]], $this->hdr())->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame(9.0, $this->onHand($this->napa));
        $this->assertSame(2, SyncEvent::firstOrFail()->attempts);
        $this->assertSame(1, SyncEvent::count());
    }

    // ------------------------------------------------------------------ counts

    public function test_a_count_from_pharmatrack_sets_the_stock_and_writes_down_why(): void
    {
        $this->on();

        $r = $this->postJson('/webhooks/pharmatrack/counts', ['ref' => 'COUNT-1', 'lines' => [['barcode' => '8901234567890', 'on_hand' => 7], ['medicine_id' => $this->seclo->id, 'on_hand' => 6]]], $this->hdr())->assertOk();

        $this->assertSame('ok', $r->json('status'));
        $this->assertSame(7.0, $this->onHand($this->napa));
        $this->assertSame(6.0, $this->onHand($this->seclo));
        $this->assertDatabaseHas('stock_movements', ['medicine_id' => $this->napa->id, 'reason' => 'Count from PharmaTrack']);
    }

    public function test_a_count_of_zero_is_allowed_and_a_repeat_changes_nothing(): void
    {
        $this->on();
        $body = ['ref' => 'COUNT-2', 'lines' => [['medicine_id' => $this->napa->id, 'on_hand' => 0]]];

        $this->postJson('/webhooks/pharmatrack/counts', $body, $this->hdr())->assertOk();
        $this->assertSame(0.0, $this->onHand($this->napa));

        $this->postJson('/webhooks/pharmatrack/counts', $body, $this->hdr())->assertJson(['status' => 'duplicate']);
    }

    // ------------------------------------------------------------------ telling PharmaTrack

    private function onlineOrder(): Invoice
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $memo = Invoice::create(['kind' => 'paikari', 'channel' => 'online', 'status' => 'final', 'customer_id' => $shop->id, 'number' => 'M2026-000777', 'total' => 200, 'finalised_at' => now(), 'opened_at' => now()]);
        InvoiceLine::create(['invoice_id' => $memo->id, 'medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 2, 'verify_status' => 'full', 'stock_units' => 2, 'unit_mrp' => 100, 'unit_price' => 95]);
        InvoiceLine::create(['invoice_id' => $memo->id, 'medicine_id' => $this->seclo->id, 'unit' => 'box', 'quantity' => 1, 'verify_status' => 'unavailable', 'stock_units' => 0, 'unit_mrp' => 60, 'unit_price' => 55]);

        return $memo;
    }

    public function test_a_finished_online_order_is_queued_for_pharmatrack_with_what_was_really_supplied(): void
    {
        Queue::fake();
        $this->on(['base_url' => 'https://pharmatrack.example.com', 'api_key' => 'PT-KEY-1', 'push_online_sales' => 'yes']);
        $memo = $this->onlineOrder();

        $memo->update(['completed_at' => now()]);
        $memo->update(['note' => 'touched again']);

        Queue::assertPushed(PushToPharmaTrack::class, 1);
        $e = SyncEvent::firstOrFail();
        $this->assertSame(['out', 'online_sale', 'invoice:M2026-000777', 'pending'], [$e->direction, $e->kind, $e->ref, $e->status]);
        $this->assertCount(1, $e->payload['lines']); // the line that could not be supplied is not sent
        $this->assertSame(2.0, (float) $e->payload['lines'][0]['units']);
        $this->assertEqualsCanonicalizing(['8901234567890', '8909999999999'], $e->payload['lines'][0]['barcodes']);
    }

    public function test_the_message_goes_to_pharmatrack_with_our_key_and_is_marked_done(): void
    {
        Http::fake(['pharmatrack.example.com/*' => Http::response(['ok' => true], 200)]);
        $this->on(['base_url' => 'https://pharmatrack.example.com', 'push_online_sales' => 'yes'], 'live');
        $this->keep(['api_key' => 'PT-KEY-1']);

        $this->onlineOrder()->update(['completed_at' => now()]);

        Http::assertSent(fn ($r) => $r->url() === 'https://pharmatrack.example.com/integrations/ecommerce/online-sales' && $r->hasHeader('Authorization', 'Bearer PT-KEY-1') && $r['ref'] === 'invoice:M2026-000777' && $r['lines'][0]['units'] == 2);
        $this->assertSame('ok', SyncEvent::firstOrFail()->status);
    }

    public function test_a_refusal_is_kept_and_the_owner_can_send_it_again(): void
    {
        Http::fake(['pharmatrack.example.com/*' => Http::response('no', 500)]);
        $this->on(['base_url' => 'https://pharmatrack.example.com', 'push_online_sales' => 'yes']);
        $this->keep(['api_key' => 'PT-KEY-1']);

        try {
            $this->onlineOrder()->update(['completed_at' => now()]);
        } catch (\Throwable) {
            // the queue runs at once in tests and reports the refusal
        }

        $this->assertSame('failed', SyncEvent::firstOrFail()->status);
        app()->setLocale('en');
        $this->assertStringContainsString('500', SyncEvent::firstOrFail()->errorText());

        Queue::fake();
        $this->assertSame(1, app(PharmaTrack::class)->retryFailed());
        Queue::assertPushed(PushToPharmaTrack::class);
        $this->assertSame('pending', SyncEvent::firstOrFail()->status);
    }

    public function test_nothing_is_sent_when_there_is_no_address_or_the_owner_switched_pushing_off(): void
    {
        Queue::fake();
        $this->on();
        $this->onlineOrder()->update(['completed_at' => now()]);
        Queue::assertNothingPushed();

        app(Integrations::class)->save(PharmaTrack::KEY, ['base_url' => 'https://pharmatrack.example.com', 'api_key' => 'K', 'push_online_sales' => 'no'], true, 'live', [], $this->admin);
        $other = Invoice::create(['kind' => 'paikari', 'channel' => 'online', 'status' => 'final', 'customer_id' => $this->admin->id, 'number' => 'M2026-000778', 'total' => 10, 'finalised_at' => now(), 'opened_at' => now()]);
        $other->update(['completed_at' => now()]);
        Queue::assertNothingPushed();
    }

    public function test_a_counter_sale_is_not_told_to_pharmatrack_because_it_was_made_in_the_shop(): void
    {
        Queue::fake();
        $this->on(['base_url' => 'https://pharmatrack.example.com', 'push_online_sales' => 'yes']);

        Invoice::create(['kind' => 'paikari', 'channel' => 'counter', 'status' => 'final', 'customer_id' => $this->admin->id, 'number' => 'M2026-000779', 'total' => 10, 'finalised_at' => now(), 'completed_at' => now(), 'opened_at' => now()]);

        Queue::assertNothingPushed();
        $this->assertSame(0, SyncEvent::count());
    }

    public function test_the_address_must_be_a_public_https_one(): void
    {
        Queue::fake();
        Http::fake();
        $this->on(['base_url' => 'https://pharmatrack.example.com']);
        $this->keep(['api_key' => 'K'], ['base_url' => 'https://127.0.0.1', 'push_online_sales' => 'yes']);

        $this->onlineOrder()->update(['completed_at' => now()]);

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ the short list in PharmaTrack (spec ch 7, step 5)

    private function missedLine(string $status = 'unavailable'): InvoiceLine
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $memo = Invoice::create(['kind' => 'paikari', 'channel' => 'online', 'status' => 'placed', 'customer_id' => $shop->id, 'number' => 'M2026-'.random_int(100000, 999999), 'total' => 100, 'opened_at' => now()]);

        return InvoiceLine::create(['invoice_id' => $memo->id, 'medicine_id' => $this->seclo->id, 'unit' => 'box', 'quantity' => 3, 'requested_quantity' => 3, 'verify_status' => 'pending', 'stock_units' => 0, 'unit_mrp' => 60, 'unit_price' => 55]);
    }

    private function mark(InvoiceLine $line, string $status = 'unavailable'): void
    {
        app(InvoiceService::class)->verifyLine($line, $status, $status === 'partial' ? 1.0 : null, $this->admin);
    }

    public function test_a_medicine_staff_mark_not_available_is_sent_to_the_short_list_in_pharmatrack(): void
    {
        Http::fake(['pharmatrack.example.com/*' => Http::response(['status' => 'added'], 200)]);
        $this->on(['base_url' => 'https://pharmatrack.example.com']);
        $this->keep(['api_key' => 'PT-KEY-1']);
        $line = $this->missedLine();

        $this->mark($line);

        Http::assertSent(fn ($r) => $r->url() === 'https://pharmatrack.example.com/integrations/ecommerce/short-list' && $r->hasHeader('Authorization', 'Bearer PT-KEY-1')
            && $r['ref'] === 'line:'.$line->id && $r['items'][0]['name'] === 'Seclo' && $r['items'][0]['strength'] === '20 mg' && $r['items'][0]['company'] === 'Square' && $r['items'][0]['unit'] === 'box' && $r['items'][0]['quantity'] == 3);
        $this->assertSame(['out', 'short_list', 'ok'], [SyncEvent::firstOrFail()->direction, SyncEvent::firstOrFail()->kind, SyncEvent::firstOrFail()->status]);
    }

    public function test_changing_ones_mind_about_a_line_does_not_tell_pharmatrack_twice_and_a_full_or_partial_one_is_never_sent(): void
    {
        Queue::fake();
        $this->on(['base_url' => 'https://pharmatrack.example.com']);
        $this->keep(['api_key' => 'PT-KEY-1']);

        $this->mark($this->missedLine(), 'full');
        $this->assertSame(0, SyncEvent::count());

        $line = $this->missedLine();
        $this->mark($line);
        $line->refresh();
        $line->update(['verify_status' => 'pending']);
        $this->mark($line->fresh());

        $this->assertSame(1, SyncEvent::where('kind', 'short_list')->count());
        Queue::assertPushed(PushToPharmaTrack::class, 1);
    }

    public function test_the_short_list_is_not_fed_when_it_is_off_has_no_address_or_is_in_test_mode(): void
    {
        Queue::fake();

        $this->on();
        $this->mark($this->missedLine());
        $this->assertSame(0, SyncEvent::count(), 'no address');

        $this->on(['base_url' => 'https://pharmatrack.example.com', 'push_missed' => 'no']);
        $this->keep(['api_key' => 'K']);
        $this->mark($this->missedLine());
        $this->assertSame(0, SyncEvent::count(), 'switched off');

        $this->on(['base_url' => 'https://pharmatrack.example.com'], 'test');
        $this->keep(['api_key' => 'K']);
        $this->mark($this->missedLine());
        $this->assertSame(0, SyncEvent::count(), 'test mode');
        Queue::assertNothingPushed();
    }

    public function test_marking_a_line_never_fails_because_pharmatrack_is_down(): void
    {
        Http::fake(['pharmatrack.example.com/*' => Http::response('down', 503)]);
        $this->on(['base_url' => 'https://pharmatrack.example.com']);
        $this->keep(['api_key' => 'PT-KEY-1']);
        $line = $this->missedLine();

        $this->mark($line);

        $this->assertSame('unavailable', $line->fresh()->verify_status);
        $this->assertSame('failed', SyncEvent::firstOrFail()->status);
    }

    public function test_online_orders_and_stock_changes_are_told_to_pharmatrack_unless_the_owner_switches_it_off(): void
    {
        Queue::fake();
        $this->on(['base_url' => 'https://pharmatrack.example.com']);
        $this->keep(['api_key' => 'K']);

        $this->onlineOrder()->update(['completed_at' => now()]);

        Queue::assertPushed(PushToPharmaTrack::class, 1);
    }

    // ------------------------------------------------------------------ the owner's screen

    public function test_the_test_button_asks_pharmatrack_to_answer_at_its_address(): void
    {
        Http::fake(['pharmatrack.example.com/integrations/ecommerce/ping' => Http::response(['ok' => true])]);
        $this->on(['base_url' => 'https://pharmatrack.example.com']);
        $this->keep(['api_key' => 'K']);

        $this->assertTrue(app(Integrations::class)->test(PharmaTrack::KEY, [], $this->admin)['ok']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/integrations/ecommerce/ping') && $r->hasHeader('Authorization', 'Bearer K'));
    }

    public function test_the_page_shows_the_link_the_counts_and_what_failed(): void
    {
        $this->on();
        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'A', 'lines' => [['barcode' => '8901234567890', 'units' => 1]]], $this->hdr());
        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'B', 'lines' => [['medicine_id' => $this->seclo->id, 'units' => 99]]], $this->hdr());

        $this->actingAs($this->admin)->get('/admin/pharmatrack')->assertInertia(fn (Assert $p) => $p
            ->component('admin/pharmatrack')->where('link.on', true)->where('link.live', true)->where('link.has_token', true)->where('counts.ok', 1)->where('counts.partial', 1)->has('events', 2)
            ->where('base', url('/webhooks/pharmatrack')));
    }

    public function test_what_it_did_is_written_in_the_language_of_whoever_opens_the_screen(): void
    {
        $this->on();
        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'T1', 'lines' => [['barcode' => '8901234567890', 'units' => 2], ['medicine_id' => $this->seclo->id, 'units' => 9], ['barcode' => '0000', 'units' => 1]]], $this->hdr())->assertOk();
        $event = SyncEvent::firstOrFail();

        app()->setLocale('en');
        $this->assertSame('Lines taken off: 2. Short on Seclo: sold 9, here 4. Not found: 0000.', $event->summaryText());

        app()->setLocale('bn');
        $bangla = $event->summaryText();
        $this->assertStringContainsString('Seclo', $bangla);
        $this->assertStringNotContainsString('Short on', $bangla);
        $this->assertStringNotContainsString('taken off', $bangla);
        $this->assertStringContainsString('0000', $bangla);

        $event->update(['error' => SyncEvent::note([['PharmaTrack answered {n}.', ['n' => 502]]])]);
        $this->assertStringNotContainsString('answered', $event->errorText());
        $this->assertStringContainsString('502', $event->errorText());

        $event->update(['summary' => 'Plain older words.', 'error' => null]);
        $this->assertSame('Plain older words.', $event->summaryText());
        $this->assertNull($event->errorText());
    }

    public function test_only_the_owner_can_open_the_screen(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->get('/admin/pharmatrack')->assertRedirect('/login');
        foreach ([$staff, $shop] as $who) {
            $this->actingAs($who)->get('/admin/pharmatrack')->assertRedirect('/');
        }
    }
}
