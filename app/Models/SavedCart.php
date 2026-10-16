<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Only that a signed-in buyer has things in their cart, and since when. The cart itself stays in the session. */
class SavedCart extends Model
{
    protected $fillable = ['user_id', 'items', 'touched_at', 'reminded_at'];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['touched_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
