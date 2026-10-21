<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Integrations\Integrations;
use App\Services\Market\DigitalGoods;
use App\Services\Messages\Messenger;
use App\Services\Sales\Deliveries;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Paying for an order online (spec ch 27). The rules that keep money safe:
 *  - an order can be paid only after staff have checked it, and only for what is still owed (the total less anything already paid);
 *  - the amount that goes to the gateway comes from our own books, never from the buyer's browser;
 *  - nothing counts as paid because a browser came back or a message arrived: we ask the gateway ourselves (see PaymentProvider::validate) and the
 *    transaction id, the amount and the currency must all match what we sent;
 *  - the same payment is applied once however many times it is confirmed (the buyer's return and the gateway's message both confirm it);
 *  - the gateway's practice mode never touches an order's money: it is recorded as a practice;
 *  - an order cancelled after it was paid is flagged "refund due" for the office, which refunds it with the gateway and marks it done.
 */
class Payments
{
    public function __construct(private SslcommerzProvider $gateway, private Integrations $integrations, private Deliveries $deliveries, private ActivityLogger $activity) {}

    public function enabled(): bool
    {
        return $this->integrations->active('payment:sslcommerz') !== null;
    }

    /** What is still owed on an order and may be paid now. */
    public function due(Invoice $order): float
    {
        return $this->deliveries->due($order);
    }

    /** Why this order cannot be paid online now, or null when it can. */
    public function whyNot(Invoice $order): ?string
    {
        return match (true) {
            ! $this->enabled() => 'Online payment is not open yet.',
            $order->channel !== 'online' => 'Only an online order can be paid here.',
            ! in_array($order->status, ['verified', 'final'], true) => 'We are still checking your order. You can pay when it is ready.',
            $order->cancelled_at !== null || $order->delivered_at !== null => 'This order is finished.',
            $this->due($order) < 10 => 'Nothing more to pay online.',
            default => null,
        };
    }

    /**
     * Open the payment page. Returns the address to send the buyer to.
     *
     * @throws StockException
     */
    public function start(Invoice $order, ?User $by): string
    {
        if ($why = $this->whyNot($order)) {
            throw new StockException($why);
        }

        $ship = (array) $order->ship_to;

        return $this->open('invoice', $order->id, $by?->id ?? $order->customer_id, $this->due($order), [
            'name' => (string) ($ship['name'] ?? 'Customer'), 'email' => (string) ($order->customer?->email ?? 'customer@'.parse_url((string) config('app.url'), PHP_URL_HOST)), 'phone' => (string) ($ship['phone'] ?? ''),
            'city' => (string) ($ship['district'] ?? 'Dhaka'), 'product' => 'Order '.($order->number ?? 'ORD'.$order->id), 'category' => 'Medicine',
        ]);
    }

    /** Why a market order cannot be paid online now, or null when it can. */
    public function whyNotMarket(MarketOrder $order): ?string
    {
        return match (true) {
            ! $this->enabled() => 'Online payment is not open yet.',
            $order->pay_by !== 'online' => 'Only an online order can be paid here.',
            $order->paid_at !== null => 'Nothing more to pay online.',
            $order->status !== 'placed' => 'This order is finished.',
            (float) $order->total < 10 => 'Nothing more to pay online.',
            default => null,
        };
    }

    /**
     * Open the payment page for a market order. The whole order total is paid: the goods, the delivery and any discount, all from our own books.
     *
     * @throws StockException
     */
    public function startMarket(MarketOrder $order, ?User $by): string
    {
        if ($why = $this->whyNotMarket($order)) {
            throw new StockException($why);
        }

        $ship = (array) $order->ship_to;

        return $this->open('market_order', $order->id, $by?->id ?? $order->buyer_id, (float) $order->total, [
            'name' => (string) ($ship['name'] ?? 'Customer'), 'email' => (string) ($order->buyer?->email ?? 'customer@'.parse_url((string) config('app.url'), PHP_URL_HOST)), 'phone' => (string) ($ship['phone'] ?? ''),
            'city' => (string) ($ship['district'] ?? 'Dhaka'), 'product' => 'Order '.$order->number(), 'category' => 'Marketplace',
        ]);
    }

    /**
     * @param  array<string, string>  $buyer
     *
     * @throws StockException
     */
    private function open(string $type, int $id, ?int $userId, float $amount, array $buyer): string
    {
        $payment = Payment::create([
            'ref' => 'P'.now()->format('ymd').strtoupper(Str::random(10)), 'payable_type' => $type, 'payable_id' => $id, 'user_id' => $userId,
            'provider' => 'sslcommerz', 'amount' => $amount, 'currency' => 'BDT', 'status' => 'started', 'live' => $this->gateway->isLive(),
        ]);

        try {
            return $this->gateway->start($payment, $buyer, [
                'success' => route('payments.result', ['sslcommerz', 'success']), 'fail' => route('payments.result', ['sslcommerz', 'fail']),
                'cancel' => route('payments.result', ['sslcommerz', 'cancel']), 'ipn' => route('payments.ipn', 'sslcommerz'),
            ]);
        } catch (PaymentException $e) {
            $payment->update(['status' => 'failed']);

            throw new StockException($e->getMessage());
        }
    }

