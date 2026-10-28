<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Someone who said they want the prescription marketplace when it opens. */
class RxInterest extends Model
{
    protected $fillable = ['user_id', 'name', 'phone', 'area'];
}
