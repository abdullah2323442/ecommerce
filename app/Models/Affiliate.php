<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Someone outside the business who sends buyers to the site and earns a share of what they spend. */
class Affiliate extends Model
{
    protected $fillable = ['user_id', 'name', 'phone', 'code', 'percent', 'active', 'clicks', 'note'];

    protected function casts(): array
    {
        return ['percent' => 'decimal:2', 'active' => 'boolean', 'clicks' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(AffiliateEarning::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }
}
