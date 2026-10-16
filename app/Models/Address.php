<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved delivery address (spec ch 13: a multi-address book). */
class Address extends Model
{
    protected $fillable = ['user_id', 'label', 'name', 'phone', 'district', 'area', 'address', 'how_to_find', 'lat', 'lng'];
}
