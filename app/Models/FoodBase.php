<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What a buyer builds a custom dish on: a burger, a pizza, a sandwich, a roll. */
class FoodBase extends Model
{
    public const KINDS = ['burger', 'pizza', 'sandwich', 'roll'];

    protected $fillable = ['restaurant_id', 'kind', 'name', 'price', 'cook_minutes', 'available', 'sold_out_on'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'available' => 'boolean', 'sold_out_on' => 'date'];
    }

    public function isOrderable(): bool
    {
        return $this->available && ($this->sold_out_on === null || ! $this->sold_out_on->isSameDay(now('Asia/Dhaka')));
    }
}
