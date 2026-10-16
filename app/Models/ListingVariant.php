<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One colour-and-size cell of a product (spec ch 15). */
class ListingVariant extends Model
{
    protected $fillable = ['listing_id', 'color', 'size', 'stock', 'public_price', 'wholesale_price', 'photo_path', 'position'];

    protected function casts(): array
    {
        return ['stock' => 'decimal:2', 'public_price' => 'decimal:2', 'wholesale_price' => 'decimal:2'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function isAvailable(): bool
    {
        return $this->stock === null || (float) $this->stock > 0;
    }

    public function label(): string
    {
        return trim($this->color.' '.$this->size);
    }
}
