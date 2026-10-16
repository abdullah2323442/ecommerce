<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The month-end statement to a seller: what they sold, what our commission is, and what they said about it (spec ch 14). */
class MarketStatement extends Model
{
    protected $fillable = ['seller_id', 'month', 'orders', 'sales', 'commission', 'status', 'seller_note', 'our_note', 'sent_at', 'replied_at', 'decided_at', 'decided_by'];

    protected function casts(): array
    {
        return ['sales' => 'decimal:2', 'commission' => 'decimal:2', 'sent_at' => 'datetime', 'replied_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
}
