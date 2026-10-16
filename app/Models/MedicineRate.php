<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicineRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2', 'fixed_price' => 'decimal:2'];
    }
}
