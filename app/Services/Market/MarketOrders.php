<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\MarketOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Payments\Payments;
use App\Services\Stock\StockException;
use App\Services\Wallet\Tokens;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orders for a seller's listing (spec ch 14). The buyer orders through us; the seller packs, ships and is paid by the buyer directly.
 * We keep the record, tell each side from our own address so the relationship stays ours, and count our commission only on a sale that
 * is complete. The seller never learns the buyer's name, and the phone and address are only open while the goods are on their way.
 */
class MarketOrders
{
    public function __construct(private Marketplace $market, private Messenger $messages) {}

    public function autoConfirmDays(): int
    {
        return max(1, (int) Setting::read('market.auto_confirm_days', 7));
    }

    // ------------------------------------------------------------------ ordering

    /**
     * @param  array{name: string, phone: string, district: string, area: string, address: string}  $ship
     *
     * @throws StockException
     */
    public function place(Listing $listing, ?User $buyer, array $ship, float $quantity, ?string $note = null, bool $useToken = false, ?float $agreedPrice = null, ?int $variantId = null, bool $pickup = false, ?string $payBy = null): MarketOrder
    {
        $listing->loadMissing(['sector', 'seller']);

        // A digital product (spec ch 13) is bought one at a time, is always paid online, and nothing is sent or collected.
        if ($listing->digital_path !== null) {
            if ($quantity != 1) {
                throw new StockException('A digital product is bought one at a time.');
            }

            $payBy = 'online';
            $pickup = false;
        }

        // Cash on delivery is set for each product (spec ch 13). A product that is paid online only is paid online; a cart that holds one of them is paid online as a whole.
        $payBy ??= $listing->cod_allowed !== false ? 'cod' : 'online';

        if ($payBy === 'cod' && $listing->cod_allowed === false) {
            throw new StockException('This product is paid online only.');
        }

        if ($payBy === 'online' && ! app(Payments::class)->enabled()) {
            throw new StockException('This needs payment online, and online payment is not open yet.');
        }

        // A product with colours and sizes is bought one cell at a time, at that cell's price (spec ch 15).
        $variant = null;
        if ($listing->variants()->exists()) {
            $variant = $listing->variants()->whereKey($variantId)->first() ?? throw new StockException('Pick a colour and a size.');
        } elseif ($variantId !== null) {
            throw new StockException('Pick a colour and a size.');
        }

        $price = $this->market->priceFor($listing, $buyer, $variant) ?? throw new StockException('This product is not available.');

        // Collecting saves the delivery charge, but only where the seller's name and place are shown (spec ch 20).
        if ($pickup && ! $this->market->canPickup($listing)) {
            throw new StockException('Collecting from the shop is not available for this product.');
        }
        $delivery = $pickup ? 0.0 : (float) $listing->delivery_charge;

        // A price the seller agreed to (spec ch 13): our commission stays the same in taka, so the seller carries the whole discount.
        if ($agreedPrice !== null) {
            $each = $price['price'] - $price['seller_price'];
            $price['price'] = round($agreedPrice, 2);
            $price['seller_price'] = round($agreedPrice - $each, 2);

            if ($price['seller_price'] <= 0) {
                throw new StockException('That price is too low.');
            }
        }

        if ($buyer && $buyer->id === $listing->seller_id) {
            throw new StockException('You cannot buy your own product.');
        }

        if ($listing->seller->seller_paused) {
            throw new StockException('The seller is not taking new orders now.');
        }

        if ($quantity <= 0) {
            throw new StockException('The quantity must be more than zero.');
        }

        if (! $listing->sector->allow_fraction && floor($quantity) != $quantity) {
            throw new StockException('This kind of product is sold in whole units.');
        }

        if ($quantity + 0.00001 < $price['min_qty']) {
            throw new StockException(__('The smallest order is {n}.', ['n' => rtrim(rtrim(number_format($price['min_qty'], 2, '.', ''), '0'), '.')]));
        }

        return DB::transaction(function () use ($listing, $buyer, $ship, $quantity, $note, $price, $useToken, $variant, $pickup, $delivery, $payBy) {
            $fresh = $variant ? ListingVariant::whereKey($variant->id)->lockForUpdate()->firstOrFail() : Listing::whereKey($listing->id)->lockForUpdate()->firstOrFail();

            // The seller counts what they have: an order cannot be taken for more than that (and does not say how much it is).
            $preorder = false;
            if ($fresh->stock !== null && (float) $fresh->stock + 0.00001 < $quantity) {
                // Where the sector allows pre-order, what is not there can still be ordered; nothing is taken off the shelf (spec ch 15).
                if (! $listing->sector->preorder) {
                    throw new StockException('There is not enough of this to fill that order.');
                }
                $preorder = true;
            }

            if ($fresh->stock !== null && ! $preorder) {
                $before = (float) $fresh->stock;
                $fresh->update(['stock' => round($before - $quantity, 2)]);

                // The seller's own mark (spec ch 15): told once, when it falls to it.
                if (! $variant && $fresh->low_stock_at !== null && $before > (float) $fresh->low_stock_at && (float) $fresh->stock <= (float) $fresh->low_stock_at) {
                    $this->messages->send($listing->seller, 'listing_low_stock', ['name' => $listing->title], '/wholesale/sell');
                }
            }

            // A token (spec ch 24): the larger of ours and the seller's own, never both, and never more than the goods cost.
            $commission = round($quantity * ($price['price'] - $price['seller_price']), 2);
            $pick = $useToken ? app(Tokens::class)->forMarket($buyer, $listing->seller, round($quantity * $price['price'], 2), $commission) : null;
            $off = $pick['amount'] ?? 0.0;

            $order = MarketOrder::create([
                'public_token' => Str::random(40), 'listing_id' => $listing->id, 'variant_id' => $variant?->id, 'variant_label' => $variant?->label() ?: null, 'seller_id' => $listing->seller_id, 'buyer_id' => $buyer?->id,
                'title' => $listing->title, 'unit' => $listing->sector->unit, 'quantity' => $quantity, 'unit_price' => $price['price'], 'seller_price' => $price['seller_price'],
                'commission' => $pick && $pick['kind'] === 'platform' ? round($commission - $off, 2) : $commission, 'delivery_charge' => $delivery, 'pickup' => $pickup,
                'total' => round($quantity * $price['price'] + $delivery - $off, 2), 'token_amount' => $off, 'token_kind' => $pick['kind'] ?? null, 'audience' => $price['audience'], 'ship_to' => $ship,
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 300) : null, 'status' => 'placed', 'pay_by' => $payBy, 'preorder' => $preorder, 'preorder_days' => $preorder ? $listing->preorder_days : null,
            ]);

            if ($pick) {
                app(Tokens::class)->spendOnMarket($order, $pick, $buyer);
            }

            // An order that waits for its payment is shown to the seller only once it is paid.
            if ($payBy === 'cod') {
                $this->messages->send($listing->seller, 'market_order_new', ['name' => $order->title], '/wholesale/sell/orders');
            }

            return $order;
        });
    }

    // ------------------------------------------------------------------ the seller's side

    public function accept(MarketOrder $order, User $seller): MarketOrder
    {
        $this->mustBeSellers($order, $seller);

        if ($order->status !== 'placed') {
            throw new StockException('This order was already answered.');
        }

        if ($order->pay_by === 'online' && $order->paid_at === null) {
            throw new StockException('The buyer has not paid yet.');
        }

        $order->update(['status' => 'accepted', 'accepted_at' => now(), 'pickup_ready_at' => $order->pickup ? now() : null]);

        // A collected order is ready as soon as it is accepted: the buyer is told where to come.
        $order->pickup ? $this->tellBuyer($order, 'market_pickup_ready', ['where' => $this->where($order)]) : $this->tellBuyer($order, 'market_order_accepted');

        return $order->fresh();
    }

    public function ship(MarketOrder $order, User $seller, ?string $tracking = null): MarketOrder
    {
        $this->mustBeSellers($order, $seller);

        if ($order->pickup) {
            throw new StockException('This order is collected, not sent. Press Handed over when they take it.');
        }

        if (! in_array($order->status, ['placed', 'accepted'], true)) {
            throw new StockException('This order cannot be sent now.');
        }

        $order->update(['status' => 'shipped', 'shipped_at' => now(), 'accepted_at' => $order->accepted_at ?? now(), 'tracking' => $tracking !== null && trim($tracking) !== '' ? mb_substr(trim($tracking), 0, 120) : null]);
        $this->tellBuyer($order, 'market_order_sent');

        return $order->fresh();
    }

    /** The buyer came and took it: the sale is complete, and our commission with it (spec ch 20). */
    public function collected(MarketOrder $order, User $seller): MarketOrder
    {
        $this->mustBeSellers($order, $seller);

        if (! $order->pickup) {
            throw new StockException('This order is not collected.');
        }

        if ($order->status !== 'accepted') {
            throw new StockException('Accept the order first, then hand it over.');
        }

        $order->update(['status' => 'delivered', 'delivered_at' => now()]);

        return $order->fresh();
    }

    /**
     * Collected orders nobody comes for (spec ch 20): a reminder a few days after it was ready, and if still nobody comes a few days
     * later, the order is cancelled and the goods go back on the shelf. The buyer pays the seller on collecting, so no money is held.
     *
     * @return array{reminded: int, cancelled: int}
     */
    public function sweepPickups(): array
    {
        $remind = max(1, (int) Setting::read('market.pickup_remind_days', 3));
        $cancel = max(1, (int) Setting::read('market.pickup_cancel_days', 3));
        $out = ['reminded' => 0, 'cancelled' => 0];

        MarketOrder::where('pickup', true)->where('status', 'accepted')->whereNull('pickup_reminded_at')->where('pickup_ready_at', '<=', now()->subDays($remind))->with('seller', 'buyer')->get()
            ->each(function (MarketOrder $o) use (&$out) {
                $o->update(['pickup_reminded_at' => now()]);
                $this->tellBuyer($o, 'market_pickup_reminder', ['where' => $this->where($o)]);
                $out['reminded']++;
            });

        MarketOrder::where('pickup', true)->where('status', 'accepted')->whereNotNull('pickup_reminded_at')->where('pickup_reminded_at', '<=', now()->subDays($cancel))->get()
            ->each(function (MarketOrder $o) use (&$out) {
                $this->cancel($o, 'system', 'Nobody came to collect it.');
                $out['cancelled']++;
            });

        return $out;
    }

    /** Where to collect: the seller's shop, as they wrote it. */
    public function where(MarketOrder $order): string
    {
        return (string) ($order->seller?->shop_address ?? '');
    }

    // ------------------------------------------------------------------ cancelling, and the buyer's answer

    /** @param 'buyer'|'seller'|'system' $by */
    public function cancel(MarketOrder $order, string $by, ?string $reason = null): MarketOrder
    {
        if (! in_array($order->status, ['placed', 'accepted'], true)) {
            throw new StockException('It is too late to cancel this order.');
        }

        DB::transaction(function () use ($order, $by, $reason) {
            $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by, 'cancel_reason' => $reason ? mb_substr(trim($reason), 0, 200) : null]);

            // What was held for this order goes back on the seller's shelf.
            $held = $order->variant_id ? ListingVariant::whereKey($order->variant_id)->lockForUpdate()->first() : ($order->listing_id ? Listing::whereKey($order->listing_id)->lockForUpdate()->first() : null);
            if ($held && $held->stock !== null && ! $order->preorder) {
                $held->update(['stock' => round((float) $held->stock + (float) $order->quantity, 2)]);
            }

            // The token used on it comes back (spec ch 24).
            app(Tokens::class)->releaseMarket($order);
            app(Payments::class)->marketCancelled($order);
        });

        // An unpaid online order was never shown to the seller, so there is nothing to tell them.
        if ($by === 'buyer' && ! ($order->pay_by === 'online' && $order->paid_at === null)) {
            $this->messages->send($order->seller, 'market_order_cancelled', ['name' => $order->title], '/wholesale/sell/orders');
        } elseif ($by !== 'buyer') {
            $this->tellBuyer($order, 'market_order_cancelled');
        }

        return $order->fresh();
    }

    /** The buyer says it arrived, or enough days pass without a word from them. The sale is complete and the commission is ours. */
    public function confirm(MarketOrder $order): MarketOrder
    {
        if ($order->status === 'delivered') {
            return $order;
        }

        if ($order->status !== 'shipped') {
            throw new StockException('This order has not been sent yet.');
        }

        $order->update(['status' => 'delivered', 'delivered_at' => now()]);

        return $order->fresh();
    }

    /** "Not received": it is not a complete sale, so no commission. The seller cannot make it one. */
    public function notReceived(MarketOrder $order): MarketOrder
    {
        if ($order->status !== 'shipped') {
            throw new StockException('Only an order that was sent can be reported as not received.');
        }

        $order->update(['status' => 'not_received', 'not_received_at' => now()]);

        return $order->fresh();
    }

    /** A sent order nobody has answered for a few days is taken as received (unless the buyer said it was not). Returns how many. */
    public function autoConfirm(): int
    {
        return MarketOrder::where('status', 'shipped')->where('shipped_at', '<=', now()->subDays($this->autoConfirmDays()))
            ->update(['status' => 'delivered', 'delivered_at' => now()]);
    }

    // ------------------------------------------------------------------ seeing them

    /** The seller's orders as the seller may see them: no name; phone and address only while the goods are on their way. */
    public function forSeller(MarketOrder $order): array
    {
        $ship = (array) $order->ship_to;
        $open = $order->isActive();
        $hide = '***';

        return [
            'id' => $order->id, 'number' => $order->number(), 'title' => $order->title, 'variant' => $order->variant_label, 'unit' => $order->unit, 'quantity' => (float) $order->quantity, 'status' => $order->status,
            'note' => $order->note, 'tracking' => $order->tracking, 'placed_at' => $order->created_at->toIso8601String(), 'delivery_charge' => (float) $order->delivery_charge,
            // What the seller is paid for it; our commission is never in what they see as the buyer's price.
            'you_get' => $order->sellerGets(), 'seller_price' => (float) $order->seller_price,
            'unseen' => $order->seller_seen_at === null, 'preorder' => (bool) $order->preorder, 'pickup' => (bool) $order->pickup,
            'phone' => $open ? ($ship['phone'] ?? null) : $hide,
            'address' => $order->pickup ? ($open ? __('They collect it from your shop.') : $hide) : ($open ? trim(($ship['address'] ?? '').', '.($ship['area'] ?? '').', '.($ship['district'] ?? '')) : $hide),
            'how_to_find' => $open ? ($ship['how_to_find'] ?? null) : null,
            'cancel_reason' => $order->cancel_reason, 'cancelled_by' => $order->cancelled_by,
            // What the buyer wrote, never who they are.
            'cases' => $order->cases->map(fn ($c) => ['id' => $c->id, 'kind' => $c->kind, 'reason' => $c->reason, 'status' => $c->status, 'seller_note' => $c->seller_note, 'our_note' => $c->our_note])->values()->all(),
        ];
    }

    public function unseen(User $seller): int
    {
        return MarketOrder::where('seller_id', $seller->id)->whereNull('seller_seen_at')->count();
    }

    public function markSeen(User $seller): void
    {
        MarketOrder::where('seller_id', $seller->id)->whereNull('seller_seen_at')->update(['seller_seen_at' => now()]);
    }

    /**
     * What each seller owes us on complete sales, and how many of each product sold (spec ch 14: "who owes how much, how many sold").
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function owed(?string $month = null): Collection
    {
        // What the buyer paid us online is not collected by the seller, so there is nothing for them to hand over: our commission is already kept (see payouts()).
        $orders = MarketOrder::where('status', 'delivered')->where('pay_by', 'cod')->with('seller:id,name,business_name')
            ->when($month, fn ($q) => $q->whereBetween('delivered_at', [Carbon::parse($month.'-01')->startOfMonth(), Carbon::parse($month.'-01')->endOfMonth()]))
            ->get();

        return $orders->groupBy('seller_id')->map(function (Collection $rows) {
            $seller = $rows->first()->seller;

            return [
                'seller_id' => $seller->id, 'seller' => $seller->business_name ?: $seller->name, 'orders' => $rows->count(),
                'sales' => round((float) $rows->sum(fn (MarketOrder $o) => (float) $o->quantity * (float) $o->seller_price), 2), 'commission' => round((float) $rows->sum('commission'), 2),
                'products' => $rows->groupBy('title')->map(fn (Collection $g, $title) => ['title' => $title, 'sold' => round((float) $g->sum('quantity'), 2), 'commission' => round((float) $g->sum('commission'), 2)])->values()->all(),
            ];
        })->sortByDesc('commission')->values();
    }

    /**
     * What we owe each seller for complete sales that were paid to us online: what the buyer paid, less our commission (spec ch 14: money held, then paid
     * out once the sale is complete). A sale that was sent back or not received is not delivered, so it is not here.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function payouts(): Collection
    {
        return MarketOrder::where('status', 'delivered')->where('pay_by', 'online')->whereNotNull('paid_at')->whereNull('payout_at')->with('seller:id,name,business_name')->get()
            ->groupBy('seller_id')->map(function (Collection $rows) {
                $seller = $rows->first()->seller;

                return [
                    'seller_id' => $seller->id, 'seller' => $seller->business_name ?: $seller->name, 'orders' => $rows->count(),
                    'amount' => round((float) $rows->sum(fn (MarketOrder $o) => (float) $o->total - (float) $o->commission), 2),
                ];
            })->sortByDesc('amount')->values();
    }

    /** The office paid a seller what is owed up to now. @return int how many orders were marked */
    public function markPaidOut(User $seller): int
    {
        return MarketOrder::where('seller_id', $seller->id)->where('status', 'delivered')->where('pay_by', 'online')->whereNotNull('paid_at')->whereNull('payout_at')->update(['payout_at' => now()]);
    }

    /** Online orders the buyer never paid for are cancelled after a day, so the stock is not held for ever. @return int how many */
    public function expireUnpaid(): int
    {
        $n = 0;

        MarketOrder::where('status', 'placed')->where('pay_by', 'online')->whereNull('paid_at')->where('created_at', '<', now()->subDay())->get()->each(function (MarketOrder $o) use (&$n) {
            $this->cancel($o, 'system', 'Not paid in time.');
            $n++;
        });

        return $n;
    }

    // ------------------------------------------------------------------ helpers

    private function mustBeSellers(MarketOrder $order, User $seller): void
    {
        if ($order->seller_id !== $seller->id) {
            throw new StockException('This is not your order.');
        }
    }

    /** From our own address, so the buyer's relationship stays with us (spec ch 14). Guests follow it on their private page. */
    private function tellBuyer(MarketOrder $order, string $kind, array $more = []): void
    {
        if ($order->buyer) {
            $this->messages->send($order->buyer, $kind, ['name' => $order->title] + $more, '/market/orders/'.$order->public_token);
        }
    }
}
