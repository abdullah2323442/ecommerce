<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Support\Collection;

/**
 * Personal recommendations (spec ch 13: what each person likes). The plain, explainable kind: a signed-in buyer who has ordered or saved something in the market
 * is shown other products from the same categories, the same kinds of business and the same sellers, with more recently-ordered products ahead. What they have
 * already bought, their own products and anything sold out are left out. A buyer with no history gets nothing here, and no one else's history is ever used.
 */
class Recommendations
{
    public const LIMIT = 8;

    /**
     * @param  Collection<int, array<string, mixed>>  $rows  what this viewer can see, from Marketplace::visible
     * @return Collection<int, array<string, mixed>> the same rows, best first
     */
    public function for(?User $viewer, Collection $rows, int $limit = self::LIMIT): Collection
    {
        if ($viewer === null || $rows->isEmpty()) {
            return collect();
        }

        $bought = MarketOrder::where('buyer_id', $viewer->id)->where('status', '!=', 'cancelled')->pluck('listing_id')->filter()->unique()->values();
        $saved = WishlistItem::where('user_id', $viewer->id)->pluck('listing_id')->filter()->unique()->values();
        $known = Listing::whereIn('id', $bought->merge($saved)->unique()->all())->get(['id', 'category_id', 'sector_id', 'seller_id']);

        if ($known->isEmpty()) {
            return collect();
        }

        // What they bought counts for more than what they only saved.
        $weight = fn (Listing $l) => $bought->contains($l->id) ? 3 : 1;
        $categories = $known->whereNotNull('category_id')->groupBy('category_id')->map(fn ($g) => $g->sum($weight));
        $sectors = $known->groupBy('sector_id')->map(fn ($g) => $g->sum($weight));
        $sellers = $known->groupBy('seller_id')->map(fn ($g) => $g->sum($weight));
        $recent = MarketOrder::where('status', '!=', 'cancelled')->where('created_at', '>=', now()->subDays(90))->whereIn('listing_id', $rows->pluck('listing.id')->all())->selectRaw('listing_id, count(*) as n')->groupBy('listing_id')->pluck('n', 'listing_id');

        return $rows
            ->reject(fn ($r) => $known->contains('id', $r['listing']->id) && $bought->contains($r['listing']->id))
            ->reject(fn ($r) => $r['listing']->seller_id === $viewer->id)
            ->reject(fn ($r) => $r['listing']->stock !== null && (float) $r['listing']->stock <= 0 && ! $r['listing']->variants()->exists())
            ->map(function ($r) use ($categories, $sectors, $sellers, $recent) {
                /** @var Listing $l */
                $l = $r['listing'];
                $score = 3 * min(6, (int) ($categories[$l->category_id] ?? 0)) + 1 * min(4, (int) ($sectors[$l->sector_id] ?? 0)) + 1 * min(3, (int) ($sellers[$l->seller_id] ?? 0)) + min(2, intdiv((int) ($recent[$l->id] ?? 0), 3));

                return $r + ['pick_score' => $score];
            })
            ->filter(fn ($r) => $r['pick_score'] > 0)
            ->sortBy([['pick_score', 'desc'], [fn ($a, $b) => $b['listing']->approved_at <=> $a['listing']->approved_at]])
            // One card for each catalog product, as the market list shows.
            ->unique(fn ($r) => $r['listing']->catalog_product_id !== null ? 'c'.$r['listing']->catalog_product_id : 'l'.$r['listing']->id)
            ->take($limit)->values();
    }
}
