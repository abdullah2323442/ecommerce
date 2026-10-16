<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A question under a product. Only an answered one that is not hidden is shown to everyone (spec ch 23). */
class ProductQuestion extends Model
{
    protected $fillable = ['medicine_id', 'user_id', 'question', 'answer', 'answered_by', 'answered_at', 'hidden'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'hidden' => 'boolean'];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->whereNotNull('answered_at')->where('hidden', false);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('answered_at')->where('hidden', false);
    }
}
