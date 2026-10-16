<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One dish on a restaurant's menu. */
class FoodItem extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'description', 'price', 'photo_path', 'cook_minutes', 'ready_made', 'available', 'sold_out_on', 'sort'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'ready_made' => 'boolean', 'available' => 'boolean', 'sold_out_on' => 'date'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** On the menu right now: switched on, and not sold out today (that ends by itself at midnight). */
    public function isOrderable(): bool
    {
        return $this->available && ($this->sold_out_on === null || ! $this->sold_out_on->isSameDay(now('Asia/Dhaka')));
    }
}
