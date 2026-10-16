<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A post on the Help board (spec ch 19). Nobody sees it until we approve it; who wrote it is never shown. */
class HelpPost extends Model
{
    public const CATEGORIES = ['worker', 'product', 'help'];

    protected $fillable = [
        'user_id', 'category', 'title', 'body', 'contact', 'status', 'review_note', 'reviewed_by', 'approved_at', 'confirmed_at', 'asked_at',
        'closed_at', 'closed_by', 'hidden',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'confirmed_at' => 'datetime', 'asked_at' => 'datetime', 'closed_at' => 'datetime', 'hidden' => 'boolean'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(HelpComment::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(HelpReport::class);
    }

    /** What anyone may read: approved, still open, and not taken down. */
    public function scopeShown(Builder $query): Builder
    {
        return $query->where('status', 'open')->where('hidden', false);
    }

    public function isShown(): bool
    {
        return $this->status === 'open' && ! $this->hidden;
    }
}
