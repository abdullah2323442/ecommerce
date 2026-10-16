<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A combination a buyer saved under a name. */
class FoodFavorite extends Model
{
    protected $fillable = ['user_id', 'restaurant_id', 'food_base_id', 'name', 'ingredient_ids'];

    protected function casts(): array
    {
        return ['ingredient_ids' => 'array'];
    }
}
