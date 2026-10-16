<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\PriceOffer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;

/**
 * "Why didn't you buy" and a price the buyer proposes (spec ch 13). The reason always reaches the seller, so they learn why it is not selling.
 * When the reason is the price, the buyer may propose their own: the seller agrees (the buyer can order at it), counters, or says no. An offer
 * nobody answers is cancelled after a set time and the buyer is told. A price is a price for each, with our commission inside it, and our
 * commission stays the same in taka, so the seller carries the whole discount.
 */
class PriceOffers
{
    public function __construct(private Marketplace $market, private MarketOrders $orders, private Messenger $messages) {}

    public function hours(): int
    {
        return max(1, (int) Setting::read('market.offer_hours', 24));
    }

    // ------------------------------------------------------------------ the buyer

    /** @throws StockException */
    public function note(Listing $listing, User $buyer, string $reason, ?float $price = null, ?float $quantity = null): PriceOffer
    {
        $listing->loadMissing(['sector', 'seller']);

        if (! in_array($reason, PriceOffer::REASONS, true)) {
            throw new StockException('Pick a reason.');
        }

        if ($buyer->id === $listing->seller_id) {
            throw new StockException('You cannot make an offer on your own product.');
        }

        $now = $this->market->priceFor($listing, $buyer) ?? throw new StockException('This product is not available.');

        if ($reason === 'price' && $price !== null && $listing->variants()->exists()) {
            throw new StockException('Please order this one at its price. Offers are not open for products with colours and sizes yet.');
        }

        // One reason a day for one product from one buyer: this is a question, not a haggle.
        if (PriceOffer::where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->where('created_at', '>=', now()->subDay())->exists()) {
            throw new StockException('You already told us today. Thank you.');
        }

        $offer = ['listing_id' => $listing->id, 'seller_id' => $listing->seller_id, 'buyer_id' => $buyer->id, 'reason' => $reason, 'status' => 'noted',
            'quantity' => max($now['min_qty'], $quantity ?? $now['min_qty']), 'commission_each' => round($now['price'] - $now['seller_price'], 2)];

        if ($reason === 'price' && $price !== null) {
            if (PriceOffer::where('listing_id', $listing->id)->where('buyer_id', $buyer->id)->whereIn('status', ['waiting', 'accepted', 'countered'])->exists()) {
                throw new StockException('You already have an offer on this product.');
            }

            if ($price <= $offer['commission_each']) {
                throw new StockException('That price is too low.');
            }

            if ($price >= $now['price']) {
                throw new StockException('The price is already that low or lower. You can order it now.');
            }

            $offer = array_merge($offer, ['offered_price' => round($price, 2), 'status' => 'waiting', 'expires_at' => now()->addHours($this->hours())]);
        }

        $made = PriceOffer::create($offer);

        if ($made->status === 'waiting') {
            $this->messages->send($listing->seller, 'price_offer_new', ['name' => $listing->title], '/wholesale/sell/offers');
        }

        return $made;
    }

    /** The buyer lets go of an offer. */
    public function withdraw(PriceOffer $offer): PriceOffer
    {
        if (! in_array($offer->status, ['waiting', 'accepted', 'countered'], true)) {
            throw new StockException('This offer is over.');
        }

        $offer->update(['status' => 'withdrawn']);

        return $offer->fresh();
    }

    /**
     * The buyer orders at the price that was agreed, before it runs out.
     *
     * @param  array{name: string, phone: string, district: string, area: string, address: string}  $ship
     *
     * @throws StockException
     */
    public function order(PriceOffer $offer, array $ship, ?string $note = null): MarketOrder
    {
        $price = $offer->agreedPrice();

        if ($price === null) {
            throw new StockException('There is no agreed price to order at.');
        }

        if ($offer->expires_at && $offer->expires_at->isPast()) {
            throw new StockException('This offer has run out.');
        }

        $order = $this->orders->place($offer->listing, $offer->buyer, $ship, (float) $offer->quantity, $note, false, $price);
        $offer->update(['status' => 'ordered', 'market_order_id' => $order->id]);

        return $order;
    }

    // ------------------------------------------------------------------ the seller

    /** @throws StockException */
    public function accept(PriceOffer $offer, User $seller): PriceOffer
    {
        $this->mustBeWaiting($offer, $seller);
        $offer->update(['status' => 'accepted', 'answered_at' => now(), 'expires_at' => now()->addHours($this->hours())]);
        $this->tell($offer, 'price_offer_accepted');

        return $offer->fresh();
    }

