<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    protected $fillable = ['stock_source_id', 'invoice_number', 'invoice_date', 'entered_by', 'total_cost', 'paid_amount', 'due_date', 'note'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'total_cost' => 'decimal:2', 'paid_amount' => 'decimal:2'];
    }

    /** What is still unpaid to the company on this purchase. */
    public function owed(): float
    {
        return max(0.0, round((float) $this->total_cost - (float) $this->paid_amount, 2));
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(StockSource::class, 'stock_source_id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }
}
