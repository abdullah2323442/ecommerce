<?php

namespace App\Services\Market;

use App\Models\Bundle;
use App\Models\Listing;
use App\Models\ListingSale;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Two ways a seller can lower a price for a while (spec ch 27, the extras). A flash sale takes a percent off one product until a set time, and the buyer is shown
 * what it was and when it ends. A bundle is a few of the seller's own products that cost less bought together. Both are the seller's own cost: our commission
 * is worked out on the price after the discount, and nothing changes for a buyer who does not take the deal.
 */
class Deals
{
    public const MIN_SALE = 5;

    public const MAX_SALE = 70;

    public const MIN_BUNDLE = 3;

    public const MAX_BUNDLE = 50;

    public const BUNDLE_SIZE = [2, 5];

    public function __construct(private Marketplace $market, private ActivityLogger $activity) {}

    /** The longest a flash sale may run, in days (a setting). */
    public function longestSaleDays(): int
    {
        return max(1, (int) Setting::read('market.sale_max_days', 14));
    }

    // ------------------------------------------------------------------ flash sales

    /** @throws StockException */
    public function startSale(User $seller, Listing $listing, int $percent, CarbonInterface $endsAt): ListingSale
    {
        $this->ownsPublished($seller, $listing);

        if ($percent < self::MIN_SALE || $percent > self::MAX_SALE) {
            throw new StockException(__('A sale takes between {a} and {b} percent off.', ['a' => self::MIN_SALE, 'b' => self::MAX_SALE]));
        }

        if ($endsAt->lte(now()->addMinutes(5))) {
            throw new StockException('The sale must end later than now.');
        }

        if ($endsAt->gt(now()->addDays($this->longestSaleDays()))) {
            throw new StockException(__('A sale can run for {n} days at most.', ['n' => $this->longestSaleDays()]));
        }

        $sale = ListingSale::updateOrCreate(['listing_id' => $listing->id], ['percent' => $percent, 'starts_at' => now(), 'ends_at' => $endsAt]);
        $this->activity->record('market.sale_started', $listing, ['percent' => $percent, 'ends_at' => $endsAt->toDateTimeString()], $seller);

        return $sale;
    }

    public function endSale(User $seller, Listing $listing): void
    {
        abort_unless($listing->seller_id === $seller->id, 403);

        if (ListingSale::where('listing_id', $listing->id)->delete() > 0) {
            $this->activity->record('market.sale_ended', $listing, [], $seller);
        }
    }

    /**
     * Every sale this seller has that has not ended, for their own page.
     *
     * @return list<array<string, mixed>>
     */
    public function salesOf(User $seller): array
    {
        return ListingSale::whereHas('listing', fn ($q) => $q->where('seller_id', $seller->id))->with('listing:id,title')->where('ends_at', '>', now())->orderBy('ends_at')->get()
            ->map(fn (ListingSale $s) => ['listing_id' => $s->listing_id, 'title' => $s->listing->title, 'percent' => $s->percent, 'ends_at' => $s->ends_at->toIso8601String()])->all();
    }

    // ------------------------------------------------------------------ bundles

    /**
     * @param  list<int>  $listingIds
     *
     * @throws StockException
     */
    public function createBundle(User $seller, string $title, int $percent, array $listingIds): Bundle
    {
        $ids = collect($listingIds)->map(fn ($i) => (int) $i)->unique()->values();

        if ($ids->count() < self::BUNDLE_SIZE[0] || $ids->count() > self::BUNDLE_SIZE[1]) {
            throw new StockException(__('A bundle holds {a} to {b} of your products.', ['a' => self::BUNDLE_SIZE[0], 'b' => self::BUNDLE_SIZE[1]]));
        }

        if ($percent < self::MIN_BUNDLE || $percent > self::MAX_BUNDLE) {
            throw new StockException(__('A bundle takes between {a} and {b} percent off.', ['a' => self::MIN_BUNDLE, 'b' => self::MAX_BUNDLE]));
        }

        $title = trim($title);

        if (mb_strlen($title) < 3) {
            throw new StockException('Give the bundle a name.');
        }

        $listings = Listing::whereIn('id', $ids)->get();

        if ($listings->count() !== $ids->count()) {
            throw new StockException('Pick your own products.');
        }

        foreach ($listings as $l) {
            $this->ownsPublished($seller, $l);

            // A product with colours and sizes, or one that is a download, has no single price to bundle.
            if ($l->variants()->exists() || $l->digital_path !== null) {
                throw new StockException(__('{title} has colours or sizes, or is a download, so it cannot be in a bundle.', ['title' => $l->title]));
            }
        }

        return DB::transaction(function () use ($seller, $title, $percent, $ids) {
            $bundle = Bundle::create(['seller_id' => $seller->id, 'title' => mb_substr($title, 0, 80), 'percent' => $percent, 'is_active' => true]);
            $bundle->listings()->sync($ids->all());
            $this->activity->record('market.bundle_created', $bundle, ['percent' => $percent, 'listings' => $ids->all()], $seller);

            return $bundle;
        });
    }

