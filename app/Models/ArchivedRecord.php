<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An old record moved out of daily work: kept whole in payload, and findable by the words in summary. */
class ArchivedRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['source', 'source_id', 'occurred_at', 'summary', 'payload', 'archived_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'archived_at' => 'datetime', 'payload' => 'array'];
    }
}
