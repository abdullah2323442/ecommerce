<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpoBid extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['percent' => 'decimal:2', 'box_price' => 'decimal:2'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MpoListing::class, 'mpo_listing_id');
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bidder_id');
    }
}
