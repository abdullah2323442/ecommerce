<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only history of stock. Never updated or deleted. */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['medicine_id', 'stock_lot_id', 'invoice_line_id', 'type', 'units', 'unit_cost', 'unit_price', 'is_loss', 'reversed', 'reason', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['units' => 'decimal:4', 'unit_cost' => 'decimal:4', 'unit_price' => 'decimal:2', 'is_loss' => 'boolean', 'reversed' => 'boolean'];
    }
}
