<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountLine extends Model
{
    protected $fillable = ['stock_count_id', 'medicine_id', 'system_units', 'counted_units'];

    protected function casts(): array
    {
        return ['system_units' => 'decimal:4', 'counted_units' => 'decimal:4'];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
