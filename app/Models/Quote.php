<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A trade buyer's request for a price, and our answer (spec ch 14: custom quotation). */
class Quote extends Model
{
    protected $fillable = ['user_id', 'request', 'quantity', 'status', 'quoted_total', 'answer_note', 'valid_until', 'answered_by', 'answered_at', 'responded_at'];

    protected function casts(): array
    {
        return ['quoted_total' => 'decimal:2', 'valid_until' => 'date', 'answered_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The price is still good to take. */
    public function isOpenToAccept(): bool
    {
        return $this->status === 'quoted' && ($this->valid_until === null || $this->valid_until->gte(today()));
    }
}
