<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Preorder extends Model
{
    protected $fillable = [
        'customer_id', 'status', 'promised_days', 'due_on', 'advance_amount', 'advance_refunded', 'advance_state', 'reply_note', 'cancelled_by', 'cancel_note',
        'extended', 'accepted_at', 'told_at', 'invoice_id', 'mpo_listing_id', 'box_price', 'bonus_free', 'mpo_taken_at',
    ];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'accepted_at' => 'datetime', 'told_at' => 'datetime', 'mpo_taken_at' => 'datetime', 'advance_amount' => 'decimal:2', 'box_price' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PreorderLine::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MpoListing::class, 'mpo_listing_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'accepted'], true);
    }

    /** Past the promised time and still waiting: shown red (spec ch 21). */
    public function isLate(): bool
    {
        return $this->status === 'accepted' && $this->due_on !== null && $this->due_on->lt(today());
    }
}
