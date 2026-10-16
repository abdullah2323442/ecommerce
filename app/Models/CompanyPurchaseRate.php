<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPurchaseRate extends Model
{
    protected $fillable = ['company_id', 'percent'];
}
