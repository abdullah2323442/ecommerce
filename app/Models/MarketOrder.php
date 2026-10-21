<?php

namespace App\Models;

use App\Services\Wallet\Referrals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order for a seller's listing. The commission is only ours once the sale is complete: delivered, and not reported as not received
 * (spec ch 14: "commission only on complete sales").
 */
class MarketOrder extends Model
{
    protected static function booted(): void
    {
        static::saved(function (MarketOrder $order) {
            if ($order->status === 'delivered' && $order->wasChanged('status') && $order->buyer_id !== null) {
                app(Referrals::class)->qualify(User::find($order->buyer_id));
            }
        });
    }

    /** While the goods are on their way the seller may see where to send them; after that nobody can dig the numbers out of old orders. */
    public const ACTIVE = ['placed', 'accepted', 'shipped'];

    protected $fillable = [
        'public_token', 'group_token', 'pay_by', 'paid_at', 'payout_at', 'download_max', 'downloads', 'download_until', 'preorder', 'preorder_days', 'pickup', 'pickup_ready_at', 'pickup_reminded_at', 'listing_id', 'variant_id', 'variant_label', 'seller_id', 'buyer_id', 'title', 'unit', 'quantity', 'unit_price', 'seller_price', 'commission', 'delivery_charge', 'total', 'token_amount', 'token_kind', 'audience',
        'ship_to', 'note', 'status', 'cancel_reason', 'cancelled_by', 'tracking', 'seller_seen_at', 'accepted_at', 'shipped_at', 'delivered_at', 'cancelled_at', 'not_received_at', 'returned_at',
    ];

    protected function casts(): array
    {
        return [
            'preorder' => 'boolean', 'quantity' => 'decimal:2', 'unit_price' => 'decimal:2', 'seller_price' => 'decimal:2', 'commission' => 'decimal:2', 'delivery_charge' => 'decimal:2', 'total' => 'decimal:2', 'token_amount' => 'decimal:2',
            'ship_to' => 'array', 'paid_at' => 'datetime', 'payout_at' => 'datetime', 'download_until' => 'datetime', 'pickup' => 'boolean', 'pickup_ready_at' => 'datetime', 'pickup_reminded_at' => 'datetime', 'seller_seen_at' => 'datetime', 'accepted_at' => 'datetime', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime',
            'not_received_at' => 'datetime', 'returned_at' => 'datetime',
        ];
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

    public function cases(): HasMany
    {
        return $this->hasMany(MarketCase::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function number(): string
    {
        return sprintf('M%s-%06d', ($this->created_at ?? now())->format('Y'), $this->id);
    }

    /** What the seller is paid for this order: their price for each, and the delivery charge they set. */
    public function sellerGets(): float
    {
        // The seller's own token comes out of what they are paid; a platform token comes out of our commission instead.
        $own = $this->token_kind === 'seller' ? (float) $this->token_amount : 0.0;

        return round((float) $this->quantity * (float) $this->seller_price + (float) $this->delivery_charge - $own, 2);
    }
}