    /** The seller names a price in between (what the buyer would pay for each). */
    public function counter(PriceOffer $offer, User $seller, float $price): PriceOffer
    {
        $this->mustBeWaiting($offer, $seller);
        $current = $this->market->priceFor($offer->listing->loadMissing(['sector', 'seller']), $offer->buyer);

        if ($price <= (float) $offer->offered_price || ($current && $price >= $current['price'])) {
            throw new StockException('Name a price between the buyer\'s offer and today\'s price.');
        }

        $offer->update(['status' => 'countered', 'counter_price' => round($price, 2), 'answered_at' => now(), 'expires_at' => now()->addHours($this->hours())]);
        $this->tell($offer, 'price_offer_countered');

        return $offer->fresh();
    }

    public function decline(PriceOffer $offer, User $seller): PriceOffer
    {
        $this->mustBeWaiting($offer, $seller);
        $offer->update(['status' => 'declined', 'answered_at' => now()]);
        $this->tell($offer, 'price_offer_declined');

        return $offer->fresh();
    }

    /**
     * An offer nobody answered, or an agreed price nobody ordered at, is cancelled after the set time and the buyer is told (spec ch 13).
     *
     * @return int how many were cancelled
     */
    public function expire(): int
    {
        $count = 0;

        PriceOffer::whereIn('status', ['waiting', 'accepted', 'countered'])->where('expires_at', '<=', now())->with('listing:id,title', 'buyer')->get()
            ->each(function (PriceOffer $o) use (&$count) {
                $o->update(['status' => 'expired']);
                $this->tell($o, 'price_offer_expired');
                $count++;
            });

        return $count;
    }

    // ------------------------------------------------------------------ seeing them

    /** What buyers say when they leave, counted for one seller: so they can see why it is not selling. */
    public function reasons(User $seller): array
    {
        $rows = PriceOffer::where('seller_id', $seller->id)->selectRaw('reason, count(*) as n')->groupBy('reason')->pluck('n', 'reason');

        return collect(PriceOffer::REASONS)->mapWithKeys(fn ($r) => [$r => (int) ($rows[$r] ?? 0)])->all();
    }

    public function forSeller(User $seller): Collection
    {
        return PriceOffer::where('seller_id', $seller->id)->where('status', 'waiting')->with('listing.sector', 'listing.seller', 'buyer')->oldest('id')->get()
            ->map(fn (PriceOffer $o) => [
                'id' => $o->id, 'listing' => $o->listing->title, 'quantity' => (float) $o->quantity, 'offered' => (float) $o->offered_price,
                // What the seller would be paid at it: the commission is ours and stays.
                'you_get' => round((float) $o->offered_price - (float) $o->commission_each, 2), 'commission' => (float) $o->commission_each,
                'now' => ($p = $this->market->priceFor($o->listing->loadMissing(['sector', 'seller']), $o->buyer)) ? $p['price'] : null,
                'expires_at' => $o->expires_at?->toIso8601String(), 'at' => $o->created_at->toIso8601String(),
            ]);
    }

    public function forBuyer(User $buyer): Collection
    {
        return PriceOffer::where('buyer_id', $buyer->id)->where('reason', 'price')->latest('id')->limit(30)->with('listing:id,title')->get()
            ->map(fn (PriceOffer $o) => [
                'id' => $o->id, 'listing_id' => $o->listing_id, 'listing' => $o->listing->title, 'quantity' => (float) $o->quantity, 'offered' => (float) $o->offered_price,
                'status' => $o->status, 'agreed' => $o->agreedPrice(), 'expires_at' => $o->expires_at?->toIso8601String(), 'at' => $o->created_at->toIso8601String(),
            ]);
    }

    // ------------------------------------------------------------------ helpers

    private function mustBeWaiting(PriceOffer $offer, User $seller): void
    {
        if ($offer->seller_id !== $seller->id) {
            throw new StockException('This is not your offer.');
        }

        if ($offer->status !== 'waiting') {
            throw new StockException('This offer was already answered.');
        }

        if ($offer->expires_at && $offer->expires_at->isPast()) {
            throw new StockException('This offer has run out.');
        }
    }

    private function tell(PriceOffer $offer, string $kind): void
    {
        $offer->loadMissing('listing:id,title', 'buyer');
        $this->messages->send($offer->buyer, $kind, ['name' => $offer->listing->title], '/market-offers');
    }
}
