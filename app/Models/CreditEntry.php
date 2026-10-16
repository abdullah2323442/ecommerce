<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
