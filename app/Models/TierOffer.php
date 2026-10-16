<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TierOffer extends Model
{
    protected $fillable = ['medicine_id', 'percent', 'starts_on', 'ends_on', 'is_on', 'decided_at', 'created_by'];

    protected function casts(): array
    {
        return ['percent' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date', 'is_on' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
