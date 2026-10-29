<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use App\Models\FoodOrder;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\ProductRequest;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\SalesReports;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The gaps the developer-versus-spec report found: each one checked from the buyer's, the counter's or the owner's side. */
class ComparisonFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private User $admin;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
        $stock->setMasterSync(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addToCart(): void
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
    }

    // ---- 157: night orders are packed that night ------------------------------------------------------------

    public function test_a_night_order_is_told_it_will_be_packed_that_night_and_a_holiday_is_told_the_next_working_day(): void
    {
        Carbon::setTestNow('2026-10-14 23:30:00');
        $this->addToCart();
        $this->post('/paikari/order', ['delivery_mode' => 'collect'])
            ->assertSessionHas('status', 'Order sent. We will pack it tonight and it will be ready in the morning.');

        Carbon::setTestNow('2026-10-16 13:00:00');
        Setting::write('orders.weekly_off', [5]);
        $this->addToCart();
        $this->post('/paikari/order', ['delivery_mode' => 'collect'])
            ->assertSessionHas('status', 'Order sent. We are closed today and will check it on the next working day.');
    }

    // ---- 148: someone else collects ----------------------------------------------------------------------------

    public function test_a_shop_can_send_someone_to_collect_and_staff_see_who(): void
    {
        $this->addToCart();
        $this->post('/paikari/order', ['delivery_mode' => 'collect', 'collector_name' => 'Karim', 'collector_phone' => '01712345678'])->assertSessionHasNoErrors();

        $order = Invoice::latest('id')->firstOrFail();
        $this->assertSame('Karim', $order->collector_name);
        $this->assertSame('01712345678', $order->collector_phone);

        $this->actingAs($this->staff)->get("/admin/orders/{$order->id}")
            ->assertInertia(fn (Assert $p) => $p->where('order.collector.name', 'Karim')->where('order.collector.phone', '01712345678'));
    }

    public function test_the_collector_needs_both_a_name_and_a_phone_and_is_dropped_when_it_is_delivered(): void
    {
        $this->addToCart();
        $this->post('/paikari/order', ['delivery_mode' => 'collect', 'collector_name' => 'Karim'])->assertSessionHasErrors('collector_phone');

        $this->post('/paikari/order', ['delivery_mode' => 'delivery', 'collector_name' => 'Karim', 'collector_phone' => '01712345678']);
        $order = Invoice::latest('id')->firstOrFail();
        $this->assertNull($order->collector_name);
    }

    // ---- 550: one press at the counter --------------------------------------------------------------------------

    public function test_the_counter_notes_what_was_asked_for_and_the_owner_sees_both_sources_together(): void
    {
        ProductRequest::create(['query' => 'Seclo 20', 'normalized' => 'seclo 20', 'source' => 'online']);

        $this->actingAs($this->staff)->post('/admin/counter/wanted', ['query' => 'Seclo 20'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_requests', ['normalized' => 'seclo 20', 'source' => 'counter']);

        $this->actingAs($this->admin)->get('/admin/requests')->assertInertia(fn (Assert $p) => $p
            ->where('groups.data.0.total', 2)->where('groups.data.0.counter', 1));
    }

    public function test_only_counter_staff_can_note_a_request_and_it_must_name_something(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter/wanted', ['query' => 'x'])->assertSessionHasErrors('query');
        $this->actingAs($this->shop)->post('/admin/counter/wanted', ['query' => 'Seclo 20'])->assertRedirect('/');
        $this->assertDatabaseCount('product_requests', 0);
    }

    // ---- 537: sales by department -------------------------------------------------------------------------------

    public function test_the_sales_report_splits_each_department(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->addToCart();
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);
        Invoice::latest('id')->firstOrFail()->forceFill(['completed_at' => now(), 'total' => 500])->save();

        $restaurant = Restaurant::create(['name' => 'Biryani House', 'area' => 'Mirpur', 'areas' => ['Mirpur'], 'status' => 'active', 'owner_id' => $this->admin->id, 'commission_kind' => 'percent', 'commission_value' => 10]);
        FoodOrder::unguarded(fn () => FoodOrder::create([
            'public_token' => str()->random(40), 'restaurant_id' => $restaurant->id, 'mode' => 'delivery', 'status' => 'delivered', 'subtotal' => 300, 'delivery_charge' => 0, 'total' => 300,
            'delivered_at' => now(), 'ship_to' => ['name' => 'X', 'phone' => '01711000000', 'address' => 'Y'],
        ]));

        $rows = app(SalesReports::class)->departments(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31 23:59:59'))->keyBy('key');

        $this->assertSame(1, $rows['pharmacy']['orders']);
        $this->assertSame(500.0, $rows['pharmacy']['total']);
        $this->assertSame(1, $rows['food']['orders']);
        $this->assertSame(300.0, $rows['food']['total']);
        $this->assertSame(0, $rows['gaming']['orders']);
    }

    // ---- 38: a wholesale link never reveals itself --------------------------------------------------------------

    public function test_a_visitor_who_is_not_a_wholesaler_goes_quietly_to_the_homepage(): void
    {
        $this->get('/wholesale')->assertRedirect('/');
        $this->get('/wholesale/account')->assertRedirect('/');
        $this->actingAs($this->shop)->get('/wholesale')->assertRedirect('/');
    }

    // ---- 343: the shop's own name and logo ---------------------------------------------------------------------

    public function test_a_wholesaler_can_set_its_own_name_and_logo_and_only_it_can_see_the_logo(): void
    {
        Storage::fake();
        $wholesaler = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine']]);

        $this->actingAs($wholesaler)->post('/wholesale/account/branding', ['business_name' => 'Rahim Traders', 'logo' => UploadedFile::fake()->image('logo.png', 200, 200)])
            ->assertSessionHasNoErrors();

        $wholesaler->refresh();
        $this->assertSame('Rahim Traders', $wholesaler->business_name);
        $this->assertNotNull($wholesaler->logo_path);
        $this->actingAs($wholesaler)->get('/wholesale/account/logo')->assertOk();
        $this->actingAs($wholesaler)->get('/wholesale')->assertInertia(fn (Assert $p) => $p->where('portal.brand.name', 'Rahim Traders'));

        $this->actingAs($wholesaler)->post('/wholesale/account/branding', ['business_name' => 'Rahim Traders', 'remove_logo' => true])->assertSessionHasNoErrors();
        $this->assertNull($wholesaler->fresh()->logo_path);
        $this->actingAs($wholesaler)->get('/wholesale/account/logo')->assertRedirect(); // a missing page goes quietly home, as every unknown address does
    }

    public function test_the_logo_must_be_a_picture_and_the_name_is_required(): void
    {
        Storage::fake();
        $wholesaler = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine']]);

        $this->actingAs($wholesaler)->post('/wholesale/account/branding', ['business_name' => 'X', 'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])->assertSessionHasErrors('logo');
        $this->actingAs($wholesaler)->post('/wholesale/account/branding', ['business_name' => ''])->assertSessionHasErrors('business_name');
    }

    // ---- 28: a taka price for a box, a strip or a piece --------------------------------------------------------

    public function test_a_taka_price_can_be_typed_for_a_box_and_is_kept_per_piece_with_the_percent_shown(): void
    {
        // A box of 30 pieces printed at 10 a piece: 240 for the box is 8 a piece, which is 20% off.
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->put("/admin/customers/{$this->shop->id}/rates", ['medicine_id' => $this->napa->id, 'fixed_price' => '240', 'price_unit' => 'box'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, '20% off'));

        $this->assertDatabaseHas('customer_rates', ['user_id' => $this->shop->id, 'medicine_id' => $this->napa->id, 'fixed_price' => '8.00']);
    }

    public function test_a_strip_price_is_converted_and_a_price_that_gives_no_discount_is_refused(): void
    {
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->put("/admin/customers/{$this->shop->id}/rates", ['medicine_id' => $this->napa->id, 'fixed_price' => '90', 'price_unit' => 'strip'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customer_rates', ['user_id' => $this->shop->id, 'fixed_price' => '9.00']);

        // 300 for a box is the printed price: no discount at all.
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->put("/admin/customers/{$this->shop->id}/rates", ['medicine_id' => $this->napa->id, 'fixed_price' => '300', 'price_unit' => 'box'])
            ->assertSessionHasErrors('fixed_price');
    }

    // ---- 12: two-step sign-in -------------------------------------------------------------------------------------

    public function test_staff_are_asked_for_two_step_sign_in_on_the_live_site_by_default_and_shops_only_when_switched_on(): void
    {
        $twoFactor = app(\App\Services\Security\TwoFactor::class);

        $this->assertFalse($twoFactor->requiredFor($this->staff), 'Not in tests or local work, so nothing breaks while building.');
        $this->app['env'] = 'production';
        $this->assertTrue($twoFactor->requiredFor($this->staff));
        $this->assertTrue($twoFactor->requiredFor($this->admin));
        $this->assertFalse($twoFactor->requiredFor($this->shop));
        Setting::write('security.require_2fa_staff', false);
        $this->assertFalse($twoFactor->requiredFor($this->staff), 'The owner can switch it off.');

        $this->app['env'] = 'testing';
        Setting::write('security.require_2fa_trade', true);
        $this->assertTrue($twoFactor->requiredFor($this->shop));
        $this->actingAs($this->shop)->get('/paikari')->assertRedirect(route('two-factor.show'));
    }

    // ---- 29 / 162 are already built: the percent switch is on both buyer pages ---------------------------------

    public function test_the_bulk_buyer_page_has_the_same_price_view_as_the_shop_page(): void
    {
        $wholesaler = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine']]);

        $this->actingAs($wholesaler)->get('/wholesale')->assertOk()->assertInertia(fn (Assert $p) => $p->component('paikari/index'));
        $this->actingAs($this->shop)->get('/paikari')->assertOk()->assertInertia(fn (Assert $p) => $p->component('paikari/index'));
    }

    public function test_a_stock_problem_keeps_its_reason_whatever_the_language_of_its_words(): void
    {
        foreach (['en', 'bn'] as $locale) {
            app()->setLocale($locale);

            $this->assertSame('loss', StockException::loss('Napa')->reason);
            $this->assertSame('insufficient', StockException::insufficient('Napa')->reason);
            $this->assertSame('duplicate_invoice', StockException::duplicateInvoice('A-1')->reason);
        }

        app()->setLocale('bn');
        $this->assertStringContainsString('Napa', StockException::loss('Napa')->getMessage());
        $this->assertStringNotContainsString('Only the admin can approve it', StockException::loss('Napa')->getMessage(), 'The words are in Bangla for a Bangla screen.');
        app()->setLocale('en');
        $this->assertStringContainsString('would be a loss', StockException::loss('Napa')->getMessage());
    }
}
