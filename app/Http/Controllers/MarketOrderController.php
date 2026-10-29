<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Listing;
use App\Models\MarketCase;
use App\Models\MarketOrder;
use App\Services\Market\DigitalGoods;
use App\Services\Market\MarketCases;
use App\Services\Market\MarketOrders;
use App\Services\Market\Marketplace;
use App\Services\Market\Reviews;
use App\Services\Payments\Payments;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A buyer orders from a seller's listing and follows it from a private link (spec ch 14). The buyer pays the seller directly on delivery;
 * the seller's name is never given unless the owner switched it on, and the seller never learns the buyer's name.
 */
class MarketOrderController extends Controller
{
    public function __construct(private MarketOrders $orders, private Marketplace $market, private MarketCases $cases) {}

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();
        if ($user !== null && ! in_array($user->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }

        $request->merge(['phone' => $this->normalisePhone((string) $request->input('phone'))]);

        // A file to download needs no address: nothing is sent.
        $where = $listing->digital_path !== null ? 'nullable' : 'required_unless:pickup,1,true';
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'pickup' => ['sometimes', 'boolean'],
            'district' => [$where, 'nullable', Rule::in(config('bd.districts'))],
            'area' => [$where, 'nullable', 'string', 'max:80'],
            'address' => [$where, 'nullable', 'string', 'max:250'],
            'how_to_find' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:300'],
            'use_token' => ['sometimes', 'boolean'],
            'variant_id' => ['nullable', 'integer'],
        ], ['phone.regex' => __('Write a Bangladesh mobile number, like 01712345678.')]);

        // A product that is not for this viewer does not exist for them (spec ch 05).
        abort_if($this->market->priceFor($listing->load(['sector', 'seller']), $user) === null, 404);

        try {
            $order = $this->orders->place($listing, $user, collect($data)->only($listing->digital_path !== null ? ['name', 'phone'] : ['name', 'phone', 'district', 'area', 'address', 'how_to_find'])->all(), (float) $data['quantity'], $data['note'] ?? null, (bool) ($data['use_token'] ?? false), null, isset($data['variant_id']) ? (int) $data['variant_id'] : null, (bool) ($data['pickup'] ?? false));
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return to_route('market.order', $order->public_token)->with('status', $order->pay_by === 'online' ? __('Your order is made. Pay for it to send it to the seller.') : __('Your order is sent to the seller. You pay when it arrives.'));
    }

    /** The orders of a signed-in buyer. */
    public function index(Request $request): Response
    {
        return Inertia::render('market/orders', [
            'orders' => MarketOrder::where('buyer_id', $request->user()->id)->latest('id')->limit(50)->get()
                ->map(fn (MarketOrder $o) => ['token' => $o->public_token, 'number' => $o->number(), 'title' => $o->title, 'status' => $o->status, 'total' => (float) $o->total, 'at' => $o->created_at->toIso8601String()]),
        ]);
    }

    public function show(Request $request, string $token): Response
    {
        $order = $this->find($token)->load(['seller', 'listing']);
        $ship = (array) $order->ship_to;

        return Inertia::render('market/order', [
            'pay' => app(Payments::class)->forMarketOrder($order),
            'digital' => app(DigitalGoods::class)->panel($order),
            'token' => $order->public_token,
            'order' => [
                'number' => $order->number(), 'title' => $order->title, 'variant' => $order->variant_label, 'unit' => $order->unit, 'quantity' => (float) $order->quantity, 'unit_price' => (float) $order->unit_price,
                'delivery_charge' => (float) $order->delivery_charge, 'token_off' => (float) $order->token_amount, 'total' => (float) $order->total, 'status' => $order->status, 'preorder' => (bool) $order->preorder, 'preorder_days' => $order->preorder_days, 'tracking' => $order->tracking,
                'placed_at' => $order->created_at->toIso8601String(), 'cancel_reason' => $order->cancel_reason,
                'seller' => $order->listing ? $this->market->sellerName($order->listing) : (string) config('brand.name'),
                'ship_to' => ['name' => $ship['name'] ?? null, 'phone' => $ship['phone'] ?? null, 'address' => trim(($ship['address'] ?? '').', '.($ship['area'] ?? '').', '.($ship['district'] ?? ''))],
                'can_cancel' => in_array($order->status, ['placed', 'accepted'], true),
                'pickup' => (bool) $order->pickup, 'pickup_where' => $order->pickup && in_array($order->status, ['accepted'], true) ? $order->seller?->shop_address : null,
                'can_review' => app(Reviews::class)->canReview($order),
                'can_answer' => $order->status === 'shipped',
                'can_complain' => $this->cases->allowed($order)['complaint'], 'can_return' => $this->cases->allowed($order)['return'], 'can_report_promo' => $this->cases->allowed($order)['promo'], 'return_days' => $this->cases->returnDays($order), 'return_note' => $order->listing?->return_note,
            ],
            'cases' => $order->cases()->latest('id')->get()->map(fn (MarketCase $c) => $this->cases->present($c)),
        ]);
    }

    /** A review of an order that arrived (spec ch 13): stars, words and up to three photos. The link in the buyer's hand is the proof. */
    public function review(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:1000'], 'photos' => ['nullable', 'array', 'max:3'], 'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        return $this->act(fn () => app(Reviews::class)->submit($this->find($token), (int) $data['rating'], $data['body'] ?? null, $request->file('photos', [])), 'Thank you for your review.');
    }

    /** The file, through the buyer's private link, counted and limited (spec ch 13). */
    public function download(string $token): SymfonyResponse|RedirectResponse
    {
        try {
            return app(DigitalGoods::class)->download($this->find($token)->load('listing'));
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        return $this->act(fn () => $this->orders->cancel($this->find($token), 'buyer', $request->input('reason')), 'Order cancelled.');
    }

    public function received(string $token): RedirectResponse
    {
        return $this->act(fn () => $this->orders->confirm($this->find($token)), 'Thank you. Glad it arrived.');
    }

    public function notReceived(string $token): RedirectResponse
    {
        return $this->act(fn () => $this->orders->notReceived($this->find($token)), 'We have noted that it did not arrive. We will look into it.');
    }

    /** A complaint, or sending it back (spec ch 14). The seller is told, and we get a copy. */
    public function openCase(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:complaint,return,promo'], 'reason' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->act(fn () => $this->cases->open($this->find($token), $data['kind'], $data['reason']), 'Sent. The seller and we have been told.');
    }

    public function escalateCase(string $token, MarketCase $case): RedirectResponse
    {
        $order = $this->find($token);
        abort_unless($case->market_order_id === $order->id, 404);

        return $this->act(fn () => $this->cases->escalate($case), 'We have it now. We will look into it.');
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    private function find(string $token): MarketOrder
    {
        return MarketOrder::where('public_token', $token)->firstOrFail();
    }

    private function normalisePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }
}
