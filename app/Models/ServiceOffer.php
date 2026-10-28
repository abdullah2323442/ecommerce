<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A service someone gives and people book (a lesson, a repair, a visit). */
class ServiceOffer extends Model
{
    protected $fillable = ['provider_id', 'title', 'description', 'minutes', 'price', 'place', 'area', 'active', 'admin_hidden'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'minutes' => 'integer', 'active' => 'boolean', 'admin_hidden' => 'boolean'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ServiceSlot::class, 'offer_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ServiceBooking::class, 'offer_id');
    }
}
