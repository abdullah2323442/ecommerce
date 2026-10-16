<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\ListingReview;
use App\Models\ListingReviewPhoto;
use App\Models\MarketOrder;
use App\Services\Media\ImageCompressor;
use App\Services\Stock\StockException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Reviews and ratings (spec ch 13). Only a buyer whose order really arrived can review it, once, and it is marked "Verified buyer"; nobody else
 * can, so fake reviews do not pile up. A review never says who wrote it: the seller and other buyers see the stars, the words and the photos.
 */
class Reviews
{
    public const MAX_PHOTOS = 3;

    public function __construct(private ImageCompressor $images) {}

    /** Can this order be reviewed now? It arrived, it is for a product that still exists, and it has no review yet. */
    public function canReview(MarketOrder $order): bool
    {
        return $order->status === 'delivered' && $order->listing_id !== null && ! ListingReview::where('market_order_id', $order->id)->exists();
    }

    /**
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws StockException
     */
    public function submit(MarketOrder $order, int $rating, ?string $body, array $photos = []): ListingReview
    {
        if ($order->status !== 'delivered') {
            throw new StockException('You can review a product after it has arrived.');
        }

        if ($order->listing_id === null) {
            throw new StockException('This product is not on sale any more.');
        }

        if (ListingReview::where('market_order_id', $order->id)->exists()) {
            throw new StockException('You already reviewed this order.');
        }

        if ($rating < 1 || $rating > 5) {
            throw new StockException('Pick from one to five stars.');
        }

        if (count($photos) > self::MAX_PHOTOS) {
            throw new StockException('You can add up to three photos.');
        }

        $body = $body !== null ? trim(preg_replace('/\s+/u', ' ', $body) ?? '') : null;

        // The talk stays on this site: no addresses, no numbers to call.
        if ($body && preg_match('~(https?://|www\.|@[a-z0-9_]+\.|\d[\d\s-]{8,}\d)~iu', $body)) {
            throw new StockException('Please do not write web addresses or phone numbers in a review.');
        }

        $review = ListingReview::create(['listing_id' => $order->listing_id, 'market_order_id' => $order->id, 'buyer_id' => $order->buyer_id, 'rating' => $rating, 'body' => $body ?: null]);

        foreach ($photos as $photo) {
            ListingReviewPhoto::create(['listing_review_id' => $review->id, 'path' => $this->images->store($photo, 'reviews')]);
        }

        return $review;
    }

    public function hide(ListingReview $review, bool $hidden): ListingReview
    {
        $review->update(['hidden' => $hidden]);

        return $review->fresh();
    }

    /** @return array{average: ?float, count: int, stars: array<int, int>} */
    public function summary(Listing $listing): array
    {
        $rows = ListingReview::where('listing_id', $listing->id)->where('hidden', false)->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');
        $count = (int) $rows->sum();

        return [
            'average' => $count > 0 ? round($rows->map(fn ($n, $r) => $n * $r)->sum() / $count, 1) : null, 'count' => $count,
            'stars' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($s) => [$s => (int) ($rows[$s] ?? 0)])->all(),
        ];
    }

    /**
     * The stars for many products at once, for the list.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{average: float, count: int}>
     */
    public function averages(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ListingReview::whereIn('listing_id', $ids)->where('hidden', false)->selectRaw('listing_id, count(*) as n, avg(rating) as a')->groupBy('listing_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->listing_id => ['average' => round((float) $r->a, 1), 'count' => (int) $r->n]])->all();
    }

    /** What is shown under a product: no names, only "Verified buyer". */
    public function shown(Listing $listing): array
    {
        return ListingReview::where('listing_id', $listing->id)->where('hidden', false)->with('photos')->latest('id')->limit(30)->get()
            ->map(fn (ListingReview $r) => [
                'id' => $r->id, 'rating' => $r->rating, 'body' => $r->body, 'verified' => true, 'at' => $r->created_at->toIso8601String(),
                'photos' => $r->photos->map(fn ($p) => route('market.review-photo', $p))->all(),
            ])->all();
    }

    public function forgetPhotos(ListingReview $review): void
    {
        $review->photos()->pluck('path')->each(fn ($p) => Storage::delete($p));
    }
}
