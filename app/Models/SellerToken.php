<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A seller's own token (spec ch 24): the same for every buyer, only on that seller's products, paid for by the seller. */
class SellerToken extends Model
{
    protected $fillable = ['seller_id', 'amount', 'min_order', 'per_buyer', 'active', 'ends_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'min_order' => 'decimal:2', 'per_buyer' => 'integer', 'active' => 'boolean', 'ends_at' => 'datetime'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function uses(): HasMany
    {
        return $this->hasMany(SellerTokenUse::class);
    }

    public function isOpen(): bool
    {
        return $this->active && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
