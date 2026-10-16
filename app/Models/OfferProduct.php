<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferProduct extends Model
{
    protected $fillable = [
        'medicine_id', 'percent', 'lot_percent', 'min_boxes', 'step_percent', 'viewer_percent', 'visible_to', 'stock_mode', 'weight_kg', 'delivery_charge',
        'ends_on', 'is_on', 'soldout_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2', 'lot_percent' => 'decimal:3', 'step_percent' => 'decimal:2', 'visible_to' => 'array', 'viewer_percent' => 'array', 'ends_on' => 'date',
            'is_on' => 'boolean', 'soldout_at' => 'datetime', 'weight_kg' => 'decimal:2', 'delivery_charge' => 'decimal:2',
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
