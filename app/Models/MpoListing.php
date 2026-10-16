<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpoListing extends Model
{
    public const MARKETS = ['wholesale', 'paikari', 'public'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'markets' => 'array', 'show_bonus' => 'boolean', 'show_quantity' => 'boolean', 'approved_at' => 'datetime',
            'price_wholesale' => 'decimal:2', 'price_paikari' => 'decimal:2', 'price_public' => 'decimal:2',
        ];
    }

    public function mpo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** The price per box in one market, or null when it is not sold there. */
    public function priceIn(string $market): ?float
    {
        if (! in_array($market, (array) $this->markets, true)) {
            return null;
        }

        $price = $this->{'price_'.$market};

        return $price === null ? null : (float) $price;
    }

    /** What buyers see in place of the MPO: "Anonymous 3 (store name)". The real person is never in this. */
    public function alias(): string
    {
        return 'Anonymous '.($this->mpo?->mpo_number ?? '?').' ('.(string) Setting::read('shop.name', config('app.name')).')';
    }
}
