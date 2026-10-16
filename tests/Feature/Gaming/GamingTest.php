<?php

namespace Tests\Feature\Gaming;

use App\Enums\AccountType;
use App\Models\Game;
use App\Models\GameOrder;
use App\Models\GamePackage;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Gaming\GameTopUp;
use App\Services\Gaming\PlayerVerifier;
use App\Services\Gaming\TopUpProvider;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 18: game top-up — games and packages, the ID and the name, online payment only, manual backup, refund of a failed top-up. */
class GamingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Game $ff;

    private GamePackage $d100;

    private GameTopUp $gaming;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        Setting::write('gaming.pay_numbers', 'bkash: 01700000000, nagad: 01800000000, visa: 123');
        $this->gaming = app(GameTopUp::class);
        $this->ff = $this->gaming->saveGame(['name' => 'Free Fire', 'id_label' => 'Player ID']);
        $this->d100 = $this->gaming->savePackage($this->ff, ['name' => '100 Diamonds', 'price' => 95]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A provider that knows some players and tops up (or fails) as told. */
    private function provider(array $players = ['123456789' => 'ProGamer'], string $topUp = 'manual'): void
    {
        $this->app->bind(PlayerVerifier::class, fn () => new class($players) implements PlayerVerifier
        {
            public function __construct(private array $players) {}

            public function lookup(Game $game, string $playerId, ?string $zone): array
            {
                return isset($this->players[$playerId]) ? ['status' => 'verified', 'name' => $this->players[$playerId]] : ['status' => 'invalid'];
            }
        });
        $this->app->bind(TopUpProvider::class, fn () => new class($topUp) implements TopUpProvider
        {
            public function __construct(private string $mode) {}

            public function send(GameOrder $order): array
            {
                return match ($this->mode) {
                    'done' => ['status' => 'done', 'ref' => 'PROV-1'],
                    'failed' => ['status' => 'failed', 'reason' => 'Player not found by the provider'],
                    default => ['status' => 'manual'],
                };
            }
        });
        $this->gaming = app(GameTopUp::class);
    }

    private function order(?User $buyer = null, string $id = '123456789'): GameOrder
    {
        return $this->gaming->place($buyer, $this->d100->load('game'), $id, null, '01712345678', true);
    }

    private function paid(?User $buyer = null): GameOrder
    {
        $o = $this->gaming->claimPayment($this->order($buyer), 'bkash', 'ABC123XYZ9');

        return $this->gaming->acceptPayment($o, $this->staff);
    }

    // ------------------------------------------------------------------ the catalogue

    public function test_games_get_their_own_address_and_packages_need_a_name_and_a_price(): void
    {
        $again = $this->gaming->saveGame(['name' => 'Free Fire']);
        $this->assertSame(['free-fire', 'free-fire-2'], [$this->ff->slug, $again->slug]);

        foreach ([['name' => 'x', 'price' => 10], ['name' => '50 Diamonds', 'price' => 0]] as $bad) {
            try {
                $this->gaming->savePackage($this->ff, $bad);
                $this->fail('refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_game_that_needs_a_zone_asks_for_it(): void
    {
        $ml = $this->gaming->saveGame(['name' => 'Mobile Legends', 'needs_zone' => true, 'zone_label' => 'Zone ID']);
        $pack = $this->gaming->savePackage($ml, ['name' => '86 Diamonds', 'price' => 120]);

        $this->assertSame('invalid', $this->gaming->verify($ml, '12345678', null)['status']);
        $this->assertSame('unavailable', $this->gaming->verify($ml, '12345678', '2001')['status']);
        $this->assertSame('2001', $this->gaming->place(null, $pack->load('game'), '12345678', '2001', '01712345678', true)->zone);
    }

    // ------------------------------------------------------------------ the ID and the name

    public function test_the_name_is_shown_and_kept_so_the_money_goes_to_the_right_player(): void
    {
        $this->provider();

        $this->assertSame(['status' => 'verified', 'name' => 'ProGamer'], $this->gaming->verify($this->ff, '123456789', null));
        $this->assertSame('ProGamer', $this->order()->player_name);
    }

    public function test_an_id_the_provider_does_not_know_stops_the_order(): void
    {
        $this->provider();

        $this->expectException(StockException::class);
        $this->order(null, '987654321');
    }

    public function test_an_id_with_junk_in_it_is_refused_whatever_the_provider(): void
    {
        foreach (['12', 'bad id!', str_repeat('9', 50)] as $id) {
            $this->assertSame('invalid', $this->gaming->verify($this->ff, $id, null)['status']);
        }
    }

    public function test_with_no_provider_nothing_is_checked_and_the_buyer_must_confirm_the_id(): void
    {
        $this->assertSame('unavailable', $this->gaming->verify($this->ff, '123456789', null)['status']);
        $this->assertNull($this->order()->player_name);

        $this->expectException(StockException::class);
        $this->gaming->place(null, $this->d100->load('game'), '123456789', null, '01712345678', false);
    }

    public function test_a_switched_off_package_or_game_cannot_be_ordered(): void
    {
        $this->gaming->savePackage($this->ff, ['name' => '100 Diamonds', 'price' => 95, 'is_active' => false], $this->d100);

        $this->expectException(StockException::class);
        $this->order();
    }

    public function test_a_phone_number_is_needed_for_the_confirmation(): void
    {
        $this->expectException(StockException::class);
        $this->gaming->place(null, $this->d100->load('game'), '123456789', null, '123', true);
    }

    // ------------------------------------------------------------------ payment: online only

    public function test_there_is_no_cash_on_delivery_nothing_is_done_until_paid(): void
    {
        $o = $this->order();

        $this->assertSame('placed', $o->status);
        foreach ([fn () => $this->gaming->done($o, $this->staff), fn () => $this->gaming->acceptPayment($o, $this->staff)] as $try) {
            try {
                $try();
                $this->fail('not paid');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_buyer_pays_and_gives_the_transaction_id_and_we_are_told(): void
    {
        $o = $this->gaming->claimPayment($this->order(), 'bkash', ' abc123xyz9 ');

        $this->assertSame(['claimed', 'bkash', 'ABC123XYZ9'], [$o->status, $o->pay_method, $o->pay_ref]);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'game_payment')->count());
    }

    public function test_one_transaction_pays_one_order_only(): void
    {
        $this->gaming->claimPayment($this->order(), 'bkash', 'ABC123XYZ9');

        $this->expectException(StockException::class);
        $this->gaming->claimPayment($this->order(), 'bkash', 'abc123xyz9');
    }

    public function test_only_bkash_nagad_and_rocket_and_a_sane_id(): void
    {
        $o = $this->order();

        foreach ([['visa', 'ABC123XYZ9'], ['bkash', 'ab'], ['bkash', 'has space in it']] as [$m, $ref]) {
            try {
                $this->gaming->claimPayment($o, $m, $ref);
                $this->fail('refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_only_the_known_numbers_are_listed(): void
    {
        $this->assertSame(['bkash' => '01700000000', 'nagad' => '01800000000'], $this->gaming->payNumbers());
    }

    public function test_a_payment_we_cannot_find_goes_back_to_the_buyer_with_the_reason(): void
    {
        $o = $this->gaming->claimPayment($this->order(), 'bkash', 'ABC123XYZ9');

        $back = $this->gaming->rejectPayment($o, $this->staff, 'The amount was 50, not 95');

        $this->assertSame(['placed', null, 'The amount was 50, not 95'], [$back->status, $back->pay_ref, $back->pay_note]);
        $this->assertSame('claimed', $this->gaming->claimPayment($back, 'bkash', 'ABC123XYZ9')->status);   // the id is free again
    }

    // ------------------------------------------------------------------ the top-up

    public function test_with_no_provider_a_paid_order_waits_for_us_and_we_top_up_by_hand(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $o = $this->paid($buyer);

        $this->assertSame('paid', $o->status);
        $this->assertSame([$o->id], $this->gaming->lists()['to_top_up']->pluck('id')->all());

        $done = $this->gaming->done($o, $this->staff, 'MANUAL-7');

        $this->assertSame(['topped_up', 'MANUAL-7'], [$done->status, $done->topup_ref]);
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'game_done')->count());
        $this->assertCount(0, $this->gaming->lists()['to_top_up']);
    }

    public function test_an_automatic_provider_tops_up_the_moment_the_payment_is_accepted(): void
    {
        $this->provider(['123456789' => 'ProGamer'], 'done');

        $o = $this->paid();

        $this->assertSame(['topped_up', 'PROV-1'], [$o->status, $o->topup_ref]);
    }

    public function test_a_failed_top_up_is_refunded_and_listed_apart(): void
    {
        $this->provider(['123456789' => 'ProGamer'], 'failed');
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $o = $this->paid($buyer);

        $this->assertSame(['failed', 'due'], [$o->status, $o->refund]);
        $this->assertSame([$o->id], $this->gaming->lists()['failed']->pluck('id')->all());
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'game_failed')->count());
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'game_failed')->count());

        $this->assertSame('done', $this->gaming->refunded($o, $this->staff)->refund);
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'game_refunded')->count());
    }

    public function test_we_can_fail_a_top_up_by_hand_with_a_reason(): void
    {
        $o = $this->paid();

        try {
            $this->gaming->fail($o, $this->staff, ' ');
            $this->fail('a reason is needed');
        } catch (StockException) {
        }

        $this->assertSame('due', $this->gaming->fail($o, $this->staff, 'Wrong server')->refund);
    }

    public function test_no_refund_is_made_twice_or_where_none_is_due(): void
    {
        $o = $this->paid();

        try {
            $this->gaming->refunded($o, $this->staff);
            $this->fail('nothing due');
        } catch (StockException) {
        }

        $f = $this->gaming->fail($o, $this->staff, 'Wrong server');
        $this->gaming->refunded($f, $this->staff);
        $this->expectException(StockException::class);
        $this->gaming->refunded($f->fresh(), $this->staff);
    }

    // ------------------------------------------------------------------ late

    public function test_a_late_top_up_tells_the_buyer_once_and_lets_them_cancel_for_a_refund(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $o = $this->paid($buyer);

        try {
            $this->gaming->cancel($o);
            $this->fail('not late yet');
        } catch (StockException) {
        }
        $this->assertSame(0, $this->gaming->watchLate());

        Carbon::setTestNow('2026-10-15 09:20:00');   // twenty minutes; the wait is fifteen
        $this->assertTrue($this->gaming->isLate($o->fresh()));
        $this->assertSame(1, $this->gaming->watchLate());
        $this->assertSame(0, $this->gaming->watchLate());
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'game_late')->count());

        $c = $this->gaming->cancel($o->fresh());
        $this->assertSame(['cancelled', 'due'], [$c->status, $c->refund]);
    }

    public function test_an_unpaid_order_can_simply_be_dropped(): void
    {
        $this->assertSame(['cancelled', 'none'], [($c = $this->gaming->cancel($this->order()))->status, $c->refund]);
    }

    public function test_a_topped_up_order_cannot_be_cancelled(): void
    {
        $o = $this->gaming->done($this->paid(), $this->staff);

        $this->expectException(StockException::class);
        $this->gaming->cancel($o, 'staff');
    }

    // ------------------------------------------------------------------ sales

    public function test_sales_are_counted_on_topped_up_orders_by_game(): void
    {
        $pubg = $this->gaming->saveGame(['name' => 'PUBG']);
        $uc = $this->gaming->savePackage($pubg, ['name' => '60 UC', 'price' => 100]);
        $this->gaming->done($this->paid(), $this->staff);
        $second = $this->gaming->claimPayment($this->gaming->place(null, $uc->load('game'), '555555', null, '01712345678', true), 'nagad', 'NG1234567');
        $this->gaming->done($this->gaming->acceptPayment($second, $this->staff), $this->staff);
        $this->gaming->fail($this->gaming->acceptPayment($this->gaming->claimPayment($this->order(), 'bkash', 'ZZZ999888'), $this->staff), $this->staff, 'Failed');

        $sales = $this->gaming->sales();

        $this->assertEquals([2, 195, 1], [$sales['orders'], $sales['sales'], $sales['failed']]);
        $this->assertSame(['PUBG', 'Free Fire'], $sales['by_game']->pluck('game')->all());
    }

    // ------------------------------------------------------------------ the pages

    public function test_anyone_can_browse_verify_and_order_and_the_order_page_follows_it(): void
    {
        $this->get('/gaming')->assertInertia(fn (Assert $p) => $p->component('gaming/index')->has('games', 1));
        $this->get('/gaming/free-fire')->assertInertia(fn (Assert $p) => $p->component('gaming/show')->has('packages', 1)->where('game.id_label', 'Player ID'));
        $this->postJson('/gaming/free-fire/verify', ['player_id' => '123456789'])->assertOk()->assertJson(['status' => 'unavailable']);

        $this->post("/gaming/packages/{$this->d100->id}/order", ['player_id' => '123456789', 'contact_phone' => '+880 1712-345678', 'confirmed' => '1'])->assertSessionHasNoErrors();
        $order = GameOrder::firstOrFail();

        $this->get("/gaming/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->component('gaming/order')->where('order.status', 'placed')->where('order.price', 95)->where('pay.bkash', '01700000000')->where('methods', ['bkash', 'nagad', 'rocket']));
        $this->post("/gaming/orders/{$order->public_token}/claim", ['method' => 'bkash', 'ref' => 'ABC123XYZ9'])->assertSessionHasNoErrors();
        $this->assertSame('claimed', $order->fresh()->status);
    }

    public function test_the_buyer_must_tick_that_the_id_is_right(): void
    {
        $this->post("/gaming/packages/{$this->d100->id}/order", ['player_id' => '123456789', 'contact_phone' => '01712345678'])->assertSessionHasErrors('confirmed');
        $this->assertSame(0, GameOrder::count());
    }

    public function test_a_switched_off_game_is_not_found(): void
    {
        $this->gaming->saveGame(['name' => 'Free Fire', 'is_active' => false], $this->ff);

        $this->get('/gaming/free-fire')->assertRedirect('/');
        $this->get('/gaming')->assertInertia(fn (Assert $p) => $p->has('games', 0));
    }

    public function test_we_run_the_games_and_the_orders_from_the_admin_page(): void
    {
        $this->actingAs($this->admin)->post('/admin/gaming/games', ['name' => 'PUBG', 'id_label' => 'Character ID'])->assertSessionHasNoErrors();
        $pubg = Game::where('name', 'PUBG')->firstOrFail();
        $this->post("/admin/gaming/games/{$pubg->id}/packages", ['name' => '60 UC', 'price' => 100])->assertSessionHasNoErrors();
        $package = $pubg->packages()->firstOrFail();
        $this->put("/admin/gaming/packages/{$package->id}", ['name' => '60 UC', 'price' => 110, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertEquals(110, $package->fresh()->price);

        $o = $this->gaming->claimPayment($this->order(), 'bkash', 'ABC123XYZ9');
        $this->get('/admin/gaming')->assertInertia(fn (Assert $p) => $p->component('admin/gaming')->has('toCheck', 1)->has('games', 2)->where('canEdit', true));
        $this->post("/admin/gaming/orders/{$o->id}/accept")->assertSessionHasNoErrors();
        $this->post("/admin/gaming/orders/{$o->id}/done", ['ref' => 'M-1'])->assertSessionHasNoErrors();
        $this->assertSame('topped_up', $o->fresh()->status);
        $this->post("/admin/gaming/orders/{$o->id}/bogus")->assertNotFound();
    }

    public function test_those_who_handle_orders_see_the_orders_but_only_the_owner_edits_prices(): void
    {
        $this->actingAs($this->staff)->get('/admin/gaming')->assertInertia(fn (Assert $p) => $p->where('canEdit', false));
        $this->post("/admin/gaming/games/{$this->ff->id}/packages", ['name' => '5 Diamonds', 'price' => 1])->assertRedirect('/');
        $this->assertSame(1, GamePackage::count());

        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/admin/gaming')->assertRedirect('/');
    }
}