    /**
     * Ask the gateway about a payment and, only if all is right, count it. Safe to call again and again.
     *
     * @throws PaymentException
     */
    public function confirm(Payment $payment, string $valId): Payment
    {
        if ($payment->status === 'paid' || $payment->status === 'refunded' || $payment->status === 'refund_due') {
            return $payment;
        }

        $r = $this->gateway->validate($valId);

        if (! $r['valid'] || ! hash_equals($payment->ref, $r['ref']) || abs($r['amount'] - (float) $payment->amount) > 0.001 || strtoupper($r['currency']) !== strtoupper($payment->currency)) {
            throw new PaymentException('The payment could not be confirmed.');
        }

        return DB::transaction(function () use ($payment, $r) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'paid') {
                return $payment;
            }

            $payment->update(['status' => 'paid', 'paid_at' => now(), 'val_id' => $r['val_id'], 'bank_tran_id' => $r['bank_tran_id'], 'method' => $r['method'], 'risk_level' => $r['risk_level']]);
            if ($payment->payable_type === 'market_order') {
                return $this->settleMarket($payment);
            }

            $order = Invoice::whereKey($payment->payable_id)->lockForUpdate()->first();

            if (! $order) {
                return $payment;
            }

            // The practice mode of the gateway never counts as money.
            if (! $payment->live) {
                return $payment;
            }

            // It was paid for an order that was cancelled in the meantime: the money must go back.
            if ($order->cancelled_at !== null) {
                $payment->update(['status' => 'refund_due']);
                $this->tellOffice($payment, $order);

                return $payment;
            }

            $order->update(['paid_amount' => round((float) $order->paid_amount + (float) $payment->amount, 2), 'payment_method' => in_array($order->payment_method, [null, '', 'due'], true) ? 'online' : $order->payment_method]);
            $this->activity->record('payment.received', $order, ['payment' => $payment->id, 'amount' => (float) $payment->amount], null);

            return $payment;
        });
    }

    /** The buyer came back saying they did not pay. Only a payment still waiting changes; a paid one never goes backwards. */
    public function close(Payment $payment, string $to): void
    {
        if ($payment->status === 'started' && in_array($to, ['failed', 'cancelled'], true)) {
            $payment->update(['status' => $to]);
        }
    }

    /** An order was cancelled: whatever was paid on it is now owed back. */
    public function orderCancelled(Invoice $order): void
    {
        Payment::where('payable_type', 'invoice')->where('payable_id', $order->id)->where('status', 'paid')->where('live', true)->get()
            ->each(function (Payment $p) use ($order) {
                $p->update(['status' => 'refund_due']);
                $this->tellOffice($p, $order);
            });
    }

    /** The office refunded it in the gateway's panel and says so here. @throws StockException */
    public function markRefunded(Payment $payment, User $by): Payment
    {
        if ($payment->status !== 'refund_due') {
            throw new StockException('There is nothing to refund on this payment.');
        }

        $payment->update(['status' => 'refunded', 'refunded_at' => now(), 'refunded_by' => $by->id]);
        $this->activity->record('payment.refunded', null, ['payment' => $payment->id, 'amount' => (float) $payment->amount], $by);

        return $payment->fresh();
    }

    /** What the buyer's order page shows. @return array<string, mixed> */
    public function forOrder(Invoice $order): array
    {
        $paid = Payment::where('payable_type', 'invoice')->where('payable_id', $order->id)->get();
        $why = $this->whyNot($order);

        return [
            'can' => $why === null, 'why' => $why, 'amount' => $this->due($order),
            'paid_online' => round((float) $paid->where('status', 'paid')->where('live', true)->sum('amount'), 2),
            'practice' => $paid->where('status', 'paid')->where('live', false)->isNotEmpty(),
            'refund_due' => $paid->where('status', 'refund_due')->isNotEmpty(),
        ];
    }

    /** The money for a market order has arrived: it is counted once, and only now does the seller see the order. @return Payment */
    private function settleMarket(Payment $payment): Payment
    {
        $order = MarketOrder::whereKey($payment->payable_id)->lockForUpdate()->first();

        if (! $order || ! $payment->live) {
            return $payment;
        }

        if ($order->status !== 'placed' || $order->paid_at !== null) {
            // Cancelled while the buyer was paying (or paid twice by two payments): the money must go back.
            $payment->update(['status' => 'refund_due']);
            $this->tellOffice($payment, null, $order->number());

            return $payment;
        }

        $order->update(['paid_at' => now()]);
        app(DigitalGoods::class)->deliver($order->fresh());
        $this->activity->record('payment.received', null, ['payment' => $payment->id, 'market_order' => $order->id, 'amount' => (float) $payment->amount], null);

        if ($order->seller) {
            app(Messenger::class)->send($order->seller, 'market_order_new', ['name' => $order->title], '/wholesale/sell/orders');
        }

        return $payment;
    }

    /** A market order was cancelled or sent back: whatever was paid on it is owed back. */
    public function marketCancelled(MarketOrder $order): void
    {
        Payment::where('payable_type', 'market_order')->where('payable_id', $order->id)->where('status', 'paid')->where('live', true)->get()
            ->each(function (Payment $p) use ($order) {
                $p->update(['status' => 'refund_due']);
                $this->tellOffice($p, null, $order->number());
            });
    }

    /** What the buyer's market order page shows. @return array<string, mixed> */
    public function forMarketOrder(MarketOrder $order): array
    {
        $paid = Payment::where('payable_type', 'market_order')->where('payable_id', $order->id)->get();
        $why = $this->whyNotMarket($order);

        return [
            'can' => $why === null, 'why' => $why, 'amount' => (float) $order->total, 'online' => $order->pay_by === 'online',
            'paid_online' => $order->paid_at !== null ? (float) $order->total : 0.0,
            'practice' => $paid->where('status', 'paid')->where('live', false)->isNotEmpty(),
            'refund_due' => $paid->where('status', 'refund_due')->isNotEmpty(),
        ];
    }

    private function tellOffice(Payment $payment, ?Invoice $order, ?string $label = null): void
    {
        $label ??= (string) ($order?->number ?? 'ORD'.$order?->id);

        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'payment_refund_due', 'body' => mb_substr($label, 0, 100)]);
        }
    }
}
