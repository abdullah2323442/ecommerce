<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    protected $fillable = ['scope', 'company_id', 'shelf', 'status', 'started_by', 'approved_by', 'started_at', 'approved_at', 'difference_value'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'approved_at' => 'datetime', 'difference_value' => 'decimal:2'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'paused'], true);
    }
}
