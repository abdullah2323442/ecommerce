<?php

namespace Tests\Feature\Market;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Market\DigitalGoods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 13: a digital product downloads right after payment, with a count and an expiry so one purchase is not shared with hundreds. */
class DigitalProductTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://securepay.sslcommerz.com';

    private User $admin;

    private User $seller;

    private User $buyer;

    private Listing $book;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->seller = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['books'], 'business_name' => 'Book House', 'seller_trusted_at' => now()]);
        $sector = Sector::create(['slug' => 'books', 'name' => 'Books', 'unit' => 'piece', 'allow_fraction' => false]);
        $this->book = Listing::create([
            'seller_id' => $this->seller->id, 'sector_id' => $sector->id, 'title' => 'Learn Bangla cooking', 'public_price' => 300, 'stock' => 4, 'delivery_charge' => 60, 'status' => 'published',
            'commission_kind' => 'percent', 'commission_value' => 2, 'approved_at' => now(),
        ]);
        app(Integrations::class)->save('payment:sslcommerz', ['store_id' => 'shop123live', 'store_password' => 'STORE-PASS-999'], true, 'live', [], $this->admin);
    }

    private function attach(int $max = 3, int $days = 7, string $name = 'cooking-book.pdf')
    {
        return $this->actingAs($this->seller)->post("/wholesale/sell/{$this->book->id}/digital", ['file' => UploadedFile::fake()->create($name, 200, 'application/pdf'), 'max' => $max, 'days' => $days]);
    }

    private function buy(): MarketOrder
    {
        $this->actingAs($this->buyer)->post("/market/{$this->book->id}/order", ['quantity' => 1, 'name' => 'Hasan', 'phone' => '01712345678'])->assertSessionHasNoErrors();

        return MarketOrder::latest('id')->firstOrFail();
    }

    private function pay(MarketOrder $o): Payment
    {
        Http::fake([self::HOST.'/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'sessionkey' => 'SK', 'GatewayPageURL' => self::HOST.'/EasyCheckOut/x'], 200)]);
        $this->post("/market/orders/{$o->public_token}/pay")->assertRedirect();
        $p = Payment::latest('id')->firstOrFail();
        Http::fake([self::HOST.'/validator/api/validationserverAPI.php*' => Http::response([
            'status' => 'VALID', 'tran_id' => $p->ref, 'val_id' => 'V1', 'amount' => number_format((float) $p->amount, 2, '.', ''), 'currency' => 'BDT', 'bank_tran_id' => 'B1', 'card_type' => 'BKASH-BKash', 'risk_level' => '0',
        ], 200)]);
        $this->post('/webhooks/payments/sslcommerz/result/success', ['tran_id' => $p->ref, 'val_id' => 'V1', 'status' => 'VALID'])->assertRedirect();

        return $p;
    }

    private function bought(int $max = 3, int $days = 7): MarketOrder
    {
        $this->attach($max, $days)->assertSessionHasNoErrors();
        $o = $this->buy();
        $this->pay($o);

        return $o->fresh();
    }

    // ------------------------------------------------------------------ the seller's file

    public function test_the_seller_attaches_a_file_which_turns_the_product_into_a_prepaid_one(): void
    {
        $this->attach(5, 14)->assertSessionHasNoErrors();

        $l = $this->book->fresh();
        $this->assertNotNull($l->digital_path);
        $this->assertSame('cooking-book.pdf', $l->digital_name);
        $this->assertSame(5, $l->digital_max);
        $this->assertSame(14, $l->digital_days);
        $this->assertFalse((bool) $l->cod_allowed);
        $this->assertNull($l->stock);
        $this->assertEquals(0, $l->delivery_charge);
        Storage::disk('local')->assertExists($l->digital_path);
        $this->assertStringStartsNotWith('public', $l->digital_path);
    }

    public function test_the_file_is_never_in_a_public_place_and_its_name_is_not_the_sellers(): void
    {
        $this->attach(3, 7, '../../evil name<script>.pdf')->assertSessionHasNoErrors();

        $l = $this->book->fresh();
        $this->assertStringNotContainsString('evil', $l->digital_path);
        $this->assertStringNotContainsString('<', (string) $l->digital_name);
        $this->assertStringNotContainsString('/', (string) $l->digital_name);
        $this->assertStringStartsWith('digital/', $l->digital_path);
        $this->assertEmpty(Storage::disk('local')->allFiles('public'));
    }

    public function test_only_safe_kinds_of_file_and_sane_limits_are_accepted(): void
    {
        foreach (['run.php', 'shell.exe', 'page.html', 'x.svg', 'script.js'] as $bad) {
            $this->actingAs($this->seller)->post("/wholesale/sell/{$this->book->id}/digital", ['file' => UploadedFile::fake()->create($bad, 10), 'max' => 3, 'days' => 7])->assertSessionHasErrors('digital');
        }

        $this->actingAs($this->seller)->post("/wholesale/sell/{$this->book->id}/digital", ['file' => UploadedFile::fake()->create('big.pdf', 52000), 'max' => 3, 'days' => 7])->assertSessionHasErrors('digital');
        $this->attach(0, 7)->assertSessionHasErrors('max');
        $this->attach(3, 0)->assertSessionHasErrors('days');
        $this->attach(99, 7)->assertSessionHasErrors('max');
        $this->assertNull($this->book->fresh()->digital_path);
    }

    public function test_only_the_owner_may_attach_or_remove_it(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Wholesaler, 'categories' => ['books'], 'seller_trusted_at' => now()]);
        $this->actingAs($other)->post("/wholesale/sell/{$this->book->id}/digital", ['file' => UploadedFile::fake()->create('a.pdf', 10), 'max' => 3, 'days' => 7]);
        $this->actingAs($this->buyer)->post("/wholesale/sell/{$this->book->id}/digital", ['file' => UploadedFile::fake()->create('a.pdf', 10), 'max' => 3, 'days' => 7]);
        $this->assertNull($this->book->fresh()->digital_path);
    }

    public function test_replacing_the_file_deletes_the_old_one_and_removing_clears_it(): void
    {
        $this->attach()->assertSessionHasNoErrors();
        $first = $this->book->fresh()->digital_path;
        $this->attach(3, 7, 'new.pdf');
        $second = $this->book->fresh()->digital_path;

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->delete("/wholesale/sell/{$this->book->id}/digital")->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing($second);
        $this->assertNull($this->book->fresh()->digital_path);
    }

    // ------------------------------------------------------------------ buying

    public function test_it_is_bought_one_at_a_time_and_always_paid_online(): void
    {
        $this->attach();

        $this->actingAs($this->buyer)->post("/market/{$this->book->id}/order", ['quantity' => 2, 'name' => 'Hasan', 'phone' => '01712345678'])->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());

        $o = $this->buy();
        $this->assertSame('online', $o->pay_by);
        $this->assertEquals(0, $o->delivery_charge);
        $this->assertSame('placed', $o->status);
    }

    public function test_nothing_can_be_downloaded_before_payment(): void
    {
        $this->attach();
        $o = $this->buy();

        $this->get("/market/orders/{$o->public_token}/download")->assertSessionHasErrors('order');
        $this->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('digital.ready', false)->where('digital.paid', false));
    }

    public function test_paying_completes_the_order_and_opens_the_download(): void
    {
        $o = $this->bought(3, 7);

        $this->assertSame('delivered', $o->status);
        $this->assertNotNull($o->delivered_at);
        $this->assertSame(3, $o->download_max);
        $this->assertTrue($o->download_until->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'market_download_ready')->count());

        $this->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('digital.ready', true)->where('digital.left', 3));
    }

    public function test_the_file_comes_as_a_download_and_each_one_is_counted(): void
    {
        $o = $this->bought(3);

        $r = $this->get("/market/orders/{$o->public_token}/download")->assertOk();

        $this->assertStringContainsString('attachment', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('cooking-book.pdf', (string) $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame(1, $o->fresh()->downloads);
    }

    public function test_after_the_last_download_it_stops(): void
    {
        $o = $this->bought(2);

        $this->get("/market/orders/{$o->public_token}/download")->assertOk();
        $this->get("/market/orders/{$o->public_token}/download")->assertOk();
        $this->get("/market/orders/{$o->public_token}/download")->assertSessionHasErrors('order');

        $this->assertSame(2, $o->fresh()->downloads);
        $this->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('digital.ready', false)->where('digital.left', 0));
    }

    public function test_after_the_days_are_over_it_stops(): void
    {
        $o = $this->bought(5, 2);

        $this->travel(3)->days();

        $this->get("/market/orders/{$o->public_token}/download")->assertSessionHasErrors('order');
        $this->assertSame(0, $o->fresh()->downloads);
        $this->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('digital.over', true)->where('digital.ready', false));
    }

    public function test_changing_the_limits_later_does_not_change_an_earlier_purchase(): void
    {
        $o = $this->bought(2, 7);

        $this->attach(10, 90, 'cooking-book-v2.pdf');

        $this->assertSame(2, $o->fresh()->download_max);
        $this->assertTrue($o->fresh()->download_until->lt(now()->addDays(8)));
    }

    public function test_a_wrong_link_gets_nothing(): void
    {
        $this->bought();

        $this->get('/market/orders/'.str_repeat('a', 40).'/download')->assertRedirect();
        $this->get('/market/orders/short/download')->assertRedirect();
    }

    public function test_a_file_taken_down_cannot_be_downloaded_and_a_try_is_not_used_up(): void
    {
        $o = $this->bought();
        $this->actingAs($this->seller)->delete("/wholesale/sell/{$this->book->id}/digital");

        $this->get("/market/orders/{$o->public_token}/download")->assertSessionHasErrors('order');
        $this->assertSame(0, $o->fresh()->downloads);
    }

    public function test_a_missing_file_does_not_use_up_a_download(): void
    {
        $o = $this->bought();
        Storage::disk('local')->delete($this->book->fresh()->digital_path);

        $this->get("/market/orders/{$o->public_token}/download")->assertSessionHasErrors('order');
        $this->assertSame(0, $o->fresh()->downloads);
    }

    public function test_the_download_count_cannot_go_past_the_limit_even_if_asked_directly(): void
    {
        $o = $this->bought(1);
        $service = app(DigitalGoods::class);

        $service->download($o->fresh()->load('listing'));

        $this->expectException(\App\Services\Stock\StockException::class);
        $service->download($o->fresh()->load('listing'));
    }

    public function test_a_downloaded_product_cannot_be_sent_back_but_can_be_complained_about(): void
    {
        $o = $this->bought();

        $this->get("/market/orders/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_return', false)->where('order.can_complain', true));
    }

    public function test_a_paid_download_is_owed_to_the_seller_less_commission(): void
    {
        $o = $this->bought();

        $rows = app(\App\Services\Market\MarketOrders::class)->payouts();

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta((float) $o->total - (float) $o->commission, $rows[0]['amount'], 0.001);
    }

    public function test_online_payment_off_means_it_cannot_be_ordered(): void
    {
        $this->attach();
        app(Integrations::class)->save('payment:sslcommerz', [], false, 'live', [], $this->admin);

        $this->actingAs($this->buyer)->post("/market/{$this->book->id}/order", ['quantity' => 1, 'name' => 'Hasan', 'phone' => '01712345678'])->assertSessionHasErrors('order');
        $this->assertSame(0, MarketOrder::count());
    }

    public function test_the_seller_sees_what_is_attached_in_their_list(): void
    {
        $this->attach(4, 10);

        $this->actingAs($this->seller)->get('/wholesale/sell')->assertInertia(fn (Assert $p) => $p->where('listings.0.digital.name', 'cooking-book.pdf')->where('listings.0.digital.max', 4));
    }
}
