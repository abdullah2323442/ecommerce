<?php

namespace Tests\Feature\Sync;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use App\Models\Integration;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\StockSource;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Stock\StockService;
use App\Services\Sync\PharmaTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** One stock with PharmaTrack: quantities travel in pieces, medicines are found by name, and every stock change on this side is told to it. */
class PharmaTrackPiecesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Medicine $napa;

    private Medicine $syrup;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        SafeUrl::resolveUsing(fn () => ['93.184.216.34']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square Pharmaceuticals']);
        // A box of 10 strips of 10 tablets, so 100 pieces; and a bottle sold loose.
        $this->napa = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'packaging_type' => 'box', 'strips_per_box' => 10, 'pieces_per_strip' => 10, 'mrp_piece' => 2.5, 'mrp_strip' => 25, 'mrp_box' => 250, 'is_active' => true]);
        $this->syrup = Medicine::create(['company_id' => $company->id, 'brand_name' => 'Napa Syrup', 'strength' => '60 ml', 'packaging_type' => 'pcs', 'mrp_piece' => 40, 'mrp_box' => 40, 'is_active' => true]);
        $source = StockSource::create(['name' => 'Src', 'type' => 'company', 'default_percent' => 10]);
        app(StockService::class)->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10], ['medicine_id' => $this->syrup->id, 'units' => 20]]]);
        StockMovement::query()->delete();
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveUsing(null);
        parent::tearDown();
    }

    private function on(array $settings = []): void
    {
        app(Integrations::class)->save(PharmaTrack::KEY, $settings + ['base_url' => 'https://pharmatrack.example.com', 'api_key' => 'PT-KEY'], true, 'live', [], $this->admin);
        $this->token = (string) Integration::where('key', PharmaTrack::KEY)->firstOrFail()->credentials['inbound_token'];
    }

    private function hdr(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function onHand(Medicine $m): float
    {
        return app(StockService::class)->onHand($m);
    }

    public function test_a_sale_in_pieces_takes_the_right_share_of_a_box_at_the_right_price(): void
    {
        $this->on();

        // 30 tablets of a 100-tablet box is 0.3 of a box, sold at 2 a tablet = 200 a box.
        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-1', 'lines' => [['medicine_id' => $this->napa->id, 'unit' => 'piece', 'units' => 30, 'unit_price' => 2]]], $this->hdr())->assertOk()->assertJson(['status' => 'ok']);

        $this->assertEqualsWithDelta(9.7, $this->onHand($this->napa), 0.0001);
        $sold = StockMovement::where('medicine_id', $this->napa->id)->where('type', 'sale')->firstOrFail();
        $this->assertEqualsWithDelta(200.0, (float) $sold->unit_price, 0.01);
    }

    public function test_a_loose_medicine_counts_a_piece_as_one_unit(): void
    {
        $this->on();

        $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-2', 'lines' => [['name' => 'Napa Syrup', 'strength' => '60ml', 'unit' => 'piece', 'units' => 3]]], $this->hdr())->assertOk();

        $this->assertEquals(17, $this->onHand($this->syrup));
    }

    public function test_a_line_can_name_its_medicine_with_no_id_or_barcode_and_a_wrong_or_unclear_name_is_reported(): void
    {
        $this->on();

        $r = $this->postJson('/webhooks/pharmatrack/sales', ['ref' => 'PT-3', 'lines' => [
            ['name' => 'napa', 'strength' => '500mg', 'company' => 'Square', 'unit' => 'piece', 'units' => 10],
            ['name' => 'Napa', 'strength' => '999 mg', 'unit' => 'piece', 'units' => 1],
            ['name' => 'Nonesuch', 'unit' => 'piece', 'units' => 1],
        ]], $this->hdr())->assertOk();

        $this->assertSame('partial', $r->json('status'));
        $this->assertCount(2, $r->json('unknown'));
        $this->assertEqualsWithDelta(9.9, $this->onHand($this->napa), 0.0001);
    }

    public function test_a_count_in_pieces_sets_the_stock_unit_figure(): void
    {
        $this->on();

        $this->postJson('/webhooks/pharmatrack/counts', ['ref' => 'C-1', 'lines' => [['name' => 'Napa', 'strength' => '500 mg', 'unit' => 'piece', 'on_hand' => 450]]], $this->hdr())->assertOk();

        $this->assertEqualsWithDelta(4.5, $this->onHand($this->napa), 0.0001);
    }

    public function test_an_adjustment_adds_and_takes_off_is_applied_once_and_reports_what_it_cannot_do(): void
    {
        $this->on();
        $body = ['ref' => 'A-1', 'reason' => 'Return in the shop', 'lines' => [['name' => 'Napa', 'strength' => '500 mg', 'unit' => 'piece', 'units' => 50], ['name' => 'Napa Syrup', 'strength' => '60 ml', 'unit' => 'piece', 'units' => -2]]];

        $this->postJson('/webhooks/pharmatrack/adjustments', $body, $this->hdr())->assertOk()->assertJson(['status' => 'ok']);
        $this->assertEqualsWithDelta(10.5, $this->onHand($this->napa), 0.0001);
        $this->assertEquals(18, $this->onHand($this->syrup));

        $this->postJson('/webhooks/pharmatrack/adjustments', $body, $this->hdr())->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertEqualsWithDelta(10.5, $this->onHand($this->napa), 0.0001);

        // Taking off more than there is leaves nothing, not a negative stock.
        $this->postJson('/webhooks/pharmatrack/adjustments', ['ref' => 'A-2', 'lines' => [['name' => 'Napa Syrup', 'unit' => 'piece', 'units' => -500]]], $this->hdr())->assertOk();
        $this->assertEquals(0, $this->onHand($this->syrup));
    }

    public function test_an_adjustment_for_a_medicine_never_purchased_here_is_reported_not_invented(): void
    {
        $this->on();
        $fresh = Medicine::create(['company_id' => $this->napa->company_id, 'brand_name' => 'Fresh', 'strength' => '5 mg', 'packaging_type' => 'box', 'mrp_piece' => 1, 'mrp_box' => 10, 'is_active' => true]);

        $r = $this->postJson('/webhooks/pharmatrack/adjustments', ['ref' => 'A-3', 'lines' => [['name' => 'Fresh', 'unit' => 'piece', 'units' => 5]]], $this->hdr())->assertOk();

        $this->assertSame('partial', $r->json('status'));
        $this->assertCount(1, $r->json('failed'));
        $this->assertEquals(0, $this->onHand($fresh));
    }

    public function test_bad_adjustments_are_refused(): void
    {
        $this->on();

        foreach ([['ref' => 'X', 'lines' => []], ['ref' => 'X', 'lines' => [['name' => 'Napa', 'units' => 0]]], ['ref' => 'X', 'lines' => [['units' => 5]]], ['lines' => [['name' => 'Napa', 'units' => 5]]]] as $body) {
            $this->postJson('/webhooks/pharmatrack/adjustments', $body, $this->hdr())->assertUnprocessable();
        }

        $this->postJson('/webhooks/pharmatrack/adjustments', ['ref' => 'X', 'lines' => [['name' => 'Napa', 'units' => 5]]])->assertUnauthorized();
        $this->assertSame(0, StockMovement::count());
    }

    public function test_the_stock_page_tells_pharmatrack_the_company_and_how_many_pieces_make_a_unit(): void
    {
        $this->on();

        $rows = collect($this->getJson('/webhooks/pharmatrack/stock', $this->hdr())->assertOk()->json('data'))->keyBy('brand');

        $this->assertEquals(100, $rows['Napa']['pieces_per_unit']);
        $this->assertEquals(1, $rows['Napa Syrup']['pieces_per_unit']);
        $this->assertSame('Square Pharmaceuticals', $rows['Napa']['company']);
    }

    public function test_a_correction_a_purchase_and_a_return_here_are_told_to_pharmatrack_in_pieces(): void
    {
        Http::fake(['pharmatrack.example.com/*' => Http::response(['status' => 'ok'], 200)]);
        $this->on();

        app(StockService::class)->correct($this->napa, 9.5, 'broken', $this->admin, 'dropped a strip box');
        $source = StockSource::first();
        app(StockService::class)->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 2]]]);

        $changes = SyncEvent::where('kind', 'adjustment')->orderBy('id')->get();
        $this->assertCount(2, $changes);
        $this->assertSame(-50.0, (float) $changes[0]->payload['lines'][0]['units']);
        $this->assertSame(200.0, (float) $changes[1]->payload['lines'][0]['units']);
        $this->assertSame('piece', $changes[1]->payload['lines'][0]['unit']);
        $this->assertSame('Napa', $changes[1]->payload['lines'][0]['name']);
        Http::assertSent(fn ($r) => $r->url() === 'https://pharmatrack.example.com/integrations/ecommerce/adjustments' && $r->hasHeader('Authorization', 'Bearer PT-KEY'));
        $this->assertSame(['ok', 'ok'], $changes->map(fn ($e) => $e->fresh()->status)->all());
    }

    public function test_what_pharmatrack_told_us_is_never_told_back(): void
    {
        Http::fake();
        $this->on();

        $this->postJson('/webhooks/pharmatrack/adjustments', ['ref' => 'E-1', 'lines' => [['name' => 'Napa', 'strength' => '500 mg', 'unit' => 'piece', 'units' => 50]]], $this->hdr())->assertOk();
        $this->postJson('/webhooks/pharmatrack/counts', ['ref' => 'E-2', 'lines' => [['name' => 'Napa', 'strength' => '500 mg', 'unit' => 'piece', 'on_hand' => 200]]], $this->hdr())->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, SyncEvent::where('direction', 'out')->count());
    }

    public function test_stock_changes_are_not_sent_while_the_switch_is_off(): void
    {
        Http::fake();
        $this->on(['push_online_sales' => 'no']);

        app(StockService::class)->correct($this->napa, 5, 'broken', $this->admin);

        Http::assertNothingSent();
        $this->assertSame(0, SyncEvent::count());
    }

    public function test_a_change_is_sent_as_whole_pieces_even_when_the_box_holds_a_fraction(): void
    {
        Http::fake(['pharmatrack.example.com/*' => Http::response(['status' => 'ok'], 200)]);
        $this->on();
        $m = $this->napa;
        $m->update(['strips_per_box' => 12]); // a 120-piece box

        // 99.996 pieces is how 0.8333 of a 120-piece box reads back.
        app(PharmaTrack::class)->announceChange($m, -0.8333, 'Correction', 'fix:test');

        $this->assertSame(-100.0, (float) SyncEvent::firstOrFail()->payload['lines'][0]['units']);
        $this->assertSame(100.0, PharmaTrack::wholePieces(99.996));
        $this->assertSame(2.5, PharmaTrack::wholePieces(2.5));
    }

    public function test_pharmatrack_can_ask_for_the_stock_of_chosen_medicines_only(): void
    {
        $this->on();

        $rows = $this->getJson('/webhooks/pharmatrack/stock?ids='.$this->napa->id, $this->hdr())->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Napa', $rows[0]['brand']);
    }
}
