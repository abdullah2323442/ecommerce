<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's booking of one time. It holds a place from the moment it is asked for until it is declined or cancelled. */
class ServiceBooking extends Model
{
    /** The states in which a booking holds its place. */
    public const HOLDING = ['pending', 'confirmed'];

    protected $fillable = ['public_token', 'offer_id', 'slot_id', 'buyer_id', 'name', 'phone', 'note', 'price', 'status', 'reason', 'decided_at', 'reminded_at'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'decided_at' => 'datetime', 'reminded_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ServiceOffer::class, 'offer_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(ServiceSlot::class, 'slot_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
