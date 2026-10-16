<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Assigned, shifted or shared: who gave a job to whom. */
class CustomMove extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['custom_order_id', 'kind', 'from_id', 'to_id', 'by_id', 'note'];

    public function from(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_id');
    }
}
