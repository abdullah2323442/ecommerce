<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A flash sale on one listing: a percent off the seller's price between two moments. */
class ListingSale extends Model
{
    protected $fillable = ['listing_id', 'percent', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['percent' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function isLive(): bool
    {
        return $this->starts_at->lte(now()) && $this->ends_at->gt(now());
    }
}
