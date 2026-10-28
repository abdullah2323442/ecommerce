<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What one order earned an affiliate, or the negative row that takes it back after a return. */
class AffiliateEarning extends Model
{
    protected $fillable = ['affiliate_id', 'kind', 'source_type', 'source_id', 'base', 'percent', 'amount', 'status', 'payout_id'];

    protected function casts(): array
    {
        return ['base' => 'decimal:2', 'percent' => 'decimal:2', 'amount' => 'decimal:2'];
    }
}
