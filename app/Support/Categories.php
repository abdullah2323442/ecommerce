<?php

namespace App\Support;

use App\Models\Sector;

/**
 * The business categories a trade buyer can be given (spec ch 14, 15): the fixed starting list, and every sector the owner has opened. A sector opened
 * in the sector builder can therefore be given to a trader the same day, with no developer.
 */
class Categories
{
    /** @return array<string, string> key (the sector code) => name */
    public static function all(): array
    {
        $own = Sector::query()->where('is_active', true)->orderBy('sort')->orderBy('name')->pluck('name', 'slug')->all();

        return array_merge((array) config('wholesale.categories'), $own);
    }

    public static function label(string $key): string
    {
        return self::all()[$key] ?? $key;
    }
}
