<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Medicine extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'mrp_piece' => 'decimal:2',
            'mrp_strip' => 'decimal:2',
            'mrp_box' => 'decimal:2',
            'prescription_required' => 'boolean',
            'is_cold_chain' => 'boolean',
            'is_controlled' => 'boolean',
            'delivery_fixed_in' => 'decimal:2',
            'delivery_fixed_out' => 'decimal:2',
            'is_active' => 'boolean',
            'reorder_level' => 'decimal:2',
            'mrp_before' => 'decimal:2',
            'mrp_changed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Local image (a root-relative path, so it loads on whatever host the site is opened on) if we have one, otherwise the remote source image, otherwise null. */
    public function getImageUrlAttribute(): ?string
    {
        if ($this->image_path) {
            return '/'.ltrim($this->image_path, '/');
        }

        return $this->image_source_url;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
