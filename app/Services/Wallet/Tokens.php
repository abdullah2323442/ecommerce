<?php

namespace App\Services\Wallet;

use App\Models\AquaToken;
use App\Models\CreditEntry;
use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\SellerToken;
use App\Models\SellerTokenUse;
use App\Models\User;
use App\Services\Stock\StockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The wallet and the Aqua Token (spec ch 24). Two kinds of token, each inside its own boundary:
 *  - a platform token we give to one person, good in every sector, once;
 *  - a seller's own token, the same for all their buyers, good only on that seller's products and paid for by the seller.
 * A token never combines with an offer price, and two tokens are never added: the larger one applies (spec ch 04).
 * A cancelled order gives its token back. Return credit is a separate part of the wallet that only works in its own sector.
 */
class Tokens
{
    // ------------------------------------------------------------------ the wallet

    /** What the buyer sees: the part that works everywhere, and the part that works only in one sector. */
    public function wallet(User $user): array
    {
        $ready = AquaToken::usable()->where('user_id', $user->id)->orderBy('expires_at')->orderBy('id')->get();

        return [
            'everywhere' => [
                'total' => round((float) $ready->sum('amount'), 2),
                'tokens' => $ready->map(fn (AquaToken $t) => ['id' => $t->id, 'amount' => (float) $t->amount, 'note' => $t->note, 'expires_at' => $t->expires_at?->toDateString()])->values()->all(),
            ],
            'sector' => $this->credits($user),
        ];
    }

    /** Return credit, one line for each sector that holds some. */
    public function credits(User $user): array
    {
        return CreditEntry::where('user_id', $user->id)->selectRaw('sector, sum(amount) as balance')->groupBy('sector')->get()
            ->filter(fn ($r) => (float) $r->balance > 0)->map(fn ($r) => ['sector' => $r->sector, 'balance' => round((float) $r->balance, 2)])->values()->all();
    }

    public function history(User $user, int $limit = 30): array
    {
        return AquaToken::where('user_id', $user->id)->latest('id')->limit($limit)->get()
            ->map(fn (AquaToken $t) => ['id' => $t->id, 'amount' => (float) $t->amount, 'note' => $t->note, 'status' => $t->status(), 'at' => ($t->spent_at ?? $t->created_at)->toIso8601String()])->all();
    }

    // ------------------------------------------------------------------ platform tokens (the owner gives them)

