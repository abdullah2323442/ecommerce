<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A restaurant asking us to add an ingredient to the central list. */
class IngredientRequest extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'group', 'status', 'ingredient_id', 'note', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
