<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerTokenUse extends Model
{
    protected $fillable = ['seller_token_id', 'buyer_id', 'market_order_id', 'amount', 'voided_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'voided_at' => 'datetime'];
    }
}
