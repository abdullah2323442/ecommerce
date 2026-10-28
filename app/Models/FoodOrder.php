<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An order for food from one restaurant (spec ch 17). */
class FoodOrder extends Model
{
    /** Still in hand: nobody has finished or cancelled it. */
    public const OPEN = ['pending', 'confirmed', 'packing', 'out', 'ready'];

    protected $fillable = [
        'public_token', 'restaurant_id', 'buyer_id', 'mode', 'status', 'ship_to', 'eat_time', 'note', 'subtotal', 'delivery_charge', 'charge_open', 'total', 'commission', 'advance_due', 'advance_paid',
        'cook_minutes', 'confirmed_at', 'ready_at', 'packing_at', 'out_at', 'delivered_at', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'refund_amount', 'nudged_at', 'complaint',
        'complaint_photo', 'complaint_reply', 'distance_km', 'distance_by_road',
    ];

    protected function casts(): array
    {
        return [
            'ship_to' => 'array', 'charge_open' => 'boolean', 'subtotal' => 'decimal:2', 'delivery_charge' => 'decimal:2', 'total' => 'decimal:2', 'commission' => 'decimal:2',
            'advance_due' => 'decimal:2', 'advance_paid' => 'decimal:2', 'refund_amount' => 'decimal:2', 'confirmed_at' => 'datetime', 'ready_at' => 'datetime', 'packing_at' => 'datetime',
            'out_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'nudged_at' => 'datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FoodOrderItem::class);
    }

    public function number(): string
    {
        return sprintf('F%s-%06d', ($this->created_at ?? now())->format('ymd'), $this->id);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Cooking has begun once the restaurant has confirmed. */
    public function cookingStarted(): bool
    {
        return $this->confirmed_at !== null;
    }
}
