<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingReviewPhoto extends Model
{
    public $timestamps = false;

    protected $fillable = ['listing_review_id', 'path'];

    public function review(): BelongsTo
    {
        return $this->belongsTo(ListingReview::class, 'listing_review_id');
    }
}
