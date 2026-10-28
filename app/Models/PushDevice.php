<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser or installed app that agreed to get notifications. */
class PushDevice extends Model
{
    protected $fillable = ['user_id', 'endpoint_hash', 'endpoint', 'p256dh', 'auth', 'agent', 'last_sent_at'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
