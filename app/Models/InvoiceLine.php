<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id', 'medicine_id', 'from_invoice_id', 'offer_product_id', 'unit', 'quantity', 'requested_quantity', 'verify_status', 'stock_units', 'unit_mrp', 'unit_price', 'rate_layer',
        'percent_off', 'special_percent', 'is_outside', 'held_for_loss', 'removed_at', 'checked_by', 'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'requested_quantity' => 'decimal:2', 'stock_units' => 'decimal:4', 'unit_mrp' => 'decimal:2', 'unit_price' => 'decimal:2',
            'percent_off' => 'decimal:3', 'special_percent' => 'decimal:2', 'vat_percent' => 'decimal:2', 'vat_amount' => 'decimal:2', 'is_outside' => 'boolean', 'held_for_loss' => 'boolean', 'removed_at' => 'datetime',
        ];
    }

    /**
     * VAT (spec ch 25): off at launch. When it is switched on in Settings, a new line takes the rate of its medicine, or of its
     * company, and every save works out the VAT inside the line total, because the printed price already includes it.
     */
    protected static function booted(): void
    {
        static::saving(function (InvoiceLine $line) {
            if (! Setting::read('vat.enabled', false)) {
                return;
            }

            if (! $line->exists && (float) $line->vat_percent === 0.0) {
                $medicine = $line->medicine()->with('company:id,vat_percent')->first();
                $line->vat_percent = (float) ($medicine?->vat_percent ?? $medicine?->company?->vat_percent ?? 0);
            }

            $rate = (float) $line->vat_percent;
            $line->vat_amount = $rate > 0 ? round($line->lineTotal() * $rate / (100 + $rate), 2) : 0;
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** The controlled-medicine register entry for this line, when the medicine is a controlled one (spec ch 27). */
    public function controlled(): HasOne
    {
        return $this->hasOne(ControlledSale::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(OfferProduct::class, 'offer_product_id');
    }

    public function lineTotal(): float
    {
        return round((float) $this->unit_price * (float) $this->quantity, 2);
    }
}
