<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\User;
use App\Services\Media\ImageCompressor;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Colours and sizes of one product in one grid (spec ch 15): colours down the side, sizes across the top, a stock number in each cell,
 * and an optional price of its own. Only a colour with nothing left turns grey; the product is not "out of stock". Choosing a colour changes the photo.
 */
class ListingVariants
{
    private const MAX_CELLS = 200;

    public function __construct(private Marketplace $market, private ImageCompressor $images) {}

    /**
     * Replace the grid with these cells. Cells that stay keep their photo.
     *
     * @param  array<int, array{color?: ?string, size?: ?string, stock?: mixed, price?: mixed}>  $cells
     *
     * @throws StockException
     */
    public function sync(Listing $listing, array $cells): void
    {
        $listing->loadMissing('sector', 'seller');
        if ($cells !== [] && ! $listing->sector->uses('color') && ! $listing->sector->uses('size')) {
            throw new StockException('This kind of business has no colours or sizes.');
        }

        $seen = [];
        $clean = [];

        foreach ($cells as $i => $c) {
            $color = mb_substr(trim((string) ($c['color'] ?? '')), 0, 40);
            $size = mb_substr(trim((string) ($c['size'] ?? '')), 0, 40);

            if ($color === '' && $size === '') {
                throw new StockException('Each cell needs a colour or a size.');
            }

            $key = mb_strtolower($color.'|'.$size);
            if (isset($seen[$key])) {
                throw new StockException('The same colour and size is in the grid twice.');
            }
            $seen[$key] = true;

            $stock = ($c['stock'] ?? '') === '' || ($c['stock'] ?? null) === null ? null : (float) $c['stock'];
            $price = ($c['price'] ?? '') === '' || ($c['price'] ?? null) === null ? null : (float) $c['price'];

            if (($stock !== null && ($stock < 0 || $stock > 100000000)) || ($price !== null && ($price <= 0 || $price > 100000000))) {
                throw new StockException('Check the numbers in the grid.');
            }

            if ($price !== null) {
                $this->market->guardCeiling($listing->seller, $price);
            }

            if ($stock !== null && ! $listing->sector->allow_fraction && floor($stock) != $stock) {
                throw new StockException('This kind of product is sold in whole units.');
            }

            $clean[] = ['color' => $color, 'size' => $size, 'stock' => $stock, 'public_price' => $price, 'position' => $i];
        }

        if (count($clean) > self::MAX_CELLS) {
            throw new StockException('A grid can have at most 200 cells.');
        }

        $existing = $listing->variants()->get()->keyBy(fn (ListingVariant $v) => mb_strtolower($v->color.'|'.$v->size));
        $keep = [];

        foreach ($clean as $row) {
            $key = mb_strtolower($row['color'].'|'.$row['size']);
            if ($v = $existing->get($key)) {
                $v->update($row);
                $keep[] = $v->id;
            } else {
                $keep[] = $listing->variants()->create($row)->id;
            }
        }

        $listing->variants()->whereNotIn('id', $keep)->get()->each(function (ListingVariant $v) {
            // A colour's photo lives on its cells; another cell of the same colour may still carry it.
            $v->delete();
        });
    }

    /** One photo for a colour: choosing the colour shows it. */
    public function setColorPhoto(Listing $listing, string $color, UploadedFile $photo): void
    {
        $cells = $listing->variants()->where('color', $color)->get();

        if ($cells->isEmpty()) {
            throw new StockException('There is no such colour in the grid.');
        }

        $path = $this->images->store($photo, 'market');
        $old = $cells->pluck('photo_path')->filter()->unique();
        $listing->variants()->where('color', $color)->update(['photo_path' => $path]);
        $old->each(fn ($p) => $this->forget($p));
    }

    public function forgetPhotos(Listing $listing): void
    {
        $listing->variants()->whereNotNull('photo_path')->pluck('photo_path')->unique()->each(fn ($p) => $this->forget($p));
    }

    /** Is there something to buy? With a grid, one colour and size left is enough. */
    public function anyAvailable(Listing $listing): bool
    {
        return $listing->variants()->get()->contains(fn (ListingVariant $v) => $v->isAvailable());
    }

    /** What the seller edits. */
    public function forSeller(Listing $listing): array
    {
        return $listing->variants()->orderBy('position')->orderBy('id')->get()->map(fn (ListingVariant $v) => [
            'color' => $v->color, 'size' => $v->size, 'stock' => $v->stock !== null ? (float) $v->stock : null, 'price' => $v->public_price !== null ? (float) $v->public_price : null, 'has_photo' => $v->photo_path !== null,
        ])->all();
    }

    /**
     * What the buyer sees: colours, sizes, each cell with its price, and which colours are sold out. No stock numbers (spec ch 05).
     *
     * @return array{colors: array<int, array{name: string, sold_out: bool, photo: ?string}>, sizes: array<int, string>, cells: array<int, array<string, mixed>>}|null
     */
    public function forBuyer(Listing $listing, ?User $viewer): ?array
    {
        $cells = $listing->variants()->orderBy('position')->orderBy('id')->get();

        if ($cells->isEmpty()) {
            return null;
        }

        $listing->loadMissing(['sector', 'seller']);
        $rows = $cells->map(function (ListingVariant $v) use ($listing, $viewer) {
            $p = $this->market->priceFor($listing, $viewer, $v);

            return $p === null ? null : ['id' => $v->id, 'color' => $v->color, 'size' => $v->size, 'price' => $p['price'], 'available' => $v->isAvailable()];
        })->filter()->values();

        $colors = $rows->pluck('color')->unique()->values()->map(function (string $name) use ($cells, $rows) {
            $photo = $cells->first(fn (ListingVariant $v) => $v->color === $name && $v->photo_path !== null);

            return ['name' => $name, 'sold_out' => ! $rows->contains(fn ($r) => $r['color'] === $name && $r['available']), 'photo' => $photo ? route('market.variant-photo', $photo) : null];
        })->all();

        return ['colors' => $colors, 'sizes' => $rows->pluck('size')->unique()->values()->all(), 'cells' => $rows->all()];
    }

    private function forget(?string $path): void
    {
        if ($path !== null && Storage::exists($path)) {
            Storage::delete($path);
        }
    }
}
