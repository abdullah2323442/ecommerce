<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One product in the central catalog (spec ch 13). Product names are never translated; the Bangla spelling is only a way to find it. */
class CatalogProduct extends Model
{
    protected $fillable = ['sector_id', 'name', 'name_bn', 'status', 'requested_by', 'decided_by', 'note'];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
