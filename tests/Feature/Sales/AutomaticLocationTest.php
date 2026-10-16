<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Address;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 20: "Use my location" fills the address from the buyer's place; the buyer can still change everything. */
class AutomaticLocationTest extends TestCase
{
    use RefreshDatabase;

    private const OSM = 'https://nominatim.openstreetmap.org';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SafeUrl::resolveUsing(fn () => ['8.8.8.8']);
        Cache::flush();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveUsing(null);
        parent::tearDown();
    }

    private function connect(string $key = 'map:nominatim', bool $on = true): void
    {
        $input = $key === 'map:google' ? ['api_key' => 'GKEY-123456'] : ['contact_email' => 'shop@example.com', 'base_url' => self::OSM];
        app(Integrations::class)->save($key, $input, $on, 'live', [], $this->admin);
    }

    /** What OpenStreetMap answers for a point in Mirpur, Dhaka. */
    private function osmDhaka(array $over = []): array
    {
        return ['address' => $over + ['road' => 'Road 2', 'suburb' => 'Mirpur', 'city' => 'Dhaka', 'state_district' => 'Dhaka District', 'state' => 'Dhaka Division', 'country_code' => 'bd']];
    }

    public function test_it_is_off_until_a_map_service_is_connected(): void
    {
        $this->getJson('/location/lookup?lat=23.78&lng=90.40')->assertStatus(422)->assertJsonPath('message', fn ($m) => $m !== '');
        Http::assertNothingSent();
    }

    public function test_openstreetmap_answer_becomes_district_area_and_street(): void
    {
        $this->connect();
        Http::fake([self::OSM.'/*' => Http::response($this->osmDhaka())]);

        $this->getJson('/location/lookup?lat=23.8103&lng=90.4125')->assertOk()->assertExactJson(['district' => 'Dhaka', 'area' => 'Mirpur', 'line' => 'Road 2']);

        Http::assertSent(fn ($r) => str_starts_with($r->url(), self::OSM.'/reverse') && $r['lat'] == 23.8103 && $r['format'] === 'jsonv2' && str_contains($r->header('User-Agent')[0], 'shop@example.com'));
    }

    public function test_old_district_spellings_match_ours(): void
    {
        $this->connect();
        Http::fake([self::OSM.'/*' => Http::response($this->osmDhaka(['state_district' => 'Chittagong District', 'city' => 'Chittagong', 'suburb' => 'Pahartali']))]);

        $this->getJson('/location/lookup?lat=22.36&lng=91.78')->assertOk()->assertJsonPath('district', 'Chattogram');
    }

    public function test_an_unknown_district_is_left_empty_for_the_buyer_to_choose(): void
    {
        $this->connect();
        Http::fake([self::OSM.'/*' => Http::response($this->osmDhaka(['state_district' => 'Nowhere District', 'city' => 'Nowhere', 'state' => 'Nowhere Division']))]);

        $this->getJson('/location/lookup?lat=23.8&lng=90.4')->assertOk()->assertJsonPath('district', null);
    }

    public function test_a_place_outside_bangladesh_is_refused_without_asking_anyone(): void
    {
        $this->connect();
        Http::fake();

        $this->getJson('/location/lookup?lat=51.5&lng=-0.12')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_bad_numbers_are_refused(): void
    {
        $this->connect();
        Http::fake();

        $this->getJson('/location/lookup?lat=abc&lng=90')->assertStatus(422);
        $this->getJson('/location/lookup?lat=23.8')->assertStatus(422);
        $this->getJson('/location/lookup?lat=95&lng=90')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_same_place_is_asked_only_once(): void
    {
        $this->connect();
        Http::fake([self::OSM.'/*' => Http::response($this->osmDhaka())]);

        $this->getJson('/location/lookup?lat=23.81031&lng=90.41251')->assertOk();
        $this->getJson('/location/lookup?lat=23.81033&lng=90.41249')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_the_map_service_being_down_gives_a_plain_sentence(): void
    {
        $this->connect();
        $down = true;
        Http::fake(function () use (&$down) {
            return $down ? throw new ConnectionException('dns failure secret-host') : Http::response('boom', 500);
        });

        $r = $this->getJson('/location/lookup?lat=23.8&lng=90.4')->assertStatus(422);
        $this->assertStringNotContainsString('secret-host', $r->getContent());

        $down = false;
        $this->getJson('/location/lookup?lat=23.8&lng=90.4')->assertStatus(422);
    }

    public function test_a_service_address_that_is_not_public_is_not_called(): void
    {
        SafeUrl::resolveUsing(fn () => ['10.0.0.5']);
        $this->connect();
        Http::fake();

        $this->getJson('/location/lookup?lat=23.8&lng=90.4')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_hostile_text_in_the_answer_is_cleaned_and_cut(): void
    {
        $this->connect();
        Http::fake([self::OSM.'/*' => Http::response($this->osmDhaka(['suburb' => '<script>alert(1)</script>Mirpur'.str_repeat('x', 200)]))]);

        $area = $this->getJson('/location/lookup?lat=23.8&lng=90.4')->assertOk()->json('area');

        $this->assertStringNotContainsString('<', $area);
        $this->assertLessThanOrEqual(80, mb_strlen($area));
    }

    public function test_google_answer_is_read(): void
    {
        $this->connect('map:google');
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'OK', 'results' => [['address_components' => [
            ['long_name' => '12', 'types' => ['street_number']], ['long_name' => 'Road 2', 'types' => ['route']], ['long_name' => 'Mirpur', 'types' => ['sublocality_level_1', 'sublocality']],
            ['long_name' => 'Dhaka', 'types' => ['locality']], ['long_name' => 'Dhaka District', 'types' => ['administrative_area_level_2']], ['long_name' => 'Dhaka Division', 'types' => ['administrative_area_level_1']],
        ]]]])]);

        $this->getJson('/location/lookup?lat=23.8103&lng=90.4125')->assertOk()->assertExactJson(['district' => 'Dhaka', 'area' => 'Mirpur', 'line' => '12 Road 2']);
        Http::assertSent(fn ($r) => $r['key'] === 'GKEY-123456' && $r['region'] === 'bd');
    }

    public function test_google_refusing_the_key_gives_a_plain_sentence(): void
    {
        $this->connect('map:google');
        Http::fake(['maps.googleapis.com/*' => Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'The provided API key is invalid. GKEY-123456'])]);

        $r = $this->getJson('/location/lookup?lat=23.8&lng=90.4')->assertStatus(422);
        $this->assertStringNotContainsString('GKEY', $r->getContent());
    }

    public function test_the_test_button_checks_a_known_place(): void
    {
        $this->connect();
        $answer = $this->osmDhaka();
        Http::fake(function () use (&$answer) {
            return Http::response($answer);
        });
        $r = app(Integrations::class)->test('map:nominatim', [], $this->admin);
        $this->assertTrue($r['ok']);

        $answer = $this->osmDhaka(['state_district' => 'Sylhet District', 'city' => 'Sylhet', 'state' => 'Sylhet Division']);
        $this->assertFalse(app(Integrations::class)->test('map:nominatim', [], $this->admin)['ok']);
    }

    public function test_the_checkout_page_knows_whether_to_show_the_button(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]));
        $napa = $this->medicineInStock();
        $this->post('/cart', ['medicine_id' => $napa->id, 'unit' => 'strip', 'quantity' => 1]);

        $this->get('/checkout')->assertInertia(fn (Assert $p) => $p->where('locate', false));

        $this->connect();
        $this->get('/checkout')->assertInertia(fn (Assert $p) => $p->where('locate', true));

        $this->connect('map:nominatim', false);
        $this->get('/checkout')->assertInertia(fn (Assert $p) => $p->where('locate', false));
    }

    public function test_the_place_is_kept_with_the_order_and_the_saved_address(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($buyer);
        $napa = $this->medicineInStock();
        $this->post('/cart', ['medicine_id' => $napa->id, 'unit' => 'strip', 'quantity' => 1]);

        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery', 'lat' => 23.810312, 'lng' => 90.412512, 'save_address' => true])->assertSessionHasNoErrors();

        $order = Invoice::latest('id')->first();
        $this->assertEquals(23.810312, $order->ship_to['lat']);
        $this->assertEquals(90.412512, $order->ship_to['lng']);
        $this->assertEquals(23.810312, (float) Address::where('user_id', $buyer->id)->value('lat'));
    }

    public function test_an_order_without_a_place_still_works(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]));
        $napa = $this->medicineInStock();
        $this->post('/cart', ['medicine_id' => $napa->id, 'unit' => 'strip', 'quantity' => 1]);

        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery'])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('lat', Invoice::latest('id')->first()->ship_to);
    }

    public function test_a_place_outside_the_country_is_not_accepted_with_an_order(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]));
        $napa = $this->medicineInStock();
        $this->post('/cart', ['medicine_id' => $napa->id, 'unit' => 'strip', 'quantity' => 1]);

        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery', 'lat' => 51.5, 'lng' => -0.12])->assertSessionHasErrors('lat');
    }

    public function test_the_browser_is_allowed_to_ask_for_the_place_on_our_own_pages_only(): void
    {
        $policy = (string) $this->get('/checkout')->headers->get('Permissions-Policy');

        $this->assertStringContainsString('geolocation=(self)', $policy);
        $this->assertStringContainsString('camera=()', $policy);
    }

    public function test_the_secret_key_never_reaches_the_checkout_page(): void
    {
        $this->connect('map:google');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]));
        $napa = $this->medicineInStock();
        $this->post('/cart', ['medicine_id' => $napa->id, 'unit' => 'strip', 'quantity' => 1]);

        $this->assertStringNotContainsString('GKEY-123456', $this->get('/checkout')->getContent());
    }

    private function medicineInStock(): Medicine
    {
        $napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $napa->id, 'units' => 50]],
        ]);
        $stock->setMasterSync(true);

        return $napa;
    }
}
