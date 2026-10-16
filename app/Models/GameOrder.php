<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One top-up order. Online payment only: there is no cash on delivery for a top-up (spec ch 18). */
class GameOrder extends Model
{
    protected $fillable = [
        'public_token', 'buyer_id', 'game_id', 'game_package_id', 'game_name', 'package_name', 'price', 'player_id', 'zone', 'player_name', 'id_confirmed_at', 'contact_phone', 'status',
        'pay_method', 'pay_ref', 'pay_note', 'claimed_at', 'paid_at', 'topped_up_at', 'topup_ref', 'failed_at', 'fail_reason', 'refund', 'refunded_at', 'late_told_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2', 'id_confirmed_at' => 'datetime', 'claimed_at' => 'datetime', 'paid_at' => 'datetime', 'topped_up_at' => 'datetime', 'failed_at' => 'datetime',
            'refunded_at' => 'datetime', 'late_told_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function number(): string
    {
        return sprintf('G%s-%06d', ($this->created_at ?? now())->format('ymd'), $this->id);
    }
}
