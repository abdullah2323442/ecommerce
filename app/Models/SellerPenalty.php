<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step taken against a seller (spec ch 14). It stays on record after it is lifted. */
class SellerPenalty extends Model
{
    public const STEPS = ['warning', 'listing_removed', 'suspended', 'withheld'];

    protected $fillable = ['seller_id', 'step', 'reason', 'listing_id', 'issued_by', 'lifted_at'];

    protected function casts(): array
    {
        return ['lifted_at' => 'datetime'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
