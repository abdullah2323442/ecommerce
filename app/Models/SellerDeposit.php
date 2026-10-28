<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A seller's own required deposit, when the owner sets one that differs from the usual amount. */
class SellerDeposit extends Model
{
    protected $fillable = ['seller_id', 'required'];

    protected function casts(): array
    {
        return ['required' => 'decimal:2'];
    }
}
