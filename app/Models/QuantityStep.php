<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One rung of a quantity discount: from `min_qty` of a unit, `value` off each (a percent, or taka). */
class QuantityStep extends Model
{
    protected $fillable = ['medicine_id', 'unit', 'min_qty', 'kind', 'value'];

    protected function casts(): array
    {
        return ['min_qty' => 'integer', 'value' => 'decimal:2'];
    }
}
