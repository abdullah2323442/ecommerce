<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Services\Market\Marketplace;
use App\Services\Market\MarketCart;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The market cart and its one checkout (spec ch 13): many sellers, one cart, one checkout, split into packages. Open to anyone who may buy in the market. */
class MarketCartController extends Controller
{
    public function __construct(private MarketCart $cart, private Marketplace $market) {}

    public function show(Request $request): Response
    {
        $this->allowed($request);
        $user = $request->user();

        return Inertia::render('market/cart', [
            'cart' => $this->cart->packages($user),
            'districts' => config('bd.districts'),
            'defaults' => ['name' => $user?->name, 'phone' => $user?->phone],
            'signedIn' => $user !== null,
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $this->allowed($request);
        $data = $request->validate([
            'listing_id' => ['required', 'integer', 'exists:listings,id'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        $listing = Listing::findOrFail($data['listing_id']);
        abort_if($this->market->priceFor($listing->load(['sector', 'seller']), $request->user()) === null, 404);

        try {
            $this->cart->add($listing, isset($data['variant_id']) ? (int) $data['variant_id'] : null, (float) $data['quantity'], $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __('Added to your market cart.'));
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $this->allowed($request);
        $data = $request->validate(['quantity' => ['required', 'numeric', 'gt:0', 'max:1000000']]);
        $this->cart->set($key, (float) $data['quantity']);

        return back();
    }

    public function remove(Request $request, string $key): RedirectResponse
    {
        $this->allowed($request);
        $this->cart->remove($key);

        return back();
    }

    public function checkout(Request $request): RedirectResponse
    {
        $this->allowed($request);
        $request->merge(['phone' => $this->normalisePhone((string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'district' => ['required', Rule::in(config('bd.districts'))],
            'area' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:250'],
            'how_to_find' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:300'],
            'use_token' => ['sometimes', 'boolean'],
        ], ['phone.regex' => __('Write a Bangladesh mobile number, like 01712345678.')]);

        try {
            $made = $this->cart->checkout($request->user(), collect($data)->only(['name', 'phone', 'district', 'area', 'address', 'how_to_find'])->all(), $data['note'] ?? null, (bool) ($data['use_token'] ?? false));
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        $online = collect($made['orders'])->contains(fn ($o) => $o->pay_by === 'online');

        return to_route('market.packages', $made['group'])->with('status', $online ? __('Your orders are made. Pay for each one to send it to the seller.') : __('Your orders are sent to the sellers. You pay when each one arrives.'));
    }

    /** The orders of one checkout as packages, by their private group link. */
    public function packages(string $token): Response
    {
        $orders = MarketOrder::where('group_token', $token)->with('listing.seller')->orderBy('id')->get();
        abort_if($orders->isEmpty(), 404);

        return Inertia::render('market/packages', [
            'packages' => $orders->values()->map(fn (MarketOrder $o, int $n) => [
                'n' => $n + 1, 'token' => $o->public_token, 'number' => $o->number(), 'title' => $o->title, 'variant' => $o->variant_label, 'status' => $o->status,
                'seller' => $o->listing ? $this->market->sellerName($o->listing) : (string) config('brand.name'), 'tracking' => $o->tracking, 'preorder' => (bool) $o->preorder, 'preorder_days' => $o->preorder_days,
                'delivery_charge' => (float) $o->delivery_charge, 'total' => (float) $o->total, 'pay_by' => $o->pay_by, 'paid' => $o->paid_at !== null,
            ]),
            'total' => round((float) $orders->sum('total'), 2),
        ]);
    }

    private function allowed(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && ! in_array($user->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }
    }

    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }
}
