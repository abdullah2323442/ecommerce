<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's complaint or return on a market order. Both the seller and we see it (spec ch 14). */
class MarketCase extends Model
{
    /** Still being dealt with. */
    public const OPEN = ['open', 'escalated'];

    protected $fillable = ['market_order_id', 'seller_id', 'buyer_id', 'kind', 'reason', 'status', 'seller_note', 'our_note', 'answered_at', 'escalated_at', 'decided_at', 'decided_by'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'escalated_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketOrder::class, 'market_order_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
