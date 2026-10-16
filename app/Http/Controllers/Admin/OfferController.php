<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Medicine;
use App\Models\OfferProduct;
use App\Models\Setting;
use App\Models\TierOffer;
use App\Services\Audit\ActivityLogger;
use App\Services\Sales\OfferProducts;
use App\Services\Sales\TierOffers;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Tier offers: admin only. Nothing goes on offer unless the admin says so (spec 9.1, 9.2). */
class OfferController extends Controller
{
    public function __construct(private TierOffers $offers, private OfferProducts $products, private StockService $stock, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-offers');

        return Inertia::render('admin/offers', [
            'suggestions' => $this->offers->suggestions(),
            'running' => TierOffer::with('medicine:id,brand_name,strength')->where('is_on', true)->whereDate('ends_on', '>=', today())->latest('id')->get()
                ->map(fn (TierOffer $o) => [
                    'id' => $o->id, 'name' => $o->medicine->brand_name, 'strength' => $o->medicine->strength, 'percent' => (float) $o->percent, 'ends_on' => $o->ends_on->toDateString(),
                    'live' => $this->offers->liveFor($o->medicine) !== null,
                ]),
            'asking' => $this->offers->awaitingDecision()->map(fn (TierOffer $o) => ['id' => $o->id, 'name' => $o->medicine->brand_name, 'strength' => $o->medicine->strength, 'percent' => (float) $o->percent, 'ended_on' => $o->ends_on->toDateString()]),
            'margin' => (float) Setting::read('offers.min_margin', 3),
            'products' => OfferProduct::with('medicine:id,brand_name,strength,mrp_box')->where('is_on', true)->whereDate('ends_on', '>=', today())->latest('id')->get()
                ->map(fn (OfferProduct $o) => [
                    'id' => $o->id, 'name' => $o->medicine->brand_name, 'strength' => $o->medicine->strength, 'percent' => (float) $o->percent, 'min_boxes' => $o->min_boxes,
                    'step_percent' => (float) $o->step_percent, 'visible_to' => $o->visible_to, 'stock_mode' => $o->stock_mode, 'boxes' => $this->products->available($o), 'state' => $this->products->state($o),
                ]),
            'low' => $this->products->lowOnes()->map(fn (array $r) => ['id' => $r['offer']->id, 'name' => $r['offer']->medicine->brand_name, 'left' => $r['left']]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-offers');
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'percent' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        try {
            $offer = $this->offers->start(Medicine::findOrFail($data['medicine_id']), (float) $data['percent'], $request->user(), isset($data['ends_on']) ? Carbon::parse($data['ends_on']) : null);
        } catch (StockException $e) {
            return back()->withErrors(['offer' => __($e->getMessage())]);
        }

        $this->activity->record('offer.started', $offer->medicine, ['percent' => (string) $offer->percent, 'ends_on' => $offer->ends_on->toDateString()], $request->user());

        return back()->with('status', __('Offer started. Every shop gets this rate now.'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $this->authorize('manage-offers');
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'percent' => ['required', 'numeric', 'gt:0', 'lt:100'],
            'min_boxes' => ['required', 'integer', 'min:1', 'max:1000'],
            'step_percent' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'viewer_percent' => ['nullable', 'array'],
            'viewer_percent.*' => ['nullable', 'numeric', 'gt:0', 'lt:100'],
            'visible_to' => ['required', 'array', 'min:1'],
            'visible_to.*' => ['in:paikari,public,wholesale'],
            'stock_mode' => ['required', 'in:in_stock,exact'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        try {
            $offer = $this->products->start(Medicine::findOrFail($data['medicine_id']), $data, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['offer' => __($e->getMessage())]);
        }

        $this->activity->record('offer_product.started', $offer->medicine, ['percent' => (string) $offer->percent, 'min_boxes' => $offer->min_boxes], $request->user());

        return back()->with('status', __('Offer product started.'));
    }

    public function turnOffProduct(Request $request, OfferProduct $offer): RedirectResponse
    {
        $this->authorize('manage-offers');
        $offer->update(['is_on' => false]);
        $this->activity->record('offer_product.off', $offer->medicine, [], $request->user());

        return back()->with('status', __('Offer switched off.'));
    }

    public function turnOff(Request $request, TierOffer $offer): RedirectResponse
    {
        $this->authorize('manage-offers');
        $this->offers->turnOff($offer);
        $this->activity->record('offer.off', $offer->medicine, [], $request->user());

        return back()->with('status', __('Offer switched off.'));
    }

    public function renew(Request $request, TierOffer $offer): RedirectResponse
    {
        $this->authorize('manage-offers');

        try {
            $this->offers->renew($offer, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['offer' => __($e->getMessage())]);
        }

        return back()->with('status', __('Offer started. Every shop gets this rate now.'));
    }

    public function leave(Request $request, TierOffer $offer): RedirectResponse
    {
        $this->authorize('manage-offers');
        $this->offers->leaveOff($offer);

        return back();
    }
}
