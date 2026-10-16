<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlledSale extends Model
{
    protected $fillable = ['invoice_id', 'invoice_line_id', 'medicine_id', 'buyer_name', 'buyer_phone', 'buyer_address', 'prescription_no', 'doctor_name', 'recorded_by'];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_line_id');
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
