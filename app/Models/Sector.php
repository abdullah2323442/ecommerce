<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kind of business the owner has opened (spec ch 15): its own unit, whether fractions are allowed, and where "premium" starts. */
class Sector extends Model
{
    public const FIELDS = ['color', 'size', 'measurements', 'weight', 'expiry', 'warranty'];

    protected $fillable = ['slug', 'name', 'name_bn', 'unit', 'allow_fraction', 'fields', 'custom_orders', 'catalog_required', 'premium_over', 'preorder', 'chat', 'return_rule', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['allow_fraction' => 'boolean', 'fields' => 'array', 'custom_orders' => 'boolean', 'catalog_required' => 'boolean', 'premium_over' => 'decimal:2', 'preorder' => 'boolean', 'chat' => 'boolean', 'is_active' => 'boolean'];
    }

    /** The fields this kind of business uses. A sector opened before this existed keeps colour, size and measurements. */
    public function fieldList(): array
    {
        return $this->fields === null ? ['color', 'size', 'measurements'] : array_values(array_intersect(self::FIELDS, $this->fields));
    }

    public function uses(string $field): bool
    {
        return in_array($field, $this->fieldList(), true);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
