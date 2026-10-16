<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One visitor saying "I was looking for this". Append-only; grouped by `normalized` for the admin. */
class ProductRequest extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** Lowercase, trimmed, single-spaced, so "Napa  XR" and "napa xr" count as one request. */
    public static function normalize(string $query): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($query)));
    }
}
