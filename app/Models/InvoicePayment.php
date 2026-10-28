<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment a customer made after the sale, against one memo (the credit ledger, spec ch 28). */
class InvoicePayment extends Model
{
    protected $fillable = ['invoice_id', 'customer_id', 'amount', 'method', 'note', 'received_by', 'received_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
