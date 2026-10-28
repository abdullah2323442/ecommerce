<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Services\Market\MarketOrders;
use App\Services\Payments\Payments;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The office's view of online payments: what came in, what was only practice, and what must be refunded. */
class PaymentController extends Controller
{
    public function __construct(private Payments $payments) {}

    public function index(Request $request): Response
    {
        $this->authorize('handle-orders');
        $row = fn (Payment $p) => [
            'id' => $p->id, 'ref' => $p->ref, 'amount' => (float) $p->amount, 'status' => $p->status, 'live' => $p->live, 'method' => $p->method, 'order' => $p->payable_type === 'market_order' ? $p->marketOrder()?->number() : ($p->invoice()?->number ?? ($p->payable_id ? 'ORD'.$p->payable_id : null)),
            'order_id' => $p->payable_type === 'market_order' ? null : $p->payable_id, 'market' => $p->payable_type === 'market_order', 'at' => ($p->paid_at ?? $p->created_at)->toIso8601String(), 'risky' => $p->risk_level === 1,
        ];

        return Inertia::render('admin/payments', [
            'refunds' => Payment::where('status', 'refund_due')->latest('id')->get()->map($row),
            'recent' => Payment::whereIn('status', ['paid', 'refunded'])->latest('id')->limit(80)->get()->map($row),
            'failed' => Payment::whereIn('status', ['failed', 'cancelled'])->where('created_at', '>=', now()->subDays(7))->latest('id')->limit(30)->get()->map($row),
            'today' => round((float) Payment::where('status', 'paid')->where('live', true)->where('paid_at', '>=', now('Asia/Dhaka')->startOfDay()->setTimezone(config('app.timezone')))->sum('amount'), 2),
            'enabled' => $this->payments->enabled(),
            'payouts' => app(MarketOrders::class)->payouts(),
        ]);
    }

    /** The office paid a seller what we hold for them. */
    public function paidOut(Request $request, User $seller): RedirectResponse
    {
        $this->authorize('handle-orders');
        $n = app(MarketOrders::class)->markPaidOut($seller);

        return back()->with('status', $n > 0 ? __('Marked as paid out.') : __('Nothing was waiting for this seller.'));
    }

    public function refunded(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('handle-orders');

        try {
            $this->payments->markRefunded($payment, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['payment' => __($e->getMessage())]);
        }

        return back()->with('status', __('Marked as refunded.'));
    }
}
