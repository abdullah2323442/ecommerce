<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Manual rate set by hand for one customer on one medicine. Highest priority; never touched by tier changes. */
class CustomerRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2', 'fixed_price' => 'decimal:2'];
    }
}
