<?php

namespace App\Services\Wallet;

use App\Models\AquaToken;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\RewardPoint;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stock\StockException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reward points (spec ch 27, the extras): a signed-in buyer earns points on what they buy, in the pharmacy and in the market, and turns them into an Aqua Token
 * (spec ch 24: tokens are the only kind of discount). The owner sets how many points a hundred taka earns (0 means the programme is off), what a hundred
 * points are worth, and the least that can be turned in. A purchase earns once, delivery is not counted, and a cancelled or returned purchase takes its points back.
 */
class RewardPoints
{
    public function __construct(private Tokens $tokens) {}

    /** Points earned for every 100 taka; 0 means off. */
    public function per100(): int
    {
        return max(0, (int) Setting::read('points.per_100', 0));
    }

    public function enabled(): bool
    {
        return $this->per100() > 0;
    }

    /** Taka of token a hundred points are worth. */
    public function worth(): float
    {
        return max(0.0, round((float) Setting::read('points.worth', 10), 2));
    }

    public function minimum(): int
    {
        return max(1, (int) Setting::read('points.min_redeem', 100));
    }

    public function balance(User $user): int
    {
        return (int) RewardPoint::where('user_id', $user->id)->sum('points');
    }

    /** The token that this many points would become, in taka. */
    public function valueOf(int $points): float
    {
        return round($points / 100 * $this->worth(), 2);
    }

    /** @return list<array{id: int, points: int, kind: string, note: ?string, at: string}> */
    public function history(User $user, int $limit = 12): array
    {
        return RewardPoint::where('user_id', $user->id)->latest('id')->limit($limit)->get()->map(fn (RewardPoint $r) => [
            'id' => $r->id, 'points' => $r->points, 'kind' => $r->kind, 'note' => $r->note, 'at' => $r->created_at->toIso8601String(),
        ])->all();
    }

    /** @return array{on: bool, balance: int, per100: int, worth: float, minimum: int, history: list<array<string, mixed>>} */
    public function summary(User $user): array
    {
        return ['on' => $this->enabled(), 'balance' => $this->balance($user), 'per100' => $this->per100(), 'worth' => $this->worth(), 'minimum' => $this->minimum(), 'history' => $this->history($user)];
    }

    // ------------------------------------------------------------------ earning

    /** A finished memo earns its customer points, once. Delivery is not counted. */
    public function earnOnInvoice(Invoice $invoice): void
    {
        $this->earn($invoice->customer_id, 'invoice', $invoice->id, (float) $invoice->total - (float) $invoice->delivery_charge, 'A purchase');
    }

    /** A delivered market order earns its buyer points, once. Delivery is not counted. */
    public function earnOnMarketOrder(MarketOrder $order): void
    {
        $this->earn($order->buyer_id, 'market', $order->id, (float) $order->total - (float) $order->delivery_charge, 'A market order');
    }

    private function earn(?int $userId, string $type, int $id, float $spent, string $note): void
    {
        if ($userId === null || ! $this->enabled() || $spent <= 0) {
            return;
        }

        $points = (int) floor($spent / 100 * $this->per100());

        if ($points < 1) {
            return;
        }

        try {
            RewardPoint::create(['user_id' => $userId, 'points' => $points, 'kind' => 'earn', 'source_type' => $type, 'source_id' => $id, 'note' => $note]);
        } catch (UniqueConstraintViolationException) {
            // This purchase has paid its points already.
        }
    }

    // ------------------------------------------------------------------ taking back

    /** A purchase was cancelled: what it earned goes back, once. */
    public function reverseInvoice(Invoice $invoice): void
    {
        $this->reverseEarned($invoice->customer_id, 'invoice', $invoice->id, 'A purchase was cancelled');
    }

    public function reverseMarketOrder(MarketOrder $order): void
    {
        $this->reverseEarned($order->buyer_id, 'market', $order->id, 'An order was cancelled or sent back');
    }

    /** Goods came back: the points for that much money go back, once for each return. */
    public function reverseReturn(SaleReturn $return): void
    {
        if ($return->customer_id === null || ! $this->enabled()) {
            return;
        }

        $points = (int) floor((float) $return->amount / 100 * $this->per100());
        $this->take($return->customer_id, $points, 'return', $return->id, 'Goods were returned');
    }

    private function reverseEarned(?int $userId, string $type, int $id, string $note): void
    {
        if ($userId === null) {
            return;
        }

        $earned = (int) RewardPoint::where(['user_id' => $userId, 'kind' => 'earn', 'source_type' => $type, 'source_id' => $id])->value('points');
        $this->take($userId, $earned, $type, $id, $note);
    }

    /** Take points back, never more than the buyer has: points already turned into a token stay spent. */
    private function take(int $userId, int $points, string $type, int $id, string $note): void
    {
        $have = (int) RewardPoint::where('user_id', $userId)->sum('points');
        $points = min($points, max(0, $have));

        if ($points < 1) {
            return;
        }

        try {
            RewardPoint::create(['user_id' => $userId, 'points' => -$points, 'kind' => 'reverse', 'source_type' => $type, 'source_id' => $id, 'note' => $note]);
        } catch (UniqueConstraintViolationException) {
            // Already taken back.
        }
    }

    // ------------------------------------------------------------------ spending

    /**
     * Turn points into an Aqua Token that works in every sector, once.
     *
     * @throws StockException
     */
    public function convert(User $user, int $points): AquaToken
    {
        if (! $this->enabled()) {
            throw new StockException('Reward points are not switched on.');
        }

        if ($points < $this->minimum()) {
            throw new StockException('You have asked for too few points to make a token. The least is shown above.');
        }

        $amount = $this->valueOf($points);

        if ($amount < 1) {
            throw new StockException('Those points are worth less than a token.');
        }

        return DB::transaction(function () use ($user, $points, $amount) {
            // Lock the buyer's row so two taps cannot spend the same points twice.
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($points > $this->balance($user)) {
                throw new StockException('You do not have that many points.');
            }

            $token = $this->tokens->give($user, $amount, 'Reward points', null, $user);
            RewardPoint::create(['user_id' => $user->id, 'points' => -$points, 'kind' => 'spend', 'token_id' => $token->id, 'note' => 'Turned into a token']);

            return $token;
        });
    }
}
