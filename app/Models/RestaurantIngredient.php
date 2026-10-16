<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An ingredient a restaurant has, at its own price. */
class RestaurantIngredient extends Model
{
    protected $fillable = ['restaurant_id', 'ingredient_id', 'price', 'available', 'sold_out_on'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'available' => 'boolean', 'sold_out_on' => 'date'];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** Switched on, and not sold out today (which ends by itself at midnight). */
    public function isOrderable(): bool
    {
        return $this->available && ($this->sold_out_on === null || ! $this->sold_out_on->isSameDay(now('Asia/Dhaka')));
    }
}
