<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One purchase line. `unit_cost` is locked in taka when saved and never changes (STK-6). */
class StockLot extends Model
{
    protected $fillable = [
        'medicine_id', 'purchase_invoice_id', 'units_paid', 'units_bonus', 'units_received', 'units_remaining',
        'unit_cost', 'mrp_unit_at_purchase', 'purchase_percent', 'shelf', 'expiry_date',
        'checked_at', 'expired_at', 'units_expired', 'return_status',
    ];

    protected function casts(): array
    {
        return [
            'units_paid' => 'decimal:4',
            'units_bonus' => 'decimal:4',
            'units_received' => 'decimal:4',
            'units_remaining' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'mrp_unit_at_purchase' => 'decimal:2',
            'purchase_percent' => 'decimal:3',
            'expiry_date' => 'date',
            'checked_at' => 'datetime',
            'expired_at' => 'datetime',
            'units_expired' => 'decimal:4',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }
}
