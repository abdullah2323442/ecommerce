<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockSource extends Model
{
    public const TYPES = ['company', 'mpo', 'shop'];

    protected $fillable = ['name', 'type', 'default_percent', 'is_active'];

    protected function casts(): array
    {
        return ['default_percent' => 'decimal:3', 'is_active' => 'boolean'];
    }
}
