<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\Payment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\Payments;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Paying for an order online. The buyer starts it from their order page (the private link is the proof of whose order it is). The gateway sends the
 * buyer back, and also posts a message of its own; both only ask us to CHECK the payment, and the check is made with the gateway, never believed as sent.
 */
class PaymentController extends Controller
{
    public function __construct(private Payments $payments) {}

    public function start(Request $request, string $token): SymfonyResponse|RedirectResponse
    {
        $order = Invoice::where('public_token', $token)->where('channel', 'online')->firstOrFail();

        try {
            $url = $this->payments->start($order, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        // A full page change: the payment page is on the gateway's own site.
        return Inertia::location($url);
    }

    /** A market order is paid from its private page. */
    public function startMarket(Request $request, string $token): SymfonyResponse|RedirectResponse
    {
        $order = MarketOrder::where('public_token', $token)->firstOrFail();

        try {
            $url = $this->payments->startMarket($order, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['order' => __($e->getMessage())]);
        }

        return Inertia::location($url);
    }

    /** The buyer's browser coming back. Whatever it says, the payment is checked with the gateway before anything is believed. */
    public function result(Request $request, string $provider, string $result): RedirectResponse
    {
        $payment = Payment::where('ref', (string) $request->input('tran_id'))->first();

        // Not ours: the home page, with no hint.
        if (! $payment || $payment->provider !== $provider) {
            return redirect('/');
        }

        $order = $payment->invoice();
        $market = $payment->marketOrder();
        $back = $market?->public_token ? route('market.order', $market->public_token) : ($order?->public_token ? route('orders.show', $order->public_token) : url('/'));

        if ($result === 'success' && $request->filled('val_id')) {
            try {
                $this->payments->confirm($payment, (string) $request->input('val_id'));

                return redirect($back)->with('status', $payment->fresh()->live ? __('Thank you. Your payment is received.') : __('Practice payment received. No real money was taken.'));
            } catch (PaymentException $e) {
                return redirect($back)->withErrors(['order' => __($e->getMessage()).' '.__('If money left your account, it will show here shortly, or call us.')]);
            }
        }

        $this->payments->close($payment, $result === 'cancel' ? 'cancelled' : 'failed');

        return redirect($back)->withErrors(['order' => $result === 'cancel' ? __('You cancelled the payment. You can pay again, or pay on delivery.') : __('The payment did not go through. You can try again, or pay on delivery.')]);
    }

    /** The gateway's own message. It only starts a check, so a forged one can do nothing the gateway does not confirm. */
    public function ipn(Request $request, string $provider): Response
    {
        $payment = Payment::where('ref', (string) $request->input('tran_id'))->where('provider', $provider)->first();

        if ($payment && $request->filled('val_id') && in_array($request->input('status'), ['VALID', 'VALIDATED'], true)) {
            try {
                $this->payments->confirm($payment, (string) $request->input('val_id'));
            } catch (PaymentException) {
                // Not confirmed: nothing changes.
            }
        }

        return response('OK', 200);
    }
}
