<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Medicine;
use App\Services\Analytics\Behavior;
use App\Services\Sales\DeliveryCharge;
use App\Services\Sales\PublicCart;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cart of a visitor or a public buyer (spec ch 13). A shop or a bulk buyer orders from their own screen at their own
 * prices, so the public cart is silently not for them.
 */
class CartController extends Controller
{
    public function __construct(private PublicCart $cart, private DeliveryCharge $delivery, private Behavior $behavior) {}

    public function show(Request $request): Response
    {
        $this->allowed($request);
        $lines = $this->cart->lines();

        return Inertia::render('cart', [
            'lines' => $lines->map(fn (array $l) => [
                'key' => $l['key'], 'id' => $l['medicine']->id, 'name' => $l['medicine']->brand_name, 'strength' => $l['medicine']->strength, 'company' => $l['medicine']->company?->name,
                'unit' => $l['unit'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'total' => $l['total'], 'mrp' => $l['mrp'], 'step' => $l['step'],
            ])->values(),
            'subtotal' => round((float) $lines->sum('total'), 2),
            'freeOver' => $this->delivery->freeOver(),
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $this->allowed($request);
        $data = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'unit' => ['required', 'in:piece,strip,box'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.PublicCart::MAX_QUANTITY],
        ]);

        try {
            $this->cart->add(Medicine::findOrFail($data['medicine_id']), $data['unit'], (int) $data['quantity']);
        } catch (StockException $e) {
            return back()->withErrors(['cart' => __($e->getMessage())]);
        }

        $this->behavior->record('cart', (int) $data['medicine_id'], null, ['unit' => $data['unit'], 'quantity' => (int) $data['quantity']]);

        return back()->with('status', __('Added to your cart.'));
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        $this->allowed($request);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.PublicCart::MAX_QUANTITY]]);
        $this->cart->set($key, (int) $data['quantity']);

        return back();
    }

    public function remove(Request $request, string $key): RedirectResponse
    {
        $this->allowed($request);
        $this->cart->remove($key);

        return back();
    }

    private function allowed(Request $request): void
    {
        $user = $request->user();

        if ($user !== null && $user->account_type !== AccountType::Public) {
            throw new AuthorizationException;
        }
    }
}
