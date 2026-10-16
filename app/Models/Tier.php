<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2', 'upgrade_target' => 'decimal:2'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
