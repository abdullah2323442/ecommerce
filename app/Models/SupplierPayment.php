<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money we paid a company against one of its purchase invoices (what we owe, spec ch 28). */
class SupplierPayment extends Model
{
    protected $fillable = ['purchase_invoice_id', 'stock_source_id', 'amount', 'method', 'note', 'paid_by', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
