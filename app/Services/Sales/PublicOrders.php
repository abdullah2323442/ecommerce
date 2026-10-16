<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Stock\StockException;
use App\Services\Wallet\Tokens;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An order from a visitor or a public buyer (spec ch 13). It becomes an online retail invoice that staff check line by
 * line, exactly like a shop's order; the buyer follows it from a private link. Name, phone and address are required even
 * without an account. The buyer can cancel on their own for a short time, and an order nobody has answered is cancelled
 * after a while and the buyer is told.
 */
class PublicOrders
{
    public function __construct(private InvoiceService $invoices, private PublicCart $cart, private DeliveryCharge $delivery) {}

    /**
     * @param  array{name: string, phone: string, district: string, area: string, address: string, how_to_find?: ?string, for_whom?: ?string}  $ship
     *
     * @throws StockException
     */
    public function place(array $ship, string $mode, ?string $note, ?User $user, ?int $courierId = null, array $extras = []): Invoice
    {
        $lines = $this->cart->lines();

        if ($lines->isEmpty()) {
            throw new StockException('Your cart is empty.');
        }

        if ($mode === 'collect' && ! $this->delivery->pickupAvailable()) {
            throw new StockException('Collecting from the shop is not available.');
        }

        // The charge is worked out here from the cart, never taken from the browser. When couriers are offered, one must be picked.
        $quote = $this->delivery->quote($lines->map(fn ($l) => ['medicine' => $l['medicine'], 'unit' => $l['unit'], 'quantity' => $l['quantity']]), $mode, $ship['district'], $ship['area'] ?? null, $this->cart->subtotal());
        if ($quote['source'] === 'closed') {
            throw new StockException('We do not deliver to this district. You can collect it from the shop.');
        }

        if ($quote['source'] === 'blocked') {
            throw new StockException('A medicine that must stay cold cannot be sent by courier. Collect it from the shop, or order it to an address in our own delivery area.');
        }

        $option = $this->delivery->choose($quote, $quote['source'] === 'courier' ? $courierId : null);

        if ($option === null || ($quote['source'] === 'courier' && $courierId === null)) {
            throw new StockException('Pick a courier.');
        }

        return DB::transaction(function () use ($lines, $ship, $mode, $note, $user, $option, $extras) {
            $order = $this->invoices->open('retail', null, $user, 'online', $ship['name']);

            foreach ($lines as $l) {
                $this->invoices->addLine($order, $l['medicine'], $l['unit'], (float) $l['quantity'], null);
            }

            $order->update([
                'delivery_mode' => $mode, 'delivery_charge' => $option['charge'], 'courier_id' => $option['courier_id'], 'note' => $note,
                'ship_to' => $ship, 'public_token' => Str::random(40),
            ]);

            // A voice note, a photo and the time it is needed by (spec ch 23).
            app(OrderNotes::class)->attach($order, Arr::only($extras, ['voice', 'photo', 'wanted_time']));

            // The buyer's own token is chosen now and taken off when the order is finished (spec ch 24).
            if (! empty($extras['use_token'])) {
                app(Tokens::class)->chooseForInvoice($order, $user);
            }

            $placed = $this->invoices->place($order);
            $this->cart->clear();

            return $placed;
        });
    }

    /** The address can change until staff start on the order (spec ch 13: "until packed"). */
    public function canChangeAddress(Invoice $order): bool
    {
        return $order->channel === 'online' && $order->status === 'placed' && ! $this->invoices->pickingStarted($order);
    }

    /**
     * @param  array{name: string, phone: string, district: string, area: string, address: string, how_to_find?: ?string, for_whom?: ?string}  $ship
     *
     * @throws StockException
     */
    public function changeAddress(Invoice $order, array $ship): Invoice
    {
        if (! $this->canChangeAddress($order)) {
            throw new StockException('It is too late to change the address. Please call us.');
        }

        // A new district can change the charge: work it out again, keeping the courier the buyer chose when it still serves the new address.
        $items = $order->lines()->with('medicine')->get()->map(fn ($l) => ['medicine' => $l->medicine, 'unit' => $l->unit, 'quantity' => (float) $l->quantity]);
        $quote = $this->delivery->quote($items, (string) $order->delivery_mode, $ship['district'], $ship['area'] ?? null, $this->invoices->totals($order)['subtotal']);
        if ($quote['source'] === 'closed') {
            throw new StockException('We do not deliver to this district. You can collect it from the shop.');
        }

        if ($quote['source'] === 'blocked') {
            throw new StockException('A medicine that must stay cold cannot be sent by courier. Collect it from the shop, or order it to an address in our own delivery area.');
        }

        $option = $this->delivery->choose($quote, $order->courier_id) ?? ($quote['options'][0] ?? ['charge' => (float) $order->delivery_charge, 'courier_id' => null]);

        $order->update(['ship_to' => $ship, 'delivery_charge' => $option['charge'], 'courier_id' => $option['courier_id']]);

        return $order->fresh();
    }

