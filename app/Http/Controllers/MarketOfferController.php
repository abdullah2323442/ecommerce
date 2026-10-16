<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\PriceOffer;
use App\Services\Market\Marketplace;
use App\Services\Market\PriceOffers;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "Why didn't you buy", and a price the buyer proposes (spec ch 13). */
class MarketOfferController extends Controller
{
    public function __construct(private PriceOffers $offers, private Marketplace $market) {}

    public function why(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        abort_if($this->market->priceFor($listing->load(['sector', 'seller']), $user) === null, 404);
        $data = $request->validate([
            'reason' => ['required', Rule::in(PriceOffer::REASONS)], 'price' => ['nullable', 'numeric', 'gt:0', 'max:100000000'], 'quantity' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        try {
            $offer = $this->offers->note($listing, $user, $data['reason'], isset($data['price']) ? (float) $data['price'] : null, isset($data['quantity']) ? (float) $data['quantity'] : null);
        } catch (StockException $e) {
            return back()->withErrors(['why' => __($e->getMessage())]);
        }

        return back()->with('status', $offer->status === 'waiting' ? __('Your price is sent to the seller. You will hear within {n} hours.', ['n' => $this->offers->hours()]) : __('Thank you. This helps the seller.'));
    }

    public function index(Request $request): Response
    {
        return Inertia::render('market/offers', ['offers' => $this->offers->forBuyer($request->user()), 'districts' => config('bd.districts')]);
    }

    public function order(Request $request, PriceOffer $offer): RedirectResponse
    {
        $this->mine($request, $offer);
        $request->merge(['phone' => preg_replace('/\D+/', '', (string) $request->input('phone')) ?? '']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'], 'district' => ['required', Rule::in(config('bd.districts'))],
            'area' => ['required', 'string', 'max:80'], 'address' => ['required', 'string', 'max:250'], 'how_to_find' => ['nullable', 'string', 'max:200'], 'note' => ['nullable', 'string', 'max:300'],
        ], ['phone.regex' => __('Write a Bangladesh mobile number, like 01712345678.')]);

        try {
            $order = $this->offers->order($offer->load(['listing.sector', 'listing.seller', 'buyer']), collect($data)->only(['name', 'phone', 'district', 'area', 'address', 'how_to_find'])->all(), $data['note'] ?? null);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return to_route('market.order', $order->public_token)->with('status', __('Your order is sent to the seller. You pay when it arrives.'));
    }

    public function withdraw(Request $request, PriceOffer $offer): RedirectResponse
    {
        $this->mine($request, $offer);

        try {
            $this->offers->withdraw($offer);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Offer withdrawn.'));
    }

    private function mine(Request $request, PriceOffer $offer): void
    {
        $user = $request->user();

        // Not yours is the same as not there; a staff or MPO account is no buyer here.
        if ($offer->buyer_id !== $user->id || ! in_array($user->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }
    }
}
