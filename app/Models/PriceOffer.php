<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reason a buyer did not buy, and sometimes a price they would pay instead (spec ch 13). */
class PriceOffer extends Model
{
    public const REASONS = ['price', 'cheaper', 'not_wanted', 'delivery', 'not_now'];

    protected $fillable = ['listing_id', 'seller_id', 'buyer_id', 'reason', 'quantity', 'offered_price', 'counter_price', 'commission_each', 'status', 'expires_at', 'answered_at', 'market_order_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'offered_price' => 'decimal:2', 'counter_price' => 'decimal:2', 'commission_each' => 'decimal:2', 'expires_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** The price the buyer can now order at: the seller's agreement, or the seller's counter. */
    public function agreedPrice(): ?float
    {
        return match ($this->status) {
            'accepted' => (float) $this->offered_price,
            'countered' => (float) $this->counter_price,
            default => null,
        };
    }
}
