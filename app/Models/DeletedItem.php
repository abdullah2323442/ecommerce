<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing the owner deleted, kept whole for a while so it can be put back. */
class DeletedItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'label', 'payload', 'deleted_by', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
