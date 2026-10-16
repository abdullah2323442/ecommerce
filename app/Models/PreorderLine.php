<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreorderLine extends Model
{
    protected $fillable = ['preorder_id', 'medicine_id', 'unit', 'quantity', 'received_quantity', 'share_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'received_quantity' => 'decimal:2'];
    }

    public function preorder(): BelongsTo
    {
        return $this->belongsTo(Preorder::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