    /** The buyer changes the amount of one item, within the same short time they can cancel in (spec ch 13). The delivery charge follows. */
    public function changeLine(Invoice $order, int $lineId, float $quantity): Invoice
    {
        if ($this->minutesToCancel($order) <= 0) {
            throw new StockException('The time to change this order by yourself is over. Please call us.');
        }

        $line = $order->lines()->whereKey($lineId)->firstOrFail();
        DB::transaction(function () use ($line, $quantity, $order) {
            $this->invoices->editPlacedLine($line, $quantity);
            $this->refreshCharge($order->fresh());
        });

        return $order->fresh();
    }

    public function dropLine(Invoice $order, int $lineId): Invoice
    {
        if ($this->minutesToCancel($order) <= 0) {
            throw new StockException('The time to change this order by yourself is over. Please call us.');
        }

        $line = $order->lines()->whereKey($lineId)->firstOrFail();
        DB::transaction(function () use ($line, $order) {
            $this->invoices->dropPlacedLine($line);
            $this->refreshCharge($order->fresh());
        });

        return $order->fresh();
    }

    /** Work the delivery charge out again from what is on the order now (a free-over line, a weight, a mandatory charge). */
    private function refreshCharge(Invoice $order): void
    {
        $ship = (array) $order->ship_to;
        $items = $order->lines()->with('medicine')->get()->map(fn ($l) => ['medicine' => $l->medicine, 'unit' => $l->unit, 'quantity' => (float) $l->quantity]);
        $quote = $this->delivery->quote($items, (string) $order->delivery_mode, (string) ($ship['district'] ?? ''), $ship['area'] ?? null, $this->invoices->totals($order)['subtotal']);
        $option = $this->delivery->choose($quote, $order->courier_id) ?? ($quote['options'][0] ?? null);

        if ($option !== null) {
            $order->update(['delivery_charge' => $option['charge'], 'courier_id' => $option['courier_id']]);
        }
    }

    /** Minutes left in which the buyer can cancel by themselves; 0 when the time is up or staff have started on it. */
    public function minutesToCancel(Invoice $order): int
    {
        if ($order->status !== 'placed' || $this->invoices->pickingStarted($order)) {
            return 0;
        }

        $window = max(1, (int) Setting::read('orders.edit_minutes', 20));

        return max(0, $window - (int) ($order->placed_at ?? $order->opened_at)->diffInMinutes(now()));
    }

    /** @throws StockException */
    public function cancelByBuyer(Invoice $order): void
    {
        if ($this->minutesToCancel($order) <= 0) {
            throw new StockException('The time to cancel by yourself is over. Please call us to change this order.');
        }

        $this->invoices->cancel($order, null);
    }

    /**
     * Orders nobody answered, cancelled after a set time (spec ch 13). The buyer, if they have an account, is told.
     *
     * @return int how many were cancelled
     */
    public function cancelStale(): int
    {
        $hours = max(1, (int) Setting::read('orders.stale_hours', 72));
        $count = 0;

        Invoice::where('channel', 'online')->where('kind', 'retail')->where('status', 'placed')->where('placed_at', '<', now()->subHours($hours))->get()
            ->each(function (Invoice $order) use (&$count) {
                if ($this->invoices->pickingStarted($order)) {
                    return;
                }

                $this->invoices->cancel($order, null, __('Nobody could look at it in time.'));
                $order->update(['note' => trim(($order->note ?? '').' Cancelled: nobody answered in time.')]);
                $count++;
            });

        return $count;
    }
}
