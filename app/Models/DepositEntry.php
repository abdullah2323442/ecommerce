<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One movement of a seller's security deposit (spec ch 14): taken in, given back, or deducted for a reason. */
class DepositEntry extends Model
{
    protected $fillable = ['seller_id', 'amount', 'kind', 'reason', 'by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** Who did it (the column is called by, so the relation cannot be). */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'by');
    }
}
