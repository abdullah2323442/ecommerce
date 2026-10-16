<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A review from someone whose order really arrived (spec ch 13: "Verified buyer"). */
class ListingReview extends Model
{
    protected $fillable = ['listing_id', 'market_order_id', 'buyer_id', 'rating', 'body', 'hidden'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'hidden' => 'boolean'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketOrder::class, 'market_order_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ListingReviewPhoto::class);
    }
}
