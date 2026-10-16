<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry in our central ingredient list (spec ch 17). */
class Ingredient extends Model
{
    public const GROUPS = ['meat', 'cheese', 'vegetables', 'sauces', 'extras', 'spices'];

    protected $fillable = ['name', 'name_bn', 'group', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
