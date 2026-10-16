<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Courier extends Model
{
    public const ZONES = ['own', 'district', 'outside'];

    protected $fillable = ['name', 'provider', 'cod_percent', 'is_active'];

    protected function casts(): array
    {
        return ['cod_percent' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(CourierRate::class);
    }
}
