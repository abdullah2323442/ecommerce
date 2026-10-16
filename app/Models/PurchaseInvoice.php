<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    protected $fillable = ['stock_source_id', 'invoice_number', 'invoice_date', 'entered_by', 'total_cost', 'note'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'total_cost' => 'decimal:2'];
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
