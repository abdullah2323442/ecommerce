<?php

namespace App\Http\Controllers;

use App\Models\MpoBid;
use App\Models\MpoListing;
use App\Services\Mpo\MpoBidding;
use App\Services\Mpo\MpoService;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A buyer orders from an "Anonymous N" offer (spec ch 10). Wholesale, shop and plain accounts each see and pay their own market's price. */
class MpoOfferController extends Controller
{
    public function __construct(private MpoService $mpos) {}

    /** A bulk buyer offers their own price, as a percentage off the printed price. */
    public function bid(Request $request, MpoListing $listing): RedirectResponse
    {
        $data = $request->validate(['percent' => ['required', 'numeric', 'gt:0', 'max:90'], 'boxes' => ['required', 'integer', 'min:1', 'max:100000']]);

        try {
            app(MpoBidding::class)->bid($request->user(), $listing->loadMissing('mpo', 'medicine'), (float) $data['percent'], (int) $data['boxes']);
        } catch (StockException $e) {
            return back()->withErrors(['offer' => __($e->getMessage())]);
        }

        return back()->with('status', __('Your offer was sent. We will tell you if it is taken.'));
    }

    public function withdraw(Request $request, MpoBid $bid): RedirectResponse
    {
        if ($bid->bidder_id !== $request->user()->id) {
            throw new AuthorizationException;
        }
        app(MpoBidding::class)->withdraw($bid);

        return back()->with('status', __('Offer withdrawn.'));
    }

    public function order(Request $request, MpoListing $listing): RedirectResponse
    {
        if ($this->mpos->marketOf($request->user()) === null) {
            throw new AuthorizationException;
        }

        $data = $request->validate(['boxes' => ['required', 'integer', 'min:1', 'max:100000']]);

        try {
            $pre = $this->mpos->order($request->user(), $listing->loadMissing('mpo', 'medicine'), (int) $data['boxes']);
        } catch (StockException $e) {
            return back()->withErrors(['offer' => __($e->getMessage())]);
        }

        return redirect()->route('preorders.show', $pre)->with('status', __('Ordered. We will tell you when it arrives.'));
    }
}
