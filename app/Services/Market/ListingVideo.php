<?php

namespace App\Services\Market;

use App\Models\Listing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** One short video of a product (spec ch 25). Adding a new one replaces the old; the file goes when the product does. */
class ListingVideo
{
    /** Kilobytes. A phone clip of half a minute fits; longer ones should be on a video site. */
    public const MAX_KB = 20480;

    public function attach(Listing $listing, UploadedFile $file): void
    {
        $old = $listing->video_path;

        $listing->update(['video_path' => $file->store('market-video')]);

        if ($old !== null) {
            Storage::delete($old);
        }
    }

    public function remove(Listing $listing): void
    {
        if ($listing->video_path !== null) {
            Storage::delete($listing->video_path);
            $listing->update(['video_path' => null]);
        }
    }
}
