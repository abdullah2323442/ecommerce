<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Services\Media\ImageCompressor;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** More than one picture of a product (spec ch 13). The first is the listing's own; up to five more can be added, and the seller picks the main one. */
class ListingPhotos
{
    public const MAX_EXTRA = 5;

    public function __construct(private ImageCompressor $images) {}

    /** @throws StockException */
    public function add(Listing $listing, UploadedFile $file): ListingPhoto
    {
        if ($listing->photos()->count() >= self::MAX_EXTRA) {
            throw new StockException('A product can have up to six photos.');
        }

        return $listing->photos()->create(['path' => $this->images->store($file, 'market'), 'position' => (int) $listing->photos()->max('position') + 1]);
    }

    public function remove(ListingPhoto $photo): void
    {
        Storage::delete($photo->path);
        $photo->delete();
    }

    /** Make this one the main picture: it swaps places with the listing's own, so nothing is lost. */
    public function makeMain(ListingPhoto $photo): void
    {
        $listing = $photo->listing;
        $old = $listing->photo_path;

        $listing->update(['photo_path' => $photo->path]);
        $old !== null ? $photo->update(['path' => $old]) : $photo->delete();
    }

    public function forgetAll(Listing $listing): void
    {
        $listing->photos()->pluck('path')->each(fn ($p) => Storage::delete($p));
    }

    /** The pictures a buyer sees, the main one first. @return array<int, array{url: string}> */
    public function gallery(Listing $listing): array
    {
        $out = $listing->photo_path !== null ? [['url' => route('market.photo', $listing)]] : [];

        foreach ($listing->photos()->orderBy('position')->get() as $p) {
            $out[] = ['url' => route('market.extra-photo', $p)];
        }

        return $out;
    }

    /** What the seller edits. */
    public function forSeller(Listing $listing): array
    {
        return $listing->photos()->orderBy('position')->get()->map(fn (ListingPhoto $p) => ['id' => $p->id, 'url' => route('market.extra-photo', $p)])->all();
    }
}
