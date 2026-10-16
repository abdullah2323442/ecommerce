<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail. Never update or delete rows from application code. */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }
}
