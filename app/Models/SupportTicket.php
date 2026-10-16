<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A request for help from a buyer, with or without an account (spec ch 29: support tickets). */
class SupportTicket extends Model
{
    protected $fillable = ['public_token', 'user_id', 'name', 'phone', 'subject', 'topic', 'invoice_id', 'market_order_id', 'status', 'assigned_to', 'last_message_at', 'last_by', 'closed_at'];

    public const TOPICS = ['order', 'payment', 'delivery', 'product', 'account', 'other'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function number(): string
    {
        return 'T'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
