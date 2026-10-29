<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Address;
use App\Models\Setting;
use App\Services\Analytics\Behavior;
use App\Services\Location\Locator;
use App\Services\Sales\DeliveryCharge;
use App\Services\Sales\OrderNotes;
use App\Services\Sales\OrderNotice;
use App\Services\Sales\PublicCart;
use App\Services\Sales\PublicOrders;
use App\Services\Stock\StockException;
use App\Services\Wallet\Tokens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkout for a visitor or a public buyer (spec ch 13). No account is needed, but name, phone and address are. The
 * money is taken when the order arrives. A signed-in buyer can keep addresses for next time.
 */
class CheckoutController extends Controller
{
    public function __construct(private PublicCart $cart, private DeliveryCharge $delivery, private PublicOrders $orders, private OrderNotice $notice, private Behavior $behavior) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $this->allowed($request);
        $lines = $this->cart->lines();

        if ($lines->isEmpty()) {
            return redirect()->route('cart.show');
        }

        $user = $request->user();

        return Inertia::render('checkout', [
            'lines' => $lines->map(fn (array $l) => [
                'key' => $l['key'], 'name' => $l['medicine']->brand_name, 'strength' => $l['medicine']->strength, 'unit' => $l['unit'], 'quantity' => $l['quantity'], 'total' => $l['total'],
            ])->values(),
            'subtotal' => round((float) $lines->sum('total'), 2),
            'districts' => config('bd.districts'),
            'locate' => app(Locator::class)->enabled(),
            'advance_over' => (float) Setting::read('delivery.advance_over', 0),
            'delivery' => [
                'district' => $this->delivery->shopDistrict(), 'pickup' => $this->delivery->pickupAvailable(), 'address' => config('brand.address'),
            ],
            'quote' => $this->quoteFor($this->delivery->shopDistrict(), null, 'delivery'),
            'addresses' => $user ? Address::where('user_id', $user->id)->latest('id')->get(['id', 'label', 'name', 'phone', 'district', 'area', 'address', 'how_to_find', 'lat', 'lng']) : [],
            'defaults' => ['name' => $user?->name, 'phone' => $user?->phone],
            'notice' => $this->notice->at(),
            'signedIn' => $user !== null,
            // A token the buyer may use on this order (spec ch 24): the biggest one, if they have any.
            'token' => $user && ($t = app(Tokens::class)->best($user)) ? ['amount' => (float) $t->amount] : null,
        ]);
    }

    public function place(Request $request): RedirectResponse
    {
        $this->allowed($request);

        // The phone is written many ways; keep one: 01XXXXXXXXX.
        $request->merge(['phone' => $this->normalisePhone((string) $request->input('phone'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'regex:/^01[3-9]\d{8}$/'],
            'district' => ['required', Rule::in(config('bd.districts'))],
            'area' => ['required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:250'],
            'how_to_find' => ['nullable', 'string', 'max:200'],
            'lat' => ['nullable', 'numeric', 'between:20.5,26.7'], 'lng' => ['nullable', 'numeric', 'between:88,92.8'],
            'for_whom' => ['nullable', 'string', 'max:120'],
            'delivery_mode' => ['required', 'in:delivery,collect'],
            'note' => ['nullable', 'string', 'max:300'],
            'save_address' => ['sometimes', 'boolean'],
            'use_token' => ['sometimes', 'boolean'],
            'courier_id' => ['nullable', 'integer'],
            'voice' => OrderNotes::RULES['voice'], 'photo' => OrderNotes::RULES['photo'], 'wanted_time' => OrderNotes::RULES['wanted_time'],
        ], ['phone.regex' => __('Write a Bangladesh mobile number, like 01712345678.')]);

        $ship = collect($data)->only(['name', 'phone', 'district', 'area', 'address', 'how_to_find', 'for_whom'])->all();

        if (isset($data['lat'], $data['lng'])) {
            $ship['lat'] = round((float) $data['lat'], 6);
            $ship['lng'] = round((float) $data['lng'], 6);
        }

        try {
            $order = $this->orders->place($ship, $data['delivery_mode'], $data['note'] ?? null, $request->user(), $data['courier_id'] ?? null, ['voice' => $request->file('voice'), 'photo' => $request->file('photo'), 'wanted_time' => $data['wanted_time'] ?? null, 'use_token' => (bool) ($data['use_token'] ?? false)]);
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        $this->behavior->record('checkout', null, null, ['order' => $order->id, 'lines' => $order->lines()->count(), 'mode' => $data['delivery_mode']]);

        if ($request->user() && ($data['save_address'] ?? false)) {
            Address::firstOrCreate(
                ['user_id' => $request->user()->id, 'phone' => $ship['phone'], 'district' => $ship['district'], 'area' => $ship['area'], 'address' => $ship['address']],
                ['name' => $ship['name'], 'how_to_find' => $ship['how_to_find'] ?? null, 'lat' => $ship['lat'] ?? null, 'lng' => $ship['lng'] ?? null],
            );
        }

        $message = match ($this->notice->at()) {
            'holiday' => 'Your order is received. It will be processed on the next working day.',
            'night' => 'Your order is received. We will pack it tonight and it will be ready in the morning.',
            default => 'Your order is received. We are checking it now.',
        };

        return redirect()->route('orders.show', $order->public_token)->with('status', __($message));
    }

    /** What sending this cart would cost to the address typed so far, and by whom. The page asks again when the address or the way of getting it changes. */
    public function quote(Request $request): JsonResponse
    {
        $this->allowed($request);
        $district = (string) $request->query('district', '');
        $mode = $request->query('mode') === 'collect' ? 'collect' : 'delivery';

        return response()->json($this->quoteFor(in_array($district, config('bd.districts'), true) ? $district : $this->delivery->shopDistrict(), (string) $request->query('area', ''), $mode));
    }

    private function quoteFor(string $district, ?string $area, string $mode): array
    {
        $lines = $this->cart->lines();

        return $this->delivery->quote($lines->map(fn ($l) => ['medicine' => $l['medicine'], 'unit' => $l['unit'], 'quantity' => $l['quantity']]), $mode, $district, $area, round((float) $lines->sum('total'), 2));
    }

    public function forgetAddress(Request $request, Address $address): RedirectResponse
    {
        if ($request->user() === null || $address->user_id !== $request->user()->id) {
            throw new AuthorizationException;
        }
        $address->delete();

        return back();
    }

    private function allowed(Request $request): void
    {
        if ($request->user() !== null && $request->user()->account_type !== AccountType::Public) {
            throw new AuthorizationException;
        }
    }

    private function normalisePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;
    }
}
