<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "This does not go with that": a pair a restaurant will not put together. */
class IngredientRule extends Model
{
    protected $fillable = ['restaurant_id', 'ingredient_a_id', 'ingredient_b_id'];
}
