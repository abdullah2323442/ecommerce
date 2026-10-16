<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in the chat of a made-to-order job. */
class CustomChatMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['custom_order_id', 'sender_id', 'role', 'kind', 'body', 'file_path', 'meta', 'unanswered_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'unanswered_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class, 'custom_order_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
