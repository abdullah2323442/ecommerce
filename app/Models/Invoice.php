<?php

namespace App\Models;

use App\Services\Marketing\Affiliates;
use App\Services\Sync\PharmaTrack;
use App\Services\Wallet\Referrals;
use App\Services\Wallet\RewardPoints;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected static function booted(): void
    {
        // An order from the website that came by an affiliate's link is the affiliate's (spec ch 27).
        static::creating(function (Invoice $invoice) {
            if ($invoice->channel === 'online' && $invoice->affiliate_id === null) {
                $invoice->affiliate_id = app(Affiliates::class)->current($invoice->customer_id)?->id;
            }
        });

        static::saved(function (Invoice $invoice) {
            // A finished online order is told to PharmaTrack, so it takes the same goods off its shelf (spec item 612).
            if ($invoice->channel === 'online' && $invoice->completed_at !== null && ($invoice->wasRecentlyCreated || $invoice->wasChanged('completed_at'))) {
                app(PharmaTrack::class)->announceOnlineSale($invoice);
            }

            if ($invoice->affiliate_id !== null && $invoice->completed_at !== null && ($invoice->wasRecentlyCreated || $invoice->wasChanged('completed_at'))) {
                app(Affiliates::class)->earnOnInvoice($invoice);
            }

            if ($invoice->affiliate_id !== null && $invoice->status === 'cancelled' && $invoice->wasChanged('status')) {
                app(Affiliates::class)->cancelOnInvoice($invoice);
            }
        });

        // A completed sale to an account that was sent by a friend's referral earns the friend a token (spec ch 24).
        static::saved(function (Invoice $invoice) {
            if ($invoice->completed_at !== null && ($invoice->wasRecentlyCreated || $invoice->wasChanged('completed_at')) && $invoice->customer_id !== null) {
                app(Referrals::class)->qualify(User::find($invoice->customer_id));
                app(RewardPoints::class)->earnOnInvoice($invoice);
            }

            // A memo that was cancelled (or corrected, which cancels the old one) takes its points back.
            if ($invoice->status === 'cancelled' && $invoice->wasChanged('status') && $invoice->customer_id !== null) {
                app(RewardPoints::class)->reverseInvoice($invoice);
            }
        });
    }

    public const KINDS = ['retail', 'paikari', 'wholesale'];

    protected $fillable = [
        'number', 'public_token', 'ship_to', 'kind', 'channel', 'status', 'customer_id', 'label', 'opened_by', 'finalised_by', 'invoice_percent', 'end_discount_percent',
        'delivery_mode', 'collector_name', 'collector_phone', 'delivery_charge', 'paid_amount', 'payment_method', 'payment_terms', 'courier_id', 'courier_consignment', 'courier_status', 'courier_status_at', 'courier_booked_at', 'delivery_person_id', 'handled_by', 'arrived_at', 'delivery_code', 'delivery_code_tries', 'delivery_photo_path', 'cash_collected', 'cash_handed_at', 'sent_at', 'delivery_advance_at', 'delivery_refund', 'courier_terms', 'courier_cost', 'cartons', 'tracking', 'delivery_extra', 'delivered_at', 'delivered_by', 'not_received_at', 'working_by', 'working_at', 'subtotal', 'discount_total', 'total', 'note',
        'opened_at', 'last_activity_at', 'placed_at', 'verified_at', 'cancel_requested_at', 'stale_snooze_until', 'offline_ref', 'offline_at', 'offline_flags', 'voice_path', 'photo_path', 'wanted_time', 'credit_applied', 'aqua_token_id', 'token_applied', 'print_count', 'corrects_invoice_id', 'finalised_at', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'ship_to' => 'array', 'delivery_extra' => 'decimal:2', 'sent_at' => 'datetime', 'delivery_advance_at' => 'datetime', 'courier_cost' => 'decimal:2', 'arrived_at' => 'datetime', 'courier_status_at' => 'datetime', 'courier_booked_at' => 'datetime', 'cash_collected' => 'decimal:2', 'cash_handed_at' => 'datetime', 'delivered_at' => 'datetime', 'not_received_at' => 'datetime', 'working_at' => 'datetime', 'invoice_percent' => 'decimal:2', 'end_discount_percent' => 'decimal:2', 'delivery_charge' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'credit_applied' => 'decimal:2', 'token_applied' => 'decimal:2', 'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'total' => 'decimal:2',
            'opened_at' => 'datetime', 'last_activity_at' => 'datetime', 'placed_at' => 'datetime', 'verified_at' => 'datetime', 'cancel_requested_at' => 'datetime', 'stale_snooze_until' => 'datetime', 'offline_at' => 'datetime', 'offline_flags' => 'array', 'finalised_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(Courier::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** Lines still on the invoice (removed ones stay in the table for the history). */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->whereNull('removed_at');
    }

    public function allLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
