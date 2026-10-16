<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A made-to-order job in wood or clothing (spec ch 16). We price it ourselves as a memo (material, wages, total); there is no commission on it
 * and no return, because it is made to one person's measurements.
 */
class CustomOrder extends Model
{
    /** Still going: not collected, not cancelled. */
    public const OPEN = ['requested', 'priced', 'confirmed', 'making', 'trial', 'done'];

    protected $fillable = [
        'public_token', 'buyer_id', 'kind', 'material_id', 'material_name', 'material_rate', 'fabric_by', 'items', 'details', 'note', 'photo_path', 'urgent', 'status',
        'material_cost', 'material_qty', 'material_used_at', 'wages_total', 'base_price', 'urgent_fee', 'price', 'advance_percent', 'advance_paid', 'days_needed', 'due_on', 'memo_note', 'priced_at',
        'buyer_confirmed_at', 'started_at', 'trial_at', 'done_at', 'collected_at', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'refund_amount',
        'complaint', 'complaint_status', 'complaint_note', 'reminded_at', 'craftsman_id', 'wage_asked_id', 'wage_proposed', 'wage_proposal_note', 'waiting_note', 'waiting_since',
        'progress_nudged_at', 'late_notified_at', 'delays', 'advance_given', 'chat_buyer_read_id', 'chat_maker_read_id',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'details' => 'array', 'urgent' => 'boolean', 'due_on' => 'date', 'material_rate' => 'decimal:2', 'material_cost' => 'decimal:2', 'wages_total' => 'decimal:2',
            'base_price' => 'decimal:2', 'urgent_fee' => 'decimal:2', 'price' => 'decimal:2', 'advance_paid' => 'decimal:2', 'refund_amount' => 'decimal:2',
            'priced_at' => 'datetime', 'buyer_confirmed_at' => 'datetime', 'started_at' => 'datetime', 'trial_at' => 'datetime', 'done_at' => 'datetime', 'collected_at' => 'datetime',
            'cancelled_at' => 'datetime', 'reminded_at' => 'datetime', 'waiting_since' => 'datetime', 'progress_nudged_at' => 'datetime', 'late_notified_at' => 'datetime',
            'wage_proposed' => 'decimal:2', 'advance_given' => 'decimal:2',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CustomMaterial::class, 'material_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomOrderEvent::class)->orderBy('id');
    }

    public function craftsman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'craftsman_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CustomMember::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(CustomProgress::class)->orderBy('id');
    }

    public function chat(): HasMany
    {
        return $this->hasMany(CustomChatMessage::class)->orderBy('id');
    }

    public function moves(): HasMany
    {
        return $this->hasMany(CustomMove::class)->orderBy('id');
    }

    public function number(): string
    {
        return sprintf('C%s-%05d', ($this->created_at ?? now())->format('Y'), $this->id);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** What the buyer still has to pay on the advance. */
    public function advanceDue(): float
    {
        if ($this->price === null || $this->advance_percent === null) {
            return 0.0;
        }

        return max(0.0, round((float) $this->price * $this->advance_percent / 100 - (float) $this->advance_paid, 2));
    }
}
