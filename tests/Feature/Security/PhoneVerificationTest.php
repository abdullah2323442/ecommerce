<?php

namespace Tests\Feature\Security;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\PhoneVerification;
use App\Models\Sector;
use App\Models\User;
use App\Services\Messages\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 03: one account per phone number, checked by a code; guest orders placed with the same (verified) number join the account. */
class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    /** @var list<array{0: string, 1: string}> */
    private array $texts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create(['account_type' => AccountType::Public]);
        $this->texts = [];
        $texts = &$this->texts;
        $fake = new class($texts) implements SmsGateway
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $text): void
            {
                $this->sent[] = [$phone, $text];
            }
        };
        // A code someone is waiting for goes out at once, not through the queue.
        $this->app->instance(\App\Services\Messages\ConfiguredSmsGateway::class, $fake);
        $this->app->instance(SmsGateway::class, $fake);
    }

    private function code(): string
    {
        preg_match('/\b(\d{6})\b/', end($this->texts)[1], $m);

        return $m[1];
    }

    private function ask(string $phone = '01712345678')
    {
        return $this->actingAs($this->me)->post('/settings/phone', ['phone' => $phone]);
    }

    private function guestOrder(string $phone = '01712345678', ?int $buyer = null): Invoice
    {
        return Invoice::create(['number' => null, 'channel' => 'online', 'status' => 'placed', 'public_token' => str()->random(40), 'customer_id' => $buyer, 'ship_to' => ['name' => 'Hasan', 'phone' => $phone, 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'H5'], 'kind' => 'retail']);
    }

    private function marketOrder(string $phone = '01712345678', ?int $buyer = null): MarketOrder
    {
        $seller = User::factory()->create(['account_type' => AccountType::Wholesaler]);
        $s = Sector::firstOrCreate(['slug' => 'wood'], ['name' => 'Wood', 'unit' => 'foot', 'allow_fraction' => false]);
        $l = Listing::create(['seller_id' => $seller->id, 'sector_id' => $s->id, 'title' => 'Chair', 'public_price' => 10, 'stock' => 5, 'status' => 'published', 'approved_at' => now()]);

        return MarketOrder::create(['public_token' => str()->random(40), 'listing_id' => $l->id, 'seller_id' => $seller->id, 'buyer_id' => $buyer, 'title' => 'Chair', 'unit' => 'foot', 'quantity' => 1, 'unit_price' => 10, 'seller_price' => 9, 'commission' => 1,
            'delivery_charge' => 0, 'total' => 10, 'status' => 'placed', 'pay_by' => 'cod', 'audience' => 'public', 'ship_to' => ['name' => 'Hasan', 'phone' => $phone]]);
    }

    public function test_a_code_goes_to_the_phone_and_only_its_hash_is_kept(): void
    {
        $this->ask()->assertSessionHasNoErrors();

        $this->assertCount(1, $this->texts);
        $this->assertSame('01712345678', $this->texts[0][0]);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $this->texts[0][1]);
        $this->assertStringNotContainsString($this->code(), (string) PhoneVerification::firstOrFail()->code_hash);
        $this->assertNull($this->me->fresh()->phone);   // nothing is kept on the account until the code is typed
    }

    public function test_the_right_code_verifies_the_number(): void
    {
        $this->ask('+880 1712-345678');
        $code = $this->code();

        $this->post('/settings/phone/verify', ['code' => $code])->assertSessionHasNoErrors();

        $me = $this->me->fresh();
        $this->assertSame('01712345678', $me->phone);
        $this->assertNotNull($me->phone_verified_at);
        $this->post('/settings/phone/verify', ['code' => $code])->assertSessionHasErrors('code');   // used once
    }

    public function test_a_wrong_code_is_refused_and_five_wrong_tries_void_it(): void
    {
        $this->ask();
        $real = $this->code();
        $wrong = $real === '000000' ? '000001' : '000000';

        foreach (range(1, 5) as $i) {
            $this->post('/settings/phone/verify', ['code' => $wrong])->assertSessionHasErrors('code');
        }

        $this->post('/settings/phone/verify', ['code' => $real])->assertSessionHasErrors('code');
        $this->assertNull($this->me->fresh()->phone_verified_at);
    }

    public function test_the_code_expires(): void
    {
        $this->ask();
        $code = $this->code();

        $this->travel(11)->minutes();

        $this->post('/settings/phone/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($this->me->fresh()->phone_verified_at);
    }

    public function test_a_new_code_voids_the_old_one_and_cannot_be_asked_for_at_once(): void
    {
        $this->ask();
        $old = $this->code();

        $this->ask()->assertSessionHasErrors('phone');   // within a minute
        $this->assertCount(1, $this->texts);

        $this->travel(2)->minutes();
        $this->ask()->assertSessionHasNoErrors();
        $new = $this->code();

        if ($old !== $new) {
            $this->post('/settings/phone/verify', ['code' => $old])->assertSessionHasErrors('code');
        }
        $this->post('/settings/phone/verify', ['code' => $new])->assertSessionHasNoErrors();
    }

    public function test_only_five_codes_a_day(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ask()->assertSessionHasNoErrors();
            $this->travel(2)->minutes();
        }

        $this->ask()->assertSessionHasErrors('phone');
        $this->assertCount(5, $this->texts);
    }

    public function test_a_number_that_is_not_a_bangladesh_mobile_is_refused(): void
    {
        foreach (['12345', '01012345678', 'abc', '0171234567'] as $bad) {
            $this->ask($bad)->assertSessionHasErrors('phone');
        }

        $this->assertCount(0, $this->texts);
    }

    public function test_a_number_used_by_another_account_is_refused_and_no_code_is_sent(): void
    {
        User::factory()->create(['phone' => '01712345678']);

        $this->ask()->assertSessionHasErrors('phone');

        $this->assertCount(0, $this->texts);
    }

    public function test_a_number_taken_while_the_code_was_on_its_way_is_refused(): void
    {
        $this->ask();
        $code = $this->code();
        User::factory()->create(['phone' => '01712345678']);

        $this->post('/settings/phone/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull($this->me->fresh()->phone_verified_at);
    }

    // ------------------------------------------------------------------ the guest orders

    public function test_guest_orders_with_the_verified_number_join_the_account(): void
    {
        $shop = $this->guestOrder();
        $market = $this->marketOrder();

        $this->ask();
        $this->post('/settings/phone/verify', ['code' => $this->code()])->assertSessionHas('orders_linked', 2);

        $this->assertSame($this->me->id, $shop->fresh()->customer_id);
        $this->assertSame($this->me->id, $market->fresh()->buyer_id);
        $this->get('/settings/phone')->assertInertia(fn (Assert $p) => $p->where('verified', true)->where('linked', 2));
    }

    public function test_orders_of_other_numbers_and_other_accounts_are_left_alone(): void
    {
        $other = User::factory()->create();
        $elsewhere = $this->guestOrder('01812345678');
        $theirs = $this->guestOrder('01712345678', $other->id);
        $theirsMarket = $this->marketOrder('01712345678', $other->id);

        $this->ask();
        $this->post('/settings/phone/verify', ['code' => $this->code()]);

        $this->assertNull($elsewhere->fresh()->customer_id);
        $this->assertSame($other->id, $theirs->fresh()->customer_id);
        $this->assertSame($other->id, $theirsMarket->fresh()->buyer_id);
    }

    public function test_a_shop_counter_sale_is_never_taken(): void
    {
        $counter = Invoice::create(['number' => null, 'channel' => 'counter', 'status' => 'final', 'customer_id' => null, 'ship_to' => ['phone' => '01712345678'], 'kind' => 'retail']);

        $this->ask();
        $this->post('/settings/phone/verify', ['code' => $this->code()]);

        $this->assertNull($counter->fresh()->customer_id);
    }

    public function test_typing_a_number_alone_never_links_anything(): void
    {
        $shop = $this->guestOrder();

        $this->ask();   // asked for, never confirmed

        $this->assertNull($shop->fresh()->customer_id);
        $this->assertSame(0, app(\App\Services\Security\PhoneVerifier::class)->claimGuestOrders($this->me->fresh()));
    }

    public function test_an_unsigned_in_visitor_cannot_use_it(): void
    {
        $this->post('/settings/phone', ['phone' => '01712345678'])->assertRedirect('/login');
        $this->post('/settings/phone/verify', ['code' => '123456'])->assertRedirect('/login');
    }
}
