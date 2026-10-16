<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A wood or a fabric a buyer can choose for made-to-order work, with its own rate (spec ch 16). */
class CustomMaterial extends Model
{
    protected $fillable = ['kind', 'name', 'name_bn', 'rate', 'stock', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'stock' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
