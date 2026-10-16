<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A token we gave to one person (spec ch 24): worth a fixed amount off, in any sector, used once. */
class AquaToken extends Model
{
    protected $fillable = ['user_id', 'amount', 'note', 'given_by', 'expires_at', 'voided_at', 'spent_on', 'spent_id', 'spent_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expires_at' => 'datetime', 'voided_at' => 'datetime', 'spent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not spent, not taken back, not out of date. */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->whereNull('spent_at')->whereNull('voided_at')->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function status(): string
    {
        return $this->voided_at ? 'taken_back' : ($this->spent_at ? 'used' : ($this->expires_at && $this->expires_at->isPast() ? 'expired' : 'ready'));
    }
}
