<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A line of a food order, as the dish was named and priced when it was ordered. */
class FoodOrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['food_order_id', 'food_item_id', 'name', 'price', 'quantity', 'note', 'ingredients'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FoodOrder::class, 'food_order_id');
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'ingredients' => 'array'];
    }
}
