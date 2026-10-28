<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A prescription sent to the marketplace for pharmacies to answer (structure only, kept ready for later). */
class RxRequest extends Model
{
    protected $fillable = ['user_id', 'phone', 'area', 'photo_path', 'note', 'status'];

    public function offers(): HasMany
    {
        return $this->hasMany(RxOffer::class);
    }
}
