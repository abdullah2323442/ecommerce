<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Services\Market\Marketplace;
use App\Services\Market\Wishlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The buyer's wishlist of market products (spec ch 13). Signed in only: it belongs to the account. */
class WishlistController extends Controller
{
    public function __construct(private Wishlist $wishlist, private Marketplace $market) {}

    public function index(Request $request): Response
    {
        return Inertia::render('market/wishlist', [
            'items' => collect($this->wishlist->items($request->user()))->map(fn (array $r) => [
                'id' => $r['listing']->id, 'title' => $r['listing']->title, 'price' => $r['price'], 'in_stock' => $r['in_stock'],
                'unit' => $r['listing']->sector->unit, 'seller' => $this->market->sellerName($r['listing']),
                'photo' => $r['listing']->photo_path !== null ? route('market.photo', $r['listing']) : null,
            ])->values(),
        ]);
    }

    public function toggle(Request $request, Listing $listing): RedirectResponse
    {
        $on = $this->wishlist->toggle($request->user(), $listing);

        return back()->with('status', $on ? __('Saved to your wishlist.') : __('Taken off your wishlist.'));
    }
}