    public function removeBundle(User $seller, Bundle $bundle): void
    {
        abort_unless($bundle->seller_id === $seller->id, 403);
        $bundle->delete();
        $this->activity->record('market.bundle_removed', $seller, ['title' => $bundle->title], $seller);
    }

    /** @return list<array<string, mixed>> */
    public function bundlesOf(User $seller): array
    {
        return Bundle::where('seller_id', $seller->id)->with('listings:id,title')->latest('id')->get()->map(fn (Bundle $b) => [
            'id' => $b->id, 'title' => $b->title, 'percent' => $b->percent, 'items' => $b->listings->map(fn ($l) => ['id' => $l->id, 'title' => $l->title])->all(),
        ])->all();
    }

    /**
     * The bundles a buyer can take from this product's page: every item is on sale to them now, and what they would save is worked out at today's prices.
     *
     * @return list<array<string, mixed>>
     */
    public function offeredFor(Listing $listing, ?User $viewer): array
    {
        return $listing->bundles()->where('is_active', true)->with(['listings.sector', 'listings.seller', 'listings.sale', 'listings.variants'])->get()->map(function (Bundle $b) use ($viewer) {
            $items = [];
            $total = 0.0;

            foreach ($b->listings as $l) {
                $p = $this->market->priceFor($l, $viewer);

                if ($p === null || $p['audience'] !== 'public' || $l->variants->isNotEmpty() || $l->digital_path !== null || ($viewer && $viewer->id === $l->seller_id)) {
                    return null;
                }

                $items[] = ['id' => $l->id, 'title' => $l->title, 'price' => $p['price']];
                $total += $p['price'];
            }

            $save = round($total * $b->percent / 100, 2);

            return ['id' => $b->id, 'title' => $b->title, 'percent' => $b->percent, 'items' => $items, 'was' => round($total, 2), 'save' => $save, 'now' => round($total - $save, 2)];
        })->filter()->values()->all();
    }

    /**
     * What the cart saves by bundles. A bundle counts once for each full set in the cart (one of each item); a unit is in one bundle only, and the bundle that
     * saves the most is taken first. Returns, for each listing, how many of its units are discounted and the sum of units times percent (a unit's discount is its price times its percent).
     *
     * @param  Collection<int, array{listing: Listing, quantity: float}>  $lines  lines priced for the public, with no colour or size
     * @return array<int, array{units: int, weighted: int, bundle: string}> keyed by listing id
     */
    public function cartDiscounts(Collection $lines): array
    {
        $left = $lines->mapWithKeys(fn ($l) => [$l['listing']->id => (int) floor($l['quantity'])])->all();
        $sellers = $lines->map(fn ($l) => $l['listing']->seller_id)->unique()->values()->all();

        if ($left === []) {
            return [];
        }

        $bundles = Bundle::where('is_active', true)->whereIn('seller_id', $sellers)->with('listings:id')->orderByDesc('percent')->get();
        $out = [];

        foreach ($bundles as $b) {
            $ids = $b->listings->pluck('id')->all();

            if ($ids === [] || array_diff($ids, array_keys($left)) !== []) {
                continue;
            }

            $sets = min(array_map(fn ($id) => $left[$id], $ids));

            if ($sets < 1) {
                continue;
            }

            foreach ($ids as $id) {
                $left[$id] -= $sets;
                $out[$id] = [
                    'units' => ($out[$id]['units'] ?? 0) + $sets, 'weighted' => ($out[$id]['weighted'] ?? 0) + $sets * $b->percent, 'bundle' => $out[$id]['bundle'] ?? $b->title,
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ helpers

    /** @throws StockException */
    private function ownsPublished(User $seller, Listing $listing): void
    {
        abort_unless($listing->seller_id === $seller->id, 403);

        if ($listing->status !== 'published') {
            throw new StockException(__('{title} is not on sale yet. Only products buyers can see can be in a deal.', ['title' => $listing->title]));
        }
    }
}
