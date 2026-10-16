<?php

namespace App\Services\Market;

use App\Models\MarketOrder;
use App\Models\Setting;
use App\Models\User;

/**
 * Gradual trust (spec ch 14): a new seller starts with low-value products. After a number of finished orders with no complaint the limit lifts.
 * The owner can trust a seller sooner.
 */
class SellerTrust
{
    public function needed(): int
    {
        return max(1, (int) Setting::read('market.trust_orders', 10));
    }

    /** The dearest a new seller may price a product, in taka. */
    public function limit(): float
    {
        return max(1, (float) Setting::read('market.trust_limit', 5000));
    }

    /** Orders that arrived and never had a complaint or a return. */
    public function clean(User $seller): int
    {
        return MarketOrder::where('seller_id', $seller->id)->where('status', 'delivered')->whereDoesntHave('cases')->count();
    }

    public function trusted(User $seller): bool
    {
        return $seller->seller_trusted_at !== null || $this->clean($seller) >= $this->needed();
    }

    /** @return array{trusted: bool, clean: int, needed: int, limit: ?float} */
    public function status(User $seller): array
    {
        $trusted = $this->trusted($seller);

        return ['trusted' => $trusted, 'clean' => $this->clean($seller), 'needed' => $this->needed(), 'limit' => $trusted ? null : $this->limit()];
    }

    /** The price a seller may not go above yet, or null when they may price freely. */
    public function ceiling(User $seller): ?float
    {
        return $this->trusted($seller) ? null : $this->limit();
    }
}
