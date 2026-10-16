<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's saved measurements under a name (spec ch 16.3). */
class MeasurementProfile extends Model
{
    protected $fillable = ['user_id', 'name', 'kind', 'unit', 'values', 'measured_on'];

    protected function casts(): array
    {
        return ['values' => 'array', 'measured_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
