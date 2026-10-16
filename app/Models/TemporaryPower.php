<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Extra powers for a staff member for a limited time (spec ch 25). They end by themselves, or the owner takes them back sooner. */
class TemporaryPower extends Model
{
    protected $fillable = ['user_id', 'permissions', 'note', 'granted_by', 'starts_at', 'ends_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /** In force right now. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
