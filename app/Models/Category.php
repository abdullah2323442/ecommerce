<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One node in a shop's category tree. A node with no parent is a top category of its sector. */
class Category extends Model
{
    protected $fillable = ['sector_id', 'parent_id', 'slug', 'name', 'name_bn', 'image_path', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('is_active', true)->orderBy('sort')->orderBy('name');
    }

    /** This node and every node below it, as ids: what a product anywhere under it should be found by. @return array<int, int> */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];
        $level = [$this->id];

        while ($level !== []) {
            $level = self::whereIn('parent_id', $level)->where('is_active', true)->pluck('id')->all();
            $ids = array_merge($ids, $level);
        }

        return $ids;
    }

    /** The nodes from the top of the sector down to this one, for a breadcrumb. @return array<int, self> */
    public function trail(): array
    {
        $trail = [$this];

        for ($node = $this; $node->parent_id !== null && ($node = $node->parent()->first());) {
            array_unshift($trail, $node);
        }

        return $trail;
    }
}
