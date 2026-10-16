<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Models\AquaToken;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Wallet\Referrals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 24: referral credit goes to the same wallet; a friend who joins by the link and completes a first order earns the sharer a token. */
class ReferralTest extends TestCase
{
    use RefreshDatabase;

    private User $sharer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sharer = User::factory()->create(['account_type' => AccountType::Public]);
        Setting::write('referral.reward', 50);
    }

    private function join(string $code, string $email = 'friend@example.com'): User
    {
        $this->get("/r/{$code}")->assertRedirect('/register');
        $this->post('/register', ['name' => 'Friend', 'email' => $email, 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])->assertSessionHasNoErrors();
        auth()->logout();

        return User::where('email', $email)->firstOrFail();
    }

    private function completedSale(User $buyer): Invoice
    {
        return Invoice::create(['kind' => 'retail', 'channel' => 'online', 'status' => 'final', 'customer_id' => $buyer->id, 'label' => 'x']);
    }

    public function test_a_link_remembers_who_sent_the_friend(): void
    {
        $code = app(Referrals::class)->codeFor($this->sharer);
        $friend = $this->join($code);

        $this->assertSame($this->sharer->id, $friend->referred_by);
        $this->assertSame($code, app(Referrals::class)->codeFor($this->sharer), 'the code stays the same');
    }

    public function test_an_unknown_code_or_own_code_gives_nothing(): void
    {
        $this->assertNull($this->join('NOSUCH99')->referred_by);

        app(Referrals::class)->attach($this->sharer, app(Referrals::class)->codeFor($this->sharer));
        $this->assertNull($this->sharer->fresh()->referred_by);
    }

    public function test_the_first_completed_order_earns_one_token_once(): void
    {
        $friend = $this->join(app(Referrals::class)->codeFor($this->sharer));

        $this->assertSame(0, AquaToken::where('user_id', $this->sharer->id)->count(), 'joining alone earns nothing');

        $sale = $this->completedSale($friend);
        $sale->update(['completed_at' => now()]);

        $tokens = AquaToken::where('user_id', $this->sharer->id)->get();
        $this->assertCount(1, $tokens);
        $this->assertSame('50.00', (string) $tokens[0]->amount);

        $this->completedSale($friend)->update(['completed_at' => now()]);
        $this->assertSame(1, AquaToken::where('user_id', $this->sharer->id)->count(), 'only the first order counts');
    }

    public function test_it_is_off_until_the_owner_sets_a_reward(): void
    {
        Setting::write('referral.reward', 0);
        $friend = $this->join(app(Referrals::class)->codeFor($this->sharer));
        $this->completedSale($friend)->update(['completed_at' => now()]);

        $this->assertSame(0, AquaToken::count());
        $this->actingAs($this->sharer)->get('/wallet')->assertInertia(fn ($p) => $p->where('referral.reward', 0));
    }

    public function test_the_wallet_shows_the_link_and_the_counts(): void
    {
        $friend = $this->join(app(Referrals::class)->codeFor($this->sharer));
        $this->completedSale($friend)->update(['completed_at' => now()]);

        $this->actingAs($this->sharer)->get('/wallet')->assertInertia(fn ($p) => $p->where('referral.joined', 1)->where('referral.rewarded', 1)->where('referral.reward', 50)->has('referral.link'));
    }
}
