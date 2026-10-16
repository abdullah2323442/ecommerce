<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourierRate extends Model
{
    public $timestamps = false;

    protected $fillable = ['courier_id', 'zone', 'first_kg', 'extra_kg'];

    protected function casts(): array
    {
        return ['first_kg' => 'decimal:2', 'extra_kg' => 'decimal:2'];
    }
}
