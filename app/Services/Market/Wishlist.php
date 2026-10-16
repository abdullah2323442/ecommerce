<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\Messages\Messenger;

/**
 * A buyer's wishlist of market products (spec ch 13). A product that is no longer for this buyer to see (switched off, a section they may not
 * enter) simply does not show, and nothing says why.
 */
class Wishlist
{
    public const LIMIT = 200;

    public function __construct(private Marketplace $market) {}

    public function has(User $user, Listing $listing): bool
    {
        return WishlistItem::where('user_id', $user->id)->where('listing_id', $listing->id)->exists();
    }

    /** Add it, or take it off when it is already there. Returns whether it is on the list now. */
    public function toggle(User $user, Listing $listing): bool
    {
        $row = WishlistItem::where('user_id', $user->id)->where('listing_id', $listing->id)->first();

        if ($row) {
            $row->delete();

            return false;
        }

        abort_if($this->market->priceFor($listing->loadMissing('sector', 'seller'), $user) === null, 404);

        if (WishlistItem::where('user_id', $user->id)->count() >= self::LIMIT) {
            WishlistItem::where('user_id', $user->id)->oldest()->first()?->delete();
        }

        WishlistItem::create(['user_id' => $user->id, 'listing_id' => $listing->id, 'price_seen' => $this->market->priceFor($listing, $user)['price'] ?? null]);

        return true;
    }

    /**
     * Price-drop alert (spec ch 13, Phase 2): for every saved product whose price for this buyer fell below the price they last saw, tell them once.
     * A rise only moves the remembered price up, so a later fall from the higher price is told too.
     *
     * @return int how many buyers were told
     */
    public function dropAlerts(): int
    {
        $told = 0;

        WishlistItem::whereNotNull('price_seen')->with('listing.sector', 'listing.seller')->orderBy('id')->chunkById(200, function ($rows) use (&$told) {
            foreach ($rows as $w) {
                $user = User::find($w->user_id);
                $p = $w->listing && $user ? $this->market->priceFor($w->listing, $user) : null;

                if ($p === null) {
                    continue;
                }

                $now = round((float) $p['price'], 2);
                $was = (float) $w->price_seen;

                if ($now < $was - 0.001) {
                    app(Messenger::class)->send($user, 'wishlist_drop', ['name' => $w->listing->title, 'now' => number_format($now, 2, '.', ','), 'was' => number_format($was, 2, '.', ',')], '/market/'.$w->listing_id);
                    $told++;
                }

                if (abs($now - $was) > 0.001) {
                    $w->update(['price_seen' => $now]);
                }
            }
        });

        return $told;
    }

    /** @return array<int, array{listing: Listing, price: float, in_stock: bool}> newest first, only what this buyer may still see */
    public function items(User $user): array
    {
        $out = [];

        foreach (WishlistItem::where('user_id', $user->id)->latest('id')->with('listing.sector', 'listing.seller')->get() as $w) {
            $l = $w->listing;
            $p = $l ? $this->market->priceFor($l, $user) : null;

            if ($p !== null) {
                $out[] = ['listing' => $l, 'price' => $p['price'], 'in_stock' => $l->variants()->exists() ? app(ListingVariants::class)->anyAvailable($l) : ($l->stock === null || (float) $l->stock > 0)];
            }
        }

        return $out;
    }
}
