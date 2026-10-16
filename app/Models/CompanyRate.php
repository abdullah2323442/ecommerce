<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['discount_percent' => 'decimal:2'];
    }
}
