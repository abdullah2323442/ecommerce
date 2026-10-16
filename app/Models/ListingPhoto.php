<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One more picture of a product (the first picture is the listing's own). */
class ListingPhoto extends Model
{
    protected $fillable = ['listing_id', 'path', 'position'];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
