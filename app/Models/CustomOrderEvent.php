<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in the history of a made-to-order job. */
class CustomOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['custom_order_id', 'actor_id', 'kind', 'note'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
