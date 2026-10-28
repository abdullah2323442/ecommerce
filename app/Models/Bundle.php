<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A few products of one seller that are cheaper bought together. */
class Bundle extends Model
{
    protected $fillable = ['seller_id', 'title', 'percent', 'is_active'];

    protected function casts(): array
    {
        return ['percent' => 'integer', 'is_active' => 'boolean'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'bundle_items')->orderBy('listings.id');
    }
}
