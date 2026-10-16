<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Found 50 here, system says 60": a report from staff that only the admin can act on (spec 6.7). */
class StockMismatch extends Model
{
    protected $fillable = ['medicine_id', 'reported_by', 'found', 'system_units', 'note', 'status', 'resolved_by', 'resolved_at'];

    protected function casts(): array
    {
        return ['found' => 'decimal:4', 'system_units' => 'decimal:4', 'resolved_at' => 'datetime'];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
