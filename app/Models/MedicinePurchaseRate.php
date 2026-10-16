<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicinePurchaseRate extends Model
{
    protected $fillable = ['medicine_id', 'percent'];
}
