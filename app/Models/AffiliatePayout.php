<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money paid to an affiliate, written down with who paid it and how. */
class AffiliatePayout extends Model
{
    public $timestamps = false;

    protected $fillable = ['affiliate_id', 'amount', 'method', 'note', 'paid_by', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }
}
