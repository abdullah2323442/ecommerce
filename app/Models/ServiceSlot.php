<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time a service can be booked, with the number of places in it. */
class ServiceSlot extends Model
{
    protected $fillable = ['offer_id', 'starts_at', 'capacity', 'booked'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'capacity' => 'integer', 'booked' => 'integer'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ServiceOffer::class, 'offer_id');
    }

    public function free(): int
    {
        return max(0, $this->capacity - $this->booked);
    }
}
