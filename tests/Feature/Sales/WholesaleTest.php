<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\TradeApplication;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 14: applications with a licence, category access, minimum order quantity, payment terms, the bulk dashboard. */
class WholesaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
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

    private function plain(): User
    {
        return User::factory()->create(['account_type' => AccountType::Public]);
    }

    private function bulk(array $over = []): User
    {
        return User::factory()->create(array_merge(['account_type' => AccountType::Wholesaler, 'categories' => ['medicine'], 'pay_cod' => true, 'pay_advance' => true], $over));
    }

    private function applyAs(User $u, array $over = [])
    {
        return $this->actingAs($u)->post('/business', array_merge([
            'business_name' => 'Rahman Traders', 'business_kind' => 'trader', 'categories' => ['medicine'], 'phone' => '01711111111',
            'address' => 'Dhaka', 'license_number' => 'DL-123', 'license' => UploadedFile::fake()->create('license.pdf', 100, 'application/pdf'),
        ], $over));
    }

    private function addBoxes(User $buyer, float $boxes): void
    {
        $this->actingAs($buyer)->post('/wholesale/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => $boxes])->assertSessionHasNoErrors();
    }

    public function test_an_application_keeps_the_licence_private_and_shows_its_state(): void
    {
        $user = $this->plain();

        $this->applyAs($user)->assertSessionHasNoErrors();

        $app = TradeApplication::firstOrFail();
        $this->assertSame('pending', $app->status);
        Storage::disk('local')->assertExists($app->license_path);
        $this->actingAs($user)->get('/business')->assertInertia(fn (Assert $p) => $p->component('business')->where('application.status', 'pending'));
    }

    public function test_an_application_needs_a_licence_and_only_one_can_wait(): void
    {
        $user = $this->plain();

        $this->actingAs($user)->post('/business', ['business_name' => 'X', 'business_kind' => 'shop', 'categories' => ['medicine'], 'phone' => '01', 'address' => 'a'])
            ->assertSessionHasErrors('license');

        $this->applyAs($user)->assertSessionHasNoErrors();
        $this->applyAs($user)->assertSessionHasErrors('business_name');
        $this->assertSame(1, TradeApplication::count());
    }

    public function test_only_a_plain_account_applies(): void
    {
        $this->actingAs($this->bulk())->get('/business')->assertRedirect('/wholesale');
        $this->actingAs($this->bulk())->post('/business', [])->assertForbidden();
    }

    public function test_staff_read_the_licence_and_every_look_is_logged(): void
    {
        $user = $this->plain();
        $this->applyAs($user);
        $app = TradeApplication::firstOrFail();

        $this->actingAs($this->admin)->get("/admin/applications/{$app->id}/file/first")->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'application.licence_viewed', 'subject_id' => $app->id]);

        $this->actingAs($user)->get("/admin/applications/{$app->id}/file/first")->assertRedirect('/');
    }

    public function test_approving_opens_a_bulk_account_with_categories_minimum_and_terms(): void
    {
        $user = $this->plain();
        $this->applyAs($user);
        $app = TradeApplication::firstOrFail();
        $tier = Tier::create(['name' => 'Silver', 'discount_percent' => 10]);

        $this->actingAs($this->admin)->post("/admin/applications/{$app->id}/approve", [
            'account_type' => 'wholesaler', 'tier_id' => $tier->id, 'categories' => ['medicine', 'wood'], 'moq' => 5, 'pay_cod' => false, 'pay_advance' => true,
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(AccountType::Wholesaler, $user->account_type);
        $this->assertSame(['medicine', 'wood'], $user->categories);
        $this->assertSame(5.0, (float) $user->moq);
        $this->assertFalse($user->pay_cod);
        $this->assertTrue($user->pay_advance);
        $this->assertSame('Rahman Traders', $user->business_name);
        $this->assertSame('approved', $app->fresh()->status);
        $this->assertDatabaseHas('notices', ['user_id' => $user->id, 'kind' => 'application_approved']);
    }

    public function test_a_food_business_applies_and_approving_opens_its_restaurant(): void
    {
        $user = $this->plain();
        $this->applyAs($user, ['business_name' => 'Dhaka Kitchen', 'business_kind' => 'restaurant', 'categories' => null])->assertSessionHasNoErrors();
        $app = TradeApplication::firstOrFail();
        $this->assertSame([], $app->categories, 'a restaurant has no trade categories');

        // The usual buying-account approval does not apply to it, and the area is needed.
        $this->actingAs($this->admin)->post("/admin/applications/{$app->id}/approve", ['account_type' => 'wholesaler'])->assertSessionHasErrors('area');

        $this->post("/admin/applications/{$app->id}/approve", ['area' => 'Mirpur', 'commission_kind' => 'percent', 'commission_value' => 8])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame(AccountType::FoodVendor, $user->account_type);
        $restaurant = Restaurant::where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('Dhaka Kitchen', $restaurant->name);
        $this->assertSame('8.00', (string) $restaurant->commission_value);
        $this->assertSame('approved', $app->fresh()->status);
        $this->assertDatabaseHas('notices', ['user_id' => $user->id, 'kind' => 'application_approved']);
    }

    public function test_a_shop_application_still_needs_a_category(): void
    {
        $this->applyAs($this->plain(), ['categories' => null])->assertSessionHasErrors('categories');
    }

    public function test_a_decline_needs_a_reason_the_applicant_can_read_and_they_can_apply_again(): void
    {
        $user = $this->plain();
        $this->applyAs($user);
        $app = TradeApplication::firstOrFail();

        $this->actingAs($this->admin)->post("/admin/applications/{$app->id}/reject", [])->assertSessionHasErrors('reason');
        $this->post("/admin/applications/{$app->id}/reject", ['reason' => 'Licence is expired.']);

        $this->actingAs($user)->get('/business')->assertInertia(fn (Assert $p) => $p->where('application.status', 'rejected')->where('application.review_note', 'Licence is expired.'));
        $this->applyAs($user)->assertSessionHasNoErrors();
        $this->assertSame(2, TradeApplication::count());
    }

    public function test_the_review_screen_is_for_staff_with_the_customers_permission(): void
    {
        $this->actingAs($this->plain())->get('/admin/applications')->assertRedirect('/');
        $this->actingAs($this->bulk())->get('/admin/applications')->assertRedirect('/');
        $this->actingAs($this->admin)->get('/admin/applications')->assertOk();
    }

    public function test_the_shop_portal_and_the_bulk_portal_stay_apart(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($shop)->get('/wholesale')->assertRedirect('/');
        $this->actingAs($this->bulk())->get('/paikari')->assertRedirect('/');
        $this->actingAs($this->bulk())->get('/wholesale')->assertOk()->assertInertia(fn (Assert $p) => $p->where('portal.base', '/wholesale')->where('portal.bulk', true));
        $this->actingAs($shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('portal.base', '/paikari')->where('portal.bulk', false));
    }

    public function test_a_bulk_buyer_orders_on_a_bulk_invoice(): void
    {
        $buyer = $this->bulk();

        $this->addBoxes($buyer, 3);
        $this->post('/wholesale/order', ['delivery_mode' => 'send', 'payment_terms' => 'cod'])->assertSessionHasNoErrors();

        $order = Invoice::firstOrFail();
        $this->assertSame('wholesale', $order->kind);
        $this->assertSame('placed', $order->status);
        $this->assertSame('cod', $order->payment_terms);
    }

    public function test_a_bulk_buyer_sees_only_the_categories_they_were_given(): void
    {
        $other = $this->bulk(['categories' => ['wood']]);

        $this->actingAs($other)->getJson('/wholesale/search?q=Na')->assertOk()->assertJson(['results' => []]);
        $this->post('/wholesale/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1])->assertRedirect('/');
        $this->assertSame(0, Invoice::count());

        $this->actingAs($this->bulk())->getJson('/wholesale/search?q=Na')->assertJsonCount(1, 'results');
    }

    public function test_an_order_under_the_minimum_is_held_with_a_clear_message(): void
    {
        $buyer = $this->bulk(['moq' => 5]);

        $this->addBoxes($buyer, 3);
        $this->post('/wholesale/order', ['delivery_mode' => 'send', 'payment_terms' => 'cod'])->assertSessionHasErrors('order');
        // The braces are filled in whatever the language: no raw {name} or {n} reaches the buyer.
        $message = session('errors')->get('order')[0];
        $this->assertStringContainsString('Napa 500 mg', $message);
        $this->assertStringNotContainsString('{', $message);
        $this->assertSame('draft', Invoice::firstOrFail()->status);

        $line = Invoice::firstOrFail()->lines()->firstOrFail();
        $this->put("/wholesale/cart/{$line->id}", ['quantity' => 5]);
        $this->post('/wholesale/order', ['delivery_mode' => 'send', 'payment_terms' => 'cod'])->assertSessionHasNoErrors();
        $this->assertSame('placed', Invoice::firstOrFail()->status);
    }

    public function test_the_shop_wide_minimum_applies_when_the_customer_has_none(): void
    {
        Setting::write('wholesale.moq', 4);
        $buyer = $this->bulk();

        $this->addBoxes($buyer, 2);
        $this->post('/wholesale/order', ['delivery_mode' => 'send', 'payment_terms' => 'cod'])->assertSessionHasErrors('order');
    }

    public function test_a_buyer_pays_only_the_ways_they_are_allowed(): void
    {
        $buyer = $this->bulk(['pay_cod' => false, 'pay_advance' => true]);
        $this->addBoxes($buyer, 1);

        $this->post('/wholesale/order', ['delivery_mode' => 'send', 'payment_terms' => 'cod'])->assertSessionHasErrors('payment_terms');
        $this->post('/wholesale/order', ['delivery_mode' => 'send'])->assertSessionHasErrors('payment_terms');
        $this->post('/wholesale/order', ['delivery_mode' => 'send', 'payment_terms' => 'advance'])->assertSessionHasNoErrors();
        $this->assertSame('advance', Invoice::firstOrFail()->payment_terms);
    }

    public function test_a_shop_is_not_asked_how_it_pays(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);

        $this->post('/paikari/order', ['delivery_mode' => 'send'])->assertSessionHasNoErrors();
        $this->assertNull(Invoice::firstOrFail()->payment_terms);
    }

    public function test_the_dashboard_shows_grade_finished_months_and_history_but_nothing_live(): void
    {
        $tier = Tier::create(['name' => 'Gold', 'discount_percent' => 12]);
        $buyer = $this->bulk(['tier_id' => $tier->id, 'business_name' => 'Rahman Traders']);
        $lastMonth = now()->startOfMonth()->subDay();

        Invoice::create(['kind' => 'wholesale', 'channel' => 'online', 'status' => 'final', 'customer_id' => $buyer->id, 'number' => 'M1', 'total' => 900, 'finalised_at' => $lastMonth]);
        Invoice::create(['kind' => 'wholesale', 'channel' => 'online', 'status' => 'final', 'customer_id' => $buyer->id, 'number' => 'M2', 'total' => 500, 'finalised_at' => now()]);

        $this->actingAs($buyer)->get('/wholesale/account')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('wholesale/account')->where('grade', 'Gold')
            ->where('trend.5.month', $lastMonth->format('Y-m'))->where('trend.5.total', 900)
            ->has('trend', 6)->has('history', 2));
    }

    public function test_the_dashboard_is_for_bulk_buyers_only(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->get('/wholesale/account')->assertRedirect('/');
    }

    public function test_the_admin_changes_the_type_and_terms_from_the_customer_page(): void
    {
        $user = $this->plain();

        $this->actingAs($this->admin)->put("/admin/customers/{$user->id}/trade", [
            'account_type' => 'wholesaler', 'categories' => ['medicine'], 'moq' => 2, 'pay_cod' => true, 'pay_advance' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame(AccountType::Wholesaler, $user->fresh()->account_type);
        $this->assertFalse($user->fresh()->pay_advance);

        $this->put("/admin/customers/{$user->id}/trade", [
            'account_type' => 'wholesaler', 'categories' => ['medicine'], 'pay_cod' => false, 'pay_advance' => false,
        ])->assertSessionHasErrors('pay_cod');

        $this->put("/admin/customers/{$user->id}/trade", ['account_type' => 'paikari', 'pay_cod' => true, 'pay_advance' => true]);
        $this->assertNull($user->fresh()->categories);
    }
}
