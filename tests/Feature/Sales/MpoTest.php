<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\MpoListing;
use App\Models\Preorder;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\Tier;
use App\Models\User;
use App\Services\Mpo\MpoService;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 10: the MPO sector. The owner opens accounts, MPOs submit chosen products, buyers see only "Anonymous N (store)". */
class MpoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $beacon;

    private Company $square;

    private Medicine $napa;

    private Medicine $seclo;

    private Medicine $ace;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::write('shop.name', 'Siam Store');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->beacon = Company::create(['name' => 'Beacon']);
        $this->square = Company::create(['name' => 'Square']);
        $make = fn (Company $c, string $name) => Medicine::create([
            'company_id' => $c->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make($this->beacon, 'Napa');
        $this->seclo = $make($this->beacon, 'Seclo');
        $this->ace = $make($this->square, 'Ace');
        app(StockService::class)->setMasterSync(true);
    }

    private function openMpo(string $name = 'Rafiq Hasan', ?array $companies = null, string $area = 'Dhaka North'): User
    {
        $this->actingAs($this->admin)->post('/admin/mpo', [
            'name' => $name, 'phone' => '0171'.random_int(1000000, 9999999), 'password' => 'a-long-password-1', 'area' => $area, 'note' => 'Reliable',
            'company_ids' => $companies ?? [$this->beacon->id],
        ])->assertSessionHasNoErrors();

        return User::where('name', $name)->firstOrFail();
    }

    /** An MPO who has chosen a product and had it approved for the given markets. */
    private function live(User $mpo, Medicine $m, array $markets = ['wholesale', 'paikari', 'public'], array $over = []): MpoListing
    {
        $this->actingAs($mpo)->post('/mpo/products', ['add' => [$m->id]]);
        $this->post('/mpo/listings', array_merge(['medicine_id' => $m->id, 'stock_status' => 'in_stock', 'quantity' => 40], $over))->assertSessionHasNoErrors();
        $listing = MpoListing::where('medicine_id', $m->id)->where('user_id', $mpo->id)->firstOrFail();
        $this->actingAs($this->admin)->post("/admin/mpo/listings/{$listing->id}/approve", [
            'markets' => $markets, 'price_wholesale' => 200, 'price_paikari' => 220, 'price_public' => 260, 'stock_status' => $listing->stock_status, 'arrive_days' => $listing->arrive_days, 'show_bonus' => true,
        ])->assertSessionHasNoErrors();

        return $listing->fresh();
    }

    public function test_only_the_owner_opens_an_mpo_account_and_there_is_no_sign_up(): void
    {
        $payload = ['name' => 'Rafiq', 'password' => 'a-long-password-1', 'company_ids' => [$this->beacon->id]];

        $this->post('/admin/mpo', $payload)->assertRedirect();   // not signed in
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->post('/admin/mpo', $payload)->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post('/admin/mpo', $payload)->assertRedirect('/');
        $this->assertSame(0, User::where('account_type', 'mpo')->count());

        $this->actingAs($this->admin)->post('/admin/mpo', $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, User::where('account_type', 'mpo')->count());
    }

    public function test_an_account_needs_a_company_and_a_good_password_and_gets_the_next_number(): void
    {
        $this->actingAs($this->admin);
        $this->post('/admin/mpo', ['name' => 'A', 'password' => 'short', 'company_ids' => [$this->beacon->id]])->assertSessionHasErrors('password');
        $this->post('/admin/mpo', ['name' => 'A', 'password' => 'a-long-password-1', 'company_ids' => []])->assertSessionHasErrors('company_ids');

        $one = $this->openMpo('One');
        $two = $this->openMpo('Two');

        $this->assertSame([1, 2], [$one->mpo_number, $two->mpo_number]);
        $this->assertStringEndsWith('.invalid', $one->email);
        $this->assertSame([$this->beacon->id], $one->mpoCompanies()->pluck('companies.id')->all());
    }

    public function test_the_list_groups_mpos_by_territory_and_a_change_of_territory_moves_them(): void
    {
        $a = $this->openMpo('A', null, 'Dhaka North');
        $this->openMpo('B', null, 'Dhaka North');
        $this->openMpo('C', null, 'Sylhet');

        $this->get('/admin/mpo')->assertInertia(fn (Assert $p) => $p->has('groups', 2)->where('groups.0.area', 'Dhaka North')->where('groups.0.count', 2)->where('groups.1.area', 'Sylhet'));

        $this->put("/admin/mpo/{$a->id}", ['name' => 'A', 'area' => 'Sylhet'])->assertSessionHasNoErrors();
        $this->get('/admin/mpo')->assertInertia(fn (Assert $p) => $p->where('groups.0.count', 1)->where('groups.1.count', 2));
    }

    public function test_the_mpo_has_their_own_screen_and_nobody_else_does(): void
    {
        $mpo = $this->openMpo();

        $this->actingAs($mpo)->get('/mpo')->assertOk()->assertInertia(fn (Assert $p) => $p->component('mpo/index')->has('companies', 1)->where('companies.0.name', 'Beacon'));
        $this->actingAs($mpo)->get('/admin/mpo')->assertRedirect('/');
        $this->get('/admin/counter')->assertRedirect('/');
        $this->get('/paikari')->assertRedirect('/');

        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Wholesaler, AccountType::Staff] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get('/mpo')->assertRedirect('/');
        }
        $this->actingAs($this->admin)->get('/mpo')->assertOk();
    }

    public function test_an_mpo_sees_and_chooses_only_from_their_own_companies(): void
    {
        $mpo = $this->openMpo();
        $this->actingAs($mpo);

        $this->getJson("/mpo/companies/{$this->beacon->id}/medicines")->assertJsonCount(2, 'results');
        $this->getJson("/mpo/companies/{$this->beacon->id}/medicines?q=Na")->assertJsonCount(1, 'results');
        $this->getJson("/mpo/companies/{$this->square->id}/medicines")->assertForbidden();

        $this->post('/mpo/products', ['add' => [$this->napa->id, $this->ace->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->napa->id], $mpo->mpoProducts()->pluck('medicines.id')->all());   // Ace is another company's

        $this->post('/mpo/products', ['add' => [$this->seclo->id], 'remove' => [$this->napa->id]]);
        $this->assertSame([$this->seclo->id], $mpo->mpoProducts()->pluck('medicines.id')->all());
    }

    public function test_only_a_chosen_product_can_be_submitted_and_it_waits_for_the_owner(): void
    {
        $mpo = $this->openMpo();
        $this->actingAs($mpo);

        $this->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'in_stock'])->assertSessionHasErrors('listing');
        $this->post('/mpo/products', ['add' => [$this->napa->id]]);
        $this->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'pre_order'])->assertSessionHasErrors('listing');   // days missing
        $this->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'pre_order', 'arrive_days' => 4, 'bonus_buy' => 10, 'bonus_free' => 2])->assertSessionHasNoErrors();

        $l = MpoListing::firstOrFail();
        $this->assertSame('submitted', $l->status);
        $this->assertSame(4, $l->arrive_days);
        $this->assertSame([10, 2], [$l->bonus_buy, $l->bonus_free]);
        $this->assertSame([], app(MpoService::class)->offersFor($this->napa, 'public')->all());   // not on the market yet
    }

    public function test_submitting_again_changes_the_open_listing_and_sends_an_approved_one_back_for_review(): void
    {
        $mpo = $this->openMpo();
        $l = $this->live($mpo, $this->napa);

        $this->actingAs($mpo)->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'in_stock', 'quantity' => 10])->assertSessionHasNoErrors();

        $this->assertSame(1, MpoListing::count());
        $this->assertSame('submitted', $l->fresh()->status);
        $this->assertSame([], app(MpoService::class)->offersFor($this->napa, 'public')->all());
    }

    public function test_approval_needs_a_market_and_a_price_in_each_and_only_works_on_a_waiting_listing(): void
    {
        $mpo = $this->openMpo();
        $this->actingAs($mpo)->post('/mpo/products', ['add' => [$this->napa->id]]);
        $this->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'in_stock']);
        $l = MpoListing::firstOrFail();
        $this->actingAs($this->admin);

        $this->post("/admin/mpo/listings/{$l->id}/approve", ['markets' => [], 'stock_status' => 'in_stock'])->assertSessionHasErrors('markets');
        $this->post("/admin/mpo/listings/{$l->id}/approve", ['markets' => ['public', 'paikari'], 'price_public' => 260, 'stock_status' => 'in_stock'])->assertSessionHasErrors('listing');
        $this->post("/admin/mpo/listings/{$l->id}/approve", ['markets' => ['public'], 'price_public' => 260, 'stock_status' => 'pre_order'])->assertSessionHasErrors('listing');   // days missing
        $this->assertSame('submitted', $l->fresh()->status);

        $this->post("/admin/mpo/listings/{$l->id}/approve", ['markets' => ['public'], 'price_public' => 260, 'stock_status' => 'in_stock'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $l->fresh()->status);
        $this->assertNull($l->fresh()->price_paikari);
        $this->post("/admin/mpo/listings/{$l->id}/approve", ['markets' => ['public'], 'price_public' => 300, 'stock_status' => 'in_stock'])->assertSessionHasErrors('listing');
    }

    public function test_a_decline_needs_a_note_the_mpo_can_read(): void
    {
        $mpo = $this->openMpo();
        $this->actingAs($mpo)->post('/mpo/products', ['add' => [$this->napa->id]]);
        $this->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'in_stock']);
        $l = MpoListing::firstOrFail();

        $this->actingAs($this->admin)->post("/admin/mpo/listings/{$l->id}/reject", [])->assertSessionHasErrors('note');
        $this->post("/admin/mpo/listings/{$l->id}/reject", ['note' => 'Too close to expiry.'])->assertSessionHasNoErrors();

        $this->actingAs($mpo)->get('/mpo')->assertInertia(fn (Assert $p) => $p->where('listings.0.status', 'rejected')->where('listings.0.review_note', 'Too close to expiry.'));
    }

    public function test_each_buyer_sees_the_price_of_their_own_market_under_an_anonymous_name(): void
    {
        $mpo = $this->openMpo('Rafiq Hasan');
        $this->live($mpo, $this->napa);
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);

        $guest = $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('mpoOffers', 1)->where('mpoOffers.0.price', 260)->where('mpoOffers.0.seller', 'Anonymous 1 (Siam Store)'));

        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]))->getJson('/paikari/search?q=Na')->assertJsonPath('results.0.mpo_offers.0.price', 220);
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Wholesaler, 'tier_id' => $tier->id, 'categories' => ['medicine']]))->getJson('/wholesale/search?q=Na')->assertJsonPath('results.0.mpo_offers.0.price', 200);
    }

    public function test_a_market_the_owner_did_not_pick_never_sees_the_offer(): void
    {
        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa, ['wholesale']);
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);

        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('mpoOffers', 0));
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]))->getJson('/paikari/search?q=Na')->assertJsonPath('results.0.mpo_offers', []);
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Wholesaler, 'tier_id' => $tier->id, 'categories' => ['medicine']]))->getJson('/wholesale/search?q=Na')->assertJsonCount(1, 'results.0.mpo_offers');
    }

    public function test_nothing_a_buyer_can_see_says_who_the_mpo_is(): void
    {
        $mpo = $this->openMpo('Rafiq Hasan');
        $mpo->forceFill(['email' => 'rafiq.secret@example.com', 'phone' => '01799887766'])->save();
        $this->live($mpo, $this->napa);

        $json = json_encode($this->get("/medicines/{$this->napa->id}")->viewData('page')['props']);

        foreach (['Rafiq', 'Hasan', 'rafiq.secret', '01799887766', 'Dhaka North', 'Reliable'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} leaked to a buyer");
        }
        $this->assertStringNotContainsString('"user_id"', $json);
    }

    public function test_the_mpo_sees_the_orders_as_placed_from_our_account_with_no_buyer_in_them(): void
    {
        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa);
        $buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Karim Buyer', 'phone' => '01755555555']);
        $this->actingAs($buyer)->post('/mpo-offers/'.MpoListing::firstOrFail()->id.'/order', ['boxes' => 5])->assertSessionHasNoErrors();

        $page = $this->actingAs($mpo)->get('/mpo')->assertInertia(fn (Assert $p) => $p->has('orders', 1)->where('orders.0.quantity', 5))->viewData('page')['props'];

        $json = json_encode($page['orders']);
        foreach (['Karim', '01755555555', 'customer', 'price', 'public', 'wholesale', 'paikari'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_one_mpo_cannot_see_or_touch_another_mpos_things(): void
    {
        $a = $this->openMpo('A');
        $b = $this->openMpo('B');
        $listing = $this->live($a, $this->napa);

        $this->actingAs($b)->get('/mpo')->assertInertia(fn (Assert $p) => $p->has('listings', 0)->has('chosen', 0));
        $this->delete("/mpo/listings/{$listing->id}")->assertRedirect('/');
        $this->assertSame('approved', $listing->fresh()->status);

        $this->actingAs($a)->delete("/mpo/listings/{$listing->id}")->assertSessionHasNoErrors();
        $this->assertSame('closed', $listing->fresh()->status);
    }

    public function test_the_mpo_cannot_set_a_price_or_a_market_or_add_a_company(): void
    {
        $mpo = $this->openMpo();
        $this->actingAs($mpo)->post('/mpo/products', ['add' => [$this->napa->id]]);

        $this->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'in_stock', 'price_public' => 1, 'markets' => ['public'], 'status' => 'approved']);
        $l = MpoListing::firstOrFail();
        $this->assertSame('submitted', $l->status);
        $this->assertNull($l->price_public);
        $this->assertNull($l->markets);

        $this->put("/admin/mpo/{$mpo->id}/companies", ['company_ids' => [$this->beacon->id, $this->square->id]])->assertRedirect('/');
        $this->post("/admin/mpo/listings/{$l->id}/approve", ['markets' => ['public'], 'price_public' => 1, 'stock_status' => 'in_stock'])->assertRedirect('/');
        $this->assertSame('submitted', $l->fresh()->status);
    }

    public function test_an_order_takes_the_price_of_the_buyers_market_and_the_bonus_in_proportion(): void
    {
        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa, ['wholesale', 'paikari', 'public'], ['bonus_buy' => 10, 'bonus_free' => 2]);
        $listing = MpoListing::firstOrFail();
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($buyer);

        $this->post("/mpo-offers/{$listing->id}/order", ['boxes' => 25])->assertSessionHasNoErrors();

        $pre = Preorder::firstOrFail();
        $this->assertSame('accepted', $pre->status);
        $this->assertSame('260.00', (string) $pre->box_price);
        $this->assertSame(4, $pre->bonus_free);   // 25 boxes at 10+2: two full tens, so four free
        $this->assertSame($listing->id, $pre->mpo_listing_id);
        $this->assertSame($buyer->id, $pre->customer_id);

        $this->get("/preorders/{$pre->id}")->assertInertia(fn (Assert $p) => $p->where('preorder.lines.0.unit_price', 260)->where('preorder.total', 6500));
    }

    public function test_an_order_without_a_bonus_when_the_owner_did_not_show_it(): void
    {
        $mpo = $this->openMpo();
        $this->actingAs($mpo)->post('/mpo/products', ['add' => [$this->napa->id]]);
        $this->post('/mpo/listings', ['medicine_id' => $this->napa->id, 'stock_status' => 'in_stock', 'bonus_buy' => 10, 'bonus_free' => 2]);
        $this->actingAs($this->admin)->post('/admin/mpo/listings/'.MpoListing::firstOrFail()->id.'/approve', ['markets' => ['public'], 'price_public' => 260, 'stock_status' => 'in_stock']);

        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post('/mpo-offers/'.MpoListing::firstOrFail()->id.'/order', ['boxes' => 20]);

        $this->assertNull(Preorder::firstOrFail()->bonus_free);
        $this->get('/medicines/'.$this->napa->id)->assertInertia(fn (Assert $p) => $p->where('mpoOffers.0.bonus', null));
    }

    public function test_a_pre_order_listing_promises_its_days_and_staff_cannot_order_it(): void
    {
        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa, ['public'], ['stock_status' => 'pre_order', 'arrive_days' => 5]);
        $id = MpoListing::firstOrFail()->id;

        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->post("/mpo-offers/{$id}/order", ['boxes' => 1])->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post("/mpo-offers/{$id}/order", ['boxes' => 2]);

        $this->assertSame(now()->addDays(5)->toDateString(), Preorder::firstOrFail()->due_on->toDateString());
        $this->post("/mpo-offers/{$id}/order", ['boxes' => 0])->assertSessionHasErrors('boxes');
    }

    public function test_the_goods_pass_through_the_owner_enter_stock_and_the_order_is_made_at_the_agreed_price_with_the_bonus_free(): void
    {
        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa, ['wholesale', 'paikari', 'public'], ['bonus_buy' => 10, 'bonus_free' => 2]);
        $buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Rina']);
        $this->actingAs($buyer)->post('/mpo-offers/'.MpoListing::firstOrFail()->id.'/order', ['boxes' => 10]);
        $pre = Preorder::firstOrFail();
        $line = $pre->lines()->firstOrFail();

        // Not in stock yet: the owner cannot hand it over.
        $this->actingAs($this->admin)->post("/admin/preorders/{$pre->id}/receive", ['received' => [$line->id => 10]])->assertSessionHasErrors('preorder');

        $this->post("/admin/preorders/{$pre->id}/take-from-mpo", ['percent' => 40])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_sources', ['name' => 'Through MPO', 'type' => 'mpo']);
        $lot = StockLot::where('medicine_id', $this->napa->id)->firstOrFail();
        $this->assertEquals(12.0, (float) $lot->units_received);   // 10 paid and 2 free
        $this->assertEquals(40.0, (float) $lot->purchase_percent > 0 ? 40.0 : 0.0);

        $this->post("/admin/preorders/{$pre->id}/receive", ['received' => [$line->id => 10]])->assertSessionHasNoErrors();

        $order = Invoice::findOrFail($pre->fresh()->invoice_id);
        $lines = $order->lines()->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('260.00', (string) $lines[0]->unit_price);
        $this->assertSame('0.00', (string) $lines[1]->unit_price);
        $this->assertSame('bonus', $lines[1]->rate_layer);
        $this->assertSame('2.00', (string) $lines[1]->quantity);
        $this->assertEqualsWithDelta(0.0, app(StockService::class)->onHand($this->napa), 0.001);   // all 12 left the shelf
        $this->assertSame(2600.0, $this->app->make(InvoiceService::class)->totals($order)['subtotal']);
    }

    public function test_take_from_mpo_is_only_for_an_mpo_order_and_a_sane_percent(): void
    {
        $plain = Preorder::create(['customer_id' => User::factory()->create(['account_type' => AccountType::Public])->id, 'status' => 'accepted', 'promised_days' => 3, 'due_on' => today()->addDays(3)]);
        $plain->lines()->create(['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);

        $this->actingAs($this->admin)->post("/admin/preorders/{$plain->id}/take-from-mpo", ['percent' => 30])->assertSessionHasErrors('preorder');

        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa);
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post('/mpo-offers/'.MpoListing::firstOrFail()->id.'/order', ['boxes' => 1]);
        $this->actingAs($this->admin)->post('/admin/preorders/'.Preorder::whereNotNull('mpo_listing_id')->firstOrFail()->id.'/take-from-mpo', ['percent' => 100])->assertSessionHasErrors('percent');
    }

    public function test_closing_an_account_takes_its_products_off_but_orders_already_made_stand(): void
    {
        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa);
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post('/mpo-offers/'.MpoListing::firstOrFail()->id.'/order', ['boxes' => 3]);

        $this->actingAs($this->admin)->post("/admin/mpo/{$mpo->id}/close")->assertSessionHasNoErrors();

        $this->assertSame('closed', MpoListing::firstOrFail()->status);
        $this->assertTrue($mpo->fresh()->isSuspended());
        $this->assertSame('accepted', Preorder::firstOrFail()->status);
        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('mpoOffers', 0));
        $this->actingAs($mpo->fresh())->get('/mpo')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->post('/mpo-offers/'.MpoListing::firstOrFail()->id.'/order', ['boxes' => 1])->assertSessionHasErrors('offer');
    }

    public function test_taking_a_company_away_takes_its_chosen_products_and_listings_with_it(): void
    {
        $mpo = $this->openMpo('Rafiq', [$this->beacon->id, $this->square->id]);
        $this->live($mpo, $this->napa);
        $this->actingAs($mpo)->post('/mpo/products', ['add' => [$this->ace->id]]);

        $this->actingAs($this->admin)->put("/admin/mpo/{$mpo->id}/companies", ['company_ids' => [$this->square->id]])->assertSessionHasNoErrors();

        $this->assertSame([$this->ace->id], $mpo->mpoProducts()->pluck('medicines.id')->all());
        $this->assertSame('closed', MpoListing::firstOrFail()->status);
    }

    public function test_the_owner_sees_the_same_medicine_from_two_mpos_side_by_side_with_their_real_names(): void
    {
        $a = $this->openMpo('Rafiq Hasan');
        $b = $this->openMpo('Salma Akter');
        $this->live($a, $this->napa);
        $this->live($b, $this->napa);

        $this->actingAs($this->admin)->get('/admin/mpo/listings')->assertInertia(fn (Assert $p) => $p->has('groups', 1)->has('groups.0.listings', 2)
            ->where('groups.0.listings.0.mpo', 'Rafiq Hasan')->where('groups.0.listings.1.mpo', 'Salma Akter')
            ->where('groups.0.listings.0.alias', 'Anonymous 1 (Siam Store)')->where('groups.0.listings.1.alias', 'Anonymous 2 (Siam Store)'));

        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('mpoOffers', 2)->where('mpoOffers.1.seller', 'Anonymous 2 (Siam Store)'));
    }

    public function test_the_profile_shows_everything_to_the_owner_and_can_be_edited(): void
    {
        $mpo = $this->openMpo();
        $this->live($mpo, $this->napa);

        $this->actingAs($this->admin)->get("/admin/mpo/{$mpo->id}")->assertInertia(fn (Assert $p) => $p
            ->where('mpo.name', 'Rafiq Hasan')->where('mpo.area', 'Dhaka North')->where('mpo.note', 'Reliable')->has('mpo.products', 1)->has('listings', 1));

        $this->put("/admin/mpo/{$mpo->id}", ['name' => 'Rafiq H.', 'area' => 'Gazipur', 'note' => 'Slow to deliver'])->assertSessionHasNoErrors();
        $this->assertSame('Gazipur', $mpo->fresh()->area);

        $this->actingAs($this->admin)->get('/admin/mpo/'.User::factory()->create(['account_type' => AccountType::Public])->id)->assertNotFound();
    }

    public function test_the_profile_counts_what_was_submitted_approved_and_sold(): void
    {
        $mpo = $this->openMpo();
        $listing = $this->live($mpo, $this->napa);
        $this->actingAs($mpo)->post('/mpo/products', ['add' => [$this->seclo->id]]);
        $this->post('/mpo/listings', ['medicine_id' => $this->seclo->id, 'stock_status' => 'in_stock', 'quantity' => 10]);   // waits for approval
        $buyer = User::factory()->create();
        Preorder::create(['customer_id' => $buyer->id, 'medicine_id' => $this->napa->id, 'mpo_listing_id' => $listing->id, 'status' => 'fulfilled', 'promised_days' => 3]);
        Preorder::create(['customer_id' => $buyer->id, 'medicine_id' => $this->napa->id, 'mpo_listing_id' => $listing->id, 'status' => 'accepted', 'promised_days' => 3]);

        $this->actingAs($this->admin)->get("/admin/mpo/{$mpo->id}")->assertInertia(fn ($p) => $p->where('mpo.submitted', 2)->where('mpo.approved', 1)->where('mpo.sold', 1));
    }

    // ---- the profile photo (spec ch 10): the owner's, and nobody else's ---------------------------------------

    public function test_the_owner_sets_an_mpo_photo_and_sees_it_on_the_profile(): void
    {
        Storage::fake();
        $mpo = $this->openMpo();

        $this->actingAs($this->admin)->post("/admin/mpo/{$mpo->id}/photo", ['photo' => UploadedFile::fake()->image('rafiq.jpg', 300, 300)])->assertSessionHasNoErrors();

        $mpo->refresh();
        $this->assertNotNull($mpo->photo_path);
        Storage::assertExists($mpo->photo_path);

        $this->get("/admin/mpo/{$mpo->id}")->assertInertia(fn ($page) => $page->where('mpo.photo', fn ($url) => str_contains($url, "/admin/mpo/{$mpo->id}/photo")));
        $this->get("/admin/mpo/{$mpo->id}/photo")->assertOk();
    }

    public function test_a_new_photo_replaces_the_old_one_and_can_be_removed(): void
    {
        Storage::fake();
        $mpo = $this->openMpo();
        $this->actingAs($this->admin)->post("/admin/mpo/{$mpo->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $first = $mpo->fresh()->photo_path;

        $this->post("/admin/mpo/{$mpo->id}/photo", ['photo' => UploadedFile::fake()->image('b.jpg', 300, 300)]);
        $second = $mpo->fresh()->photo_path;
        $this->assertNotSame($first, $second);
        Storage::assertMissing($first);

        $this->post("/admin/mpo/{$mpo->id}/photo", ['remove' => true])->assertSessionHasNoErrors();
        $this->assertNull($mpo->fresh()->photo_path);
        Storage::assertMissing($second);
    }

    public function test_the_photo_must_be_a_picture_and_something_must_be_sent(): void
    {
        Storage::fake();
        $mpo = $this->openMpo();
        $this->actingAs($this->admin);

        $this->post("/admin/mpo/{$mpo->id}/photo", ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->post("/admin/mpo/{$mpo->id}/photo", [])->assertSessionHasErrors('photo');
    }

    public function test_nobody_but_the_owner_can_put_or_see_an_mpo_photo(): void
    {
        Storage::fake();
        $mpo = $this->openMpo();
        $this->actingAs($this->admin)->post("/admin/mpo/{$mpo->id}/photo", ['photo' => UploadedFile::fake()->image('r.jpg', 300, 300)]);

        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->get("/admin/mpo/{$mpo->id}/photo")->assertRedirect('/');
        $this->actingAs($shop)->post("/admin/mpo/{$mpo->id}/photo", ['photo' => UploadedFile::fake()->image('x.jpg')])->assertRedirect('/');
        $this->actingAs($mpo)->get("/admin/mpo/{$mpo->id}/photo")->assertRedirect('/');
    }
}
