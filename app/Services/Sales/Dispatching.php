<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;

/**
 * Sending an order and knowing it arrived (spec ch 20). Staff mark a checked order as sent; if the real cost came to more than
 * what the buyer was charged, the extra is added then and the memo shows "extra delivery charge". The buyer sees "on the way"
 * and taps "received", or reports "not received". With no answer it confirms itself after a few days, unless it was reported.
 * (Delivery proof by OTP or photo needs an SMS service and the delivery person's screen, which come later.)
 */
class Dispatching
{
    public function __construct(private Messenger $messages, private ActivityLogger $activity) {}

    /** @throws StockException */
    /** Spec ch 20: above the set amount, the delivery charge is paid before the goods go. Zero means never. */
    public function advanceRequired(Invoice $order): bool
    {
        $over = (float) Setting::read('delivery.advance_over', 0);

        return $over > 0 && (float) $order->delivery_charge > 0 && in_array($order->delivery_mode, ['send', 'delivery'], true)
            && app(InvoiceService::class)->totals($order)['subtotal'] >= $over;
    }

    /** Spec ch 12: a delivery charge already paid and the order cancelled before it was sent: half goes back (handling was spent). Once sent and returned, all of it stays with us. */
    public const NOT_SENT_REFUND_PERCENT = 50;

    public function deliveryRefundFor(Invoice $order): float
    {
        return $order->delivery_advance_at !== null && $order->sent_at === null
            ? round((float) $order->delivery_charge * self::NOT_SENT_REFUND_PERCENT / 100, 2)
            : 0.0;
    }

    public function advanceMissing(Invoice $order): bool
    {
        return $this->advanceRequired($order) && $order->delivery_advance_at === null;
    }

    public function receiveAdvance(Invoice $order, User $by): Invoice
    {
        if ($order->channel !== 'online' || ! $this->advanceRequired($order)) {
            throw new StockException('This order does not need its delivery charge first.');
        }

        $order->update(['delivery_advance_at' => $order->delivery_advance_at ?? now()]);
        $this->activity->record('order.delivery_advance', $order, ['charge' => (string) $order->delivery_charge], $by);

        return $order->fresh();
    }

    public function send(Invoice $order, User $by, float $extra = 0.0, ?string $tracking = null, ?string $terms = null, ?float $courierCost = null, ?int $cartons = null): Invoice
    {
        if ($order->channel !== 'online' || ! in_array($order->status, ['verified', 'final'], true)) {
            throw new StockException('Only a checked order can be sent.');
        }

        if (! in_array($order->delivery_mode, ['send', 'delivery'], true)) {
            throw new StockException('This order is collected, not sent.');
        }

        if ($order->sent_at !== null) {
            throw new StockException('This order was already sent.');
        }

        if ($order->courier_id !== null && $order->lines()->whereHas('medicine', fn ($q) => $q->where('is_cold_chain', true))->where('quantity', '>', 0)->exists()) {
            throw new StockException('This order has a medicine that must stay cold. It cannot go by courier. Send it by our own delivery, packed with ice.');
        }

        if ($extra < 0) {
            throw new StockException('The extra charge cannot be less than nothing.');
        }

        if ($this->advanceMissing($order)) {
            throw new StockException('This order must pay its delivery charge first. Mark it received before sending.');
        }

        $charge = round((float) $order->delivery_charge + $extra, 2);
        $record = [];

        // Wholesale market (spec ch 20): the courier charge is shared 50-50, or a fixed amount is named; what is entered when sending becomes the buyer's charge.
        if ($terms !== null) {
            if ($order->kind === 'retail' || ! in_array($terms, ['split', 'fixed'], true)) {
                throw new StockException('Sharing the courier charge is for shops and wholesale buyers.');
            }

            if ($courierCost === null || $courierCost <= 0) {
                throw new StockException('Write what the courier charged, or the fixed amount.');
            }

            $charge = $terms === 'split' ? round($courierCost / 2, 2) : round($courierCost, 2);
            $extra = max(0.0, round($charge - (float) $order->delivery_charge, 2));
            $record = ['courier_terms' => $terms, 'courier_cost' => $courierCost, 'cartons' => $cartons !== null && $cartons > 0 ? min($cartons, 5000) : null];
        }

        $order->update([
            'sent_at' => now(), 'tracking' => $tracking !== null ? mb_substr(trim($tracking), 0, 80) : null,
            'delivery_extra' => $extra, 'delivery_charge' => $charge,
        ] + $record);
        $this->activity->record('order.sent', $order, ['extra' => $extra, 'tracking' => $tracking], $by);
        $this->messages->orderSent($order->fresh(), $tracking);

        return $order->fresh();
    }

    /** The buyer says it arrived. */
    public function received(Invoice $order, string $by = 'buyer'): Invoice
    {
        if ($order->sent_at === null) {
            throw new StockException('This order has not been sent yet.');
        }

        if ($order->delivered_at !== null) {
            return $order;
        }

        $order->update(['delivered_at' => now(), 'delivered_by' => $by, 'not_received_at' => null]);

        // The goods are with the buyer: a sale that was only a draft sale is a complete one now (spec 6.0).
        if ($order->status === 'final' && $order->completed_at === null) {
            $order->update(['completed_at' => now()]);
        }

        return $order->fresh();
    }

    /** The buyer says it did not arrive. It stays open for the owner to look into, and it will not confirm itself. */
    public function notReceived(Invoice $order): Invoice
    {
        if ($order->sent_at === null) {
            throw new StockException('This order has not been sent yet.');
        }

        if ($order->delivered_at !== null) {
            throw new StockException('You already said this arrived. Please call us.');
        }

        $order->update(['not_received_at' => $order->not_received_at ?? now()]);

        return $order->fresh();
    }

    /** What is still waiting a few days after it was sent is taken as delivered, unless the buyer said it did not come. */
    public function autoConfirm(): int
    {
        $days = max(1, (int) Setting::read('delivery.auto_confirm_days', 7));
        $count = 0;

        Invoice::whereNotNull('sent_at')->whereNull('delivered_at')->whereNull('not_received_at')->where('sent_at', '<', now()->subDays($days))->get()
            ->each(function (Invoice $o) use (&$count) {
                $this->received($o, 'auto');
                $count++;
            });

        return $count;
    }
}
