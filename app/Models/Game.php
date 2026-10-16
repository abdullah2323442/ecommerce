<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A game we sell top-ups for (spec ch 18). */
class Game extends Model
{
    protected $fillable = ['name', 'slug', 'id_label', 'needs_zone', 'zone_label', 'photo_path', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['needs_zone' => 'boolean', 'is_active' => 'boolean'];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(GamePackage::class)->orderBy('sort')->orderBy('price');
    }
}
