<?php

namespace App\Models;

use App\Services\Marketing\Affiliates;
use App\Services\Wallet\RewardPoints;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    protected $table = 'sale_returns';

    protected $guarded = [];

    protected static function booted(): void
    {
        // Goods that came back take the points for that money back (reward points, spec ch 27).
        static::saved(function (SaleReturn $return) {
            if ($return->status === 'approved' && $return->wasChanged('status')) {
                app(RewardPoints::class)->reverseReturn($return);
                app(Affiliates::class)->takeBackReturn($return);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'without_invoice' => 'boolean', 'amount' => 'decimal:2', 'credit_amount' => 'decimal:2', 'cash_amount' => 'decimal:2',
            'expected_money' => 'decimal:2', 'decided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }
}
