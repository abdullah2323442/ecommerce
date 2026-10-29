<?php

namespace App\Models;

use App\Services\Pricing\PriceRounding;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Something a seller offers. The seller's price is theirs; what a buyer pays is that price plus our commission (spec ch 14), and
 * the buyer is never told which part is which.
 */
class Listing extends Model
{
    protected $fillable = [
        'seller_id', 'sector_id', 'category_id', 'catalog_product_id', 'title', 'description', 'photo_path', 'video_path', 'public_price', 'wholesale_price', 'min_qty', 'delivery_charge', 'stock', 'status',
        'pickup', 'cod_allowed', 'digital_path', 'digital_name', 'digital_max', 'digital_days', 'cost_price', 'low_stock_at', 'item_weight', 'expires_on', 'warranty_months', 'seo_title', 'seo_description', 'return_days', 'return_note', 'preorder_days', 'alt_names', 'item_height', 'item_length', 'item_width', 'item_size_unit', 'commission_kind', 'commission_value', 'commission_request', 'review_note', 'reviewed_by', 'submitted_at', 'approved_at', 'flag',
    ];

    protected function casts(): array
    {
        return [
            'public_price' => 'decimal:2', 'wholesale_price' => 'decimal:2', 'min_qty' => 'decimal:2', 'delivery_charge' => 'decimal:2', 'stock' => 'decimal:2', 'cost_price' => 'decimal:2', 'pickup' => 'boolean', 'cod_allowed' => 'boolean', 'item_weight' => 'decimal:3', 'expires_on' => 'date', 'low_stock_at' => 'decimal:2',
            'commission_value' => 'decimal:2', 'item_height' => 'decimal:2', 'item_length' => 'decimal:2', 'item_width' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** Other categories this product is also shown in. There is still only one stock (spec ch 15). */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function extraSectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class, 'listing_sectors');
    }

    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ListingPhoto::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ListingVariant::class);
    }

    public function sale(): HasOne
    {
        return $this->hasOne(ListingSale::class);
    }

    /** The flash sale that is on right now, if any. */
    public function liveSale(): ?ListingSale
    {
        $sale = $this->sale;

        return $sale && $sale->isLive() ? $sale : null;
    }

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_items');
    }

    /** What anyone may see: approved, and the seller is not away. */
    public function scopeShown(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereHas('seller', fn ($s) => $s->whereNull('suspended_at')->whereNull('seller_suspended_at')->whereNull('penalty_suspended_at'));
    }

    /** The price a buyer pays for one unit: the seller's price with our commission on top. */
    public function withCommission(float $price): float
    {
        $value = (float) $this->commission_value;

        return PriceRounding::round($this->commission_kind === 'percent' ? $price * (1 + $value / 100) : $price + $value);
    }

    /** Our commission on one unit at this seller price. */
    public function commissionOn(float $price): float
    {
        return PriceRounding::round($this->withCommission($price) - $price);
    }
}