    public function give(User $to, float $amount, ?string $note, ?Carbon $expires, User $by): AquaToken
    {
        if ($amount < 1 || $amount > 100000) {
            throw new StockException('A token must be worth between 1 and 100000.');
        }

        if ($expires && $expires->isPast()) {
            throw new StockException('The last day must be in the future.');
        }

        return AquaToken::create(['user_id' => $to->id, 'amount' => round($amount, 2), 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 160) : null, 'given_by' => $by->id, 'expires_at' => $expires?->endOfDay()]);
    }

    public function takeBack(AquaToken $token): AquaToken
    {
        if ($token->spent_at) {
            throw new StockException('This token was already used.');
        }

        $token->update(['voided_at' => now()]);

        return $token->fresh();
    }

    /** The one platform token that would be used: the biggest, and the one that ends first when two are alike. */
    public function best(User $user): ?AquaToken
    {
        return AquaToken::usable()->where('user_id', $user->id)->orderByDesc('amount')->orderBy('expires_at')->orderBy('id')->first();
    }

    // ------------------------------------------------------------------ on a shop order (an invoice)

    /** The buyer asks for their token on an order that is being placed. It is only spent when the order is finished. */
    public function chooseForInvoice(Invoice $invoice, ?User $user): void
    {
        if (! $user || ! ($token = $this->best($user))) {
            return;
        }

        $invoice->update(['aqua_token_id' => $token->id]);
    }

    /** An offer price and a token never combine. */
    public function hasOffer(Invoice $invoice): bool
    {
        return $invoice->lines()->get()->contains(fn ($l) => $l->offer_product_id !== null || $l->rate_layer === 'offer_product' || $l->rate_layer === 'offer' || $l->special_percent !== null);
    }

    /** Take the chosen token off a memo being finished. Returns the amount taken. */
    public function spendOnInvoice(Invoice $invoice, float $total): float
    {
        if (! $invoice->aqua_token_id || ! $invoice->customer_id || $total <= 0) {
            return 0.0;
        }

        if ($this->hasOffer($invoice)) {
            return 0.0;
        }

        $token = AquaToken::usable()->where('user_id', $invoice->customer_id)->whereKey($invoice->aqua_token_id)->lockForUpdate()->first();

        if (! $token) {
            return 0.0;
        }

        $take = round(min((float) $token->amount, $total), 2);
        $token->update(['spent_on' => 'invoice', 'spent_id' => $invoice->id, 'spent_at' => now()]);

        return $take;
    }

    /** The order was cancelled or corrected: the token comes back, not wasted. */
    public function releaseInvoice(Invoice $invoice): void
    {
        AquaToken::where('spent_on', 'invoice')->where('spent_id', $invoice->id)->update(['spent_on' => null, 'spent_id' => null, 'spent_at' => null]);
        $invoice->update(['aqua_token_id' => null, 'token_applied' => 0]);
    }

    // ------------------------------------------------------------------ a seller's own token

    public function sellerToken(User $seller): ?SellerToken
    {
        return SellerToken::where('seller_id', $seller->id)->first();
    }

    public function saveSellerToken(User $seller, float $amount, float $minOrder, int $perBuyer, bool $active, ?Carbon $ends): SellerToken
    {
        if ($amount < 1 || $amount > 100000) {
            throw new StockException('A token must be worth between 1 and 100000.');
        }

        if ($minOrder < 0) {
            throw new StockException('The smallest order cannot be below zero.');
        }

        return SellerToken::updateOrCreate(['seller_id' => $seller->id], [
            'amount' => round($amount, 2), 'min_order' => round(max(0, $minOrder), 2), 'per_buyer' => max(1, min(50, $perBuyer)), 'active' => $active, 'ends_at' => $ends?->endOfDay(),
        ]);
    }

    /** Times this buyer has used this seller's token on orders that still stand. */
    public function sellerUses(SellerToken $token, User $buyer): int
    {
        return SellerTokenUse::where('seller_token_id', $token->id)->where('buyer_id', $buyer->id)->whereNull('voided_at')->count();
    }

    // ------------------------------------------------------------------ on a marketplace order

    /**
     * Which token would apply to this order and how much, without spending it: the larger of the buyer's platform token and the
     * seller's own token. A platform token is paid for out of our commission, so it can never be more than the commission.
     *
     * @return array{kind: 'platform'|'seller', amount: float, token: AquaToken|SellerToken}|null
     */
    public function forMarket(?User $buyer, User $seller, float $goodsTotal, float $commission): ?array
    {
        if (! $buyer) {
            return null;
        }

        $options = [];

        if (($platform = $this->best($buyer)) && $commission > 0) {
            $options[] = ['kind' => 'platform', 'amount' => round(min((float) $platform->amount, $commission, $goodsTotal), 2), 'token' => $platform];
        }

        $own = $this->sellerToken($seller);
        if ($own && $own->isOpen() && $goodsTotal >= (float) $own->min_order && $this->sellerUses($own, $buyer) < $own->per_buyer) {
            $options[] = ['kind' => 'seller', 'amount' => round(min((float) $own->amount, $goodsTotal), 2), 'token' => $own];
        }

        usort($options, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return $options[0] ?? null;
    }

    /** Spend the token worked out by forMarket on this order. Call inside the order's transaction. */
    public function spendOnMarket(MarketOrder $order, array $pick, User $buyer): void
    {
        if ($pick['kind'] === 'platform') {
            $pick['token']->update(['spent_on' => 'market', 'spent_id' => $order->id, 'spent_at' => now()]);

            return;
        }

        SellerTokenUse::create(['seller_token_id' => $pick['token']->id, 'buyer_id' => $buyer->id, 'market_order_id' => $order->id, 'amount' => $pick['amount']]);
    }

    /** The order was cancelled: the token comes back. */
    public function releaseMarket(MarketOrder $order): void
    {
        DB::transaction(function () use ($order) {
            AquaToken::where('spent_on', 'market')->where('spent_id', $order->id)->update(['spent_on' => null, 'spent_id' => null, 'spent_at' => null]);
            SellerTokenUse::where('market_order_id', $order->id)->whereNull('voided_at')->update(['voided_at' => now()]);
        });
    }
}
