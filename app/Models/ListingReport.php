<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A report that a market listing is a copy of someone else's (spec ch 14). */
class ListingReport extends Model
{
    protected $fillable = ['listing_id', 'reporter_id', 'reason', 'status', 'our_note', 'decided_at', 'decided_by'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
