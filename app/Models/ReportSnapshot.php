<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One report prepared ahead of time (spec ch 25): opened instantly instead of worked out while the owner waits. */
class ReportSnapshot extends Model
{
    protected $fillable = ['kind', 'period', 'start_day', 'payload', 'prepared_at', 'prepared_by'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'prepared_at' => 'datetime'];
    }
}
