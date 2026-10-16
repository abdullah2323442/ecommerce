<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A restaurant on the platform (spec ch 17). It sells; we take a commission we set by hand. */
class Restaurant extends Model
{
    protected $fillable = [
        'owner_id', 'name', 'description', 'photo_path', 'phone', 'address', 'area', 'areas', 'status', 'commission_kind', 'commission_value', 'is_open', 'opens_at', 'closes_at', 'closed_days',
        'delivery_by', 'charge_mode', 'charge_inside', 'charge_outside', 'min_charge', 'eat_there', 'advance_over', 'advance_percent', 'max_ingredients', 'custom_extra_minutes',
    ];

    protected function casts(): array
    {
        return [
            'areas' => 'array', 'closed_days' => 'array', 'is_open' => 'boolean', 'eat_there' => 'boolean', 'commission_value' => 'decimal:2', 'charge_inside' => 'decimal:2',
            'charge_outside' => 'decimal:2', 'min_charge' => 'decimal:2', 'advance_over' => 'decimal:2',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FoodItem::class)->orderBy('sort')->orderBy('name');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(FoodOrder::class);
    }
}
