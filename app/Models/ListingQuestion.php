<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A question under a seller's product (spec ch 23). Everyone sees an answered one that is not hidden; nobody learns who the seller is. */
class ListingQuestion extends Model
{
    protected $fillable = ['listing_id', 'seller_id', 'user_id', 'question', 'answer', 'answered_by', 'answered_at', 'escalated_at', 'hidden'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'escalated_at' => 'datetime', 'hidden' => 'boolean'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePublic(Builder $q): Builder
    {
        return $q->whereNotNull('answered_at')->where('hidden', false);
    }

    public function scopeWaiting(Builder $q): Builder
    {
        return $q->whereNull('answered_at')->where('hidden', false);
    }
}
