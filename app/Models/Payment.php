<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One try to pay online. It counts as money only after we have asked the gateway ourselves and the answer matched (see App\Services\Payments\Payments). */
class Payment extends Model
{
    protected $fillable = ['ref', 'payable_type', 'payable_id', 'user_id', 'provider', 'amount', 'currency', 'status', 'val_id', 'bank_tran_id', 'method', 'risk_level', 'live', 'paid_at', 'refunded_at', 'refunded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'live' => 'boolean', 'paid_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Invoice|null the order this pays for (the only kind so far) */
    public function marketOrder(): ?MarketOrder
    {
        return $this->payable_type === 'market_order' ? MarketOrder::find($this->payable_id) : null;
    }

    public function invoice(): ?Invoice
    {
        return $this->payable_type === 'invoice' ? Invoice::find($this->payable_id) : null;
    }
}
