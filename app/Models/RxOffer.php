<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pharmacy's answer to a prescription: its price and a note (structure only, kept ready for later). */
class RxOffer extends Model
{
    protected $fillable = ['rx_request_id', 'pharmacy_id', 'price', 'note', 'status'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RxRequest::class, 'rx_request_id');
    }
}
