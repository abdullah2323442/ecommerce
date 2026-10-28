<?php

namespace App\Http\Controllers;

use App\Http\Requests\Market\CreateBundleRequest;
use App\Http\Requests\Market\StartSaleRequest;
use App\Models\Bundle;
use App\Models\Listing;
use App\Services\Market\Deals;
use App\Services\Market\Marketplace;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** A seller's flash sales and bundles (spec ch 27 extras): the seller sets the percent and, for a sale, the end time. */
class SellDealsController extends Controller
{
    public function __construct(private Deals $deals, private Marketplace $market) {}

    public function index(Request $request): Response
    {
        abort_unless($this->market->canSell($request->user()), 403);
        $seller = $request->user();

        return Inertia::render('market/deals', [
            'products' => Listing::where('seller_id', $seller->id)->where('status', 'published')->whereNull('digital_path')->whereDoesntHave('variants')->orderBy('title')->get(['id', 'title'])
                ->map(fn ($l) => ['id' => $l->id, 'title' => $l->title])->all(),
            'sellable' => Listing::where('seller_id', $seller->id)->where('status', 'published')->orderBy('title')->get(['id', 'title'])->map(fn ($l) => ['id' => $l->id, 'title' => $l->title])->all(),
            'sales' => $this->deals->salesOf($seller),
            'bundles' => $this->deals->bundlesOf($seller),
            'limits' => ['sale' => [Deals::MIN_SALE, Deals::MAX_SALE], 'bundle' => [Deals::MIN_BUNDLE, Deals::MAX_BUNDLE], 'size' => Deals::BUNDLE_SIZE, 'days' => $this->deals->longestSaleDays()],
        ]);
    }

    public function startSale(StartSaleRequest $request, Listing $listing): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->deals->startSale($request->user(), $listing, (int) $data['percent'], Carbon::parse($data['ends_at']));
        } catch (StockException $e) {
            return back()->withErrors(['sale' => __($e->getMessage())]);
        }

        return back()->with('status', __('The sale is on.'));
    }

    public function endSale(Request $request, Listing $listing): RedirectResponse
    {
        abort_unless($this->market->canSell($request->user()), 403);
        $this->deals->endSale($request->user(), $listing);

        return back()->with('status', __('The sale is over.'));
    }

    public function createBundle(CreateBundleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->deals->createBundle($request->user(), $data['title'], (int) $data['percent'], $data['listing_ids']);
        } catch (StockException $e) {
            return back()->withErrors(['bundle' => __($e->getMessage())]);
        }

        return back()->with('status', __('The bundle is saved.'));
    }

    public function removeBundle(Request $request, Bundle $bundle): RedirectResponse
    {
        abort_unless($this->market->canSell($request->user()), 403);
        $this->deals->removeBundle($request->user(), $bundle);

        return back()->with('status', __('The bundle is removed.'));
    }
}
