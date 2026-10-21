<?php

namespace App\Services\Market;

use App\Models\Listing;
use App\Models\ListingVariant;
use App\Models\MarketOrder;
use App\Models\User;
use App\Services\Payments\Payments;
use App\Services\Sales\CartStore;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The market cart (spec ch 13, "just like Daraz"): products from different sellers and sections in one cart, one checkout, and the order split by seller.
 * Each seller gets their own order, packs and ships it, and has their own delivery charge; the buyer sees them as Package 1, Package 2.
 * The cart lives in the session. Prices are worked out again on every look, for this viewer, never taken from the browser.
 */
class MarketCart
{
    public const KEY = 'market_cart';

    public const MAX_LINES = 30;

    public function __construct(private Marketplace $market, private MarketOrders $orders) {}

    /** @return array<int, array{l: int, v: ?int, q: float}> */
    public function raw(): array
    {
        return CartStore::get(self::KEY);
    }

    public function count(): int
    {
        return count($this->raw());
    }

    public static function key(int $listingId, ?int $variantId): string
    {
        return $listingId.':'.($variantId ?? 0);
    }

    /** @throws StockException */
    public function add(Listing $listing, ?int $variantId, float $quantity, ?User $viewer): void
    {
        $listing->loadMissing(['sector', 'seller']);
        $variant = null;

        if ($listing->variants()->exists()) {
            $variant = $listing->variants()->whereKey($variantId)->first() ?? throw new StockException('Pick a colour and a size.');
        } elseif ($variantId !== null) {
            throw new StockException('Pick a colour and a size.');
        }

        $price = $this->market->priceFor($listing, $viewer, $variant) ?? throw new StockException('This product is not available.');

        if ($viewer && $viewer->id === $listing->seller_id) {
            throw new StockException('You cannot buy your own product.');
        }

        if ($quantity <= 0) {
            throw new StockException('The quantity must be more than zero.');
        }

        if (! $listing->sector->allow_fraction && floor($quantity) != $quantity) {
            throw new StockException('This kind of product is sold in whole units.');
        }

        $items = collect($this->raw());
        $key = self::key($listing->id, $variant?->id);
        $at = $items->search(fn ($i) => self::key($i['l'], $i['v']) === $key);

        if ($at === false) {
            if ($items->count() >= self::MAX_LINES) {
                throw new StockException('The cart is full. Order what is in it first.');
            }

            $items->push(['l' => $listing->id, 'v' => $variant?->id, 'q' => 0.0]);
            $at = $items->count() - 1;
        }

        $row = $items->get($at);
        $row['q'] = min(1000000.0, $row['q'] + $quantity);

        if ($price['min_qty'] > $row['q'] + 0.00001) {
            throw new StockException(__('The smallest order is {n}.', ['n' => rtrim(rtrim(number_format($price['min_qty'], 2, '.', ''), '0'), '.')]));
        }

        $items->put($at, $row);
        CartStore::put(self::KEY, $items->values()->all());
    }

    public function set(string $key, float $quantity): void
    {
        $quantity = min(1000000.0, max(0.01, $quantity));
        CartStore::put(self::KEY, collect($this->raw())->map(fn ($i) => self::key($i['l'], $i['v']) === $key ? [...$i, 'q' => $quantity] : $i)->values()->all());
    }

    public function remove(string $key): void
    {
        CartStore::put(self::KEY, collect($this->raw())->reject(fn ($i) => self::key($i['l'], $i['v']) === $key)->values()->all());
    }

    public function clear(): void
    {
        CartStore::forget(self::KEY);
    }

    /**
     * The cart as this viewer sees it, split into packages by seller. A product that went away, or is not for this viewer, drops out quietly.
     *
     * @return array{packages: array<int, array<string, mixed>>, total: float}
     */
    public function packages(?User $viewer): array
    {
        $lines = [];

        foreach ($this->raw() as $i) {
            $listing = Listing::with(['sector', 'seller'])->find($i['l']);
            $variant = $i['v'] ? ListingVariant::find($i['v']) : null;
            $price = $listing ? $this->market->priceFor($listing, $viewer, $variant) : null;

            if ($listing === null || $price === null) {
                continue;
            }

            $lines[] = [
                'key' => self::key($listing->id, $variant?->id), 'listing' => $listing, 'id' => $listing->id, 'title' => $listing->title, 'variant' => $variant?->label() ?: null, 'unit' => $listing->sector->unit,
                'quantity' => (float) $i['q'], 'price' => (float) $price['price'], 'total' => round((float) $i['q'] * (float) $price['price'], 2),
            ];
        }

        $packages = collect($lines)->groupBy(fn ($l) => $l['listing']->seller_id)->values()->map(function ($rows, int $n) {
            $first = $rows->first()['listing'];
            // One delivery charge for each seller's package: the dearest one among their products in it.
            $delivery = (float) $rows->max(fn ($r) => (float) $r['listing']->delivery_charge);
            $goods = round((float) $rows->sum('total'), 2);

            return [
                'n' => $n + 1, 'seller' => $this->market->sellerName($first), 'delivery_charge' => $delivery, 'goods' => $goods, 'total' => round($goods + $delivery, 2),
                'lines' => $rows->map(fn ($r) => collect($r)->except(['listing'])->all())->values()->all(),
            ];
        })->all();

        // Spec ch 13: one product without cash on delivery and it is hidden for the whole cart.
        $cod = collect($lines)->every(fn ($l) => (bool) $l['listing']->cod_allowed);

        return ['packages' => $packages, 'total' => round((float) collect($packages)->sum('total'), 2), 'cod' => $cod, 'online_open' => app(Payments::class)->enabled()];
    }

    /**
     * One checkout, one order for each product, all or nothing: if any line cannot be filled, none is placed and the buyer is told which. The orders share a
     * group link. A token (if the buyer asks) goes on the first order only; one token is for one order.
     *
     * @param  array<string, mixed>  $ship
     * @return array{group: string, orders: array<int, MarketOrder>}
     *
     * @throws StockException
     */
    public function checkout(?User $buyer, array $ship, ?string $note, bool $useToken): array
    {
        $lines = collect($this->raw());

        if ($lines->isEmpty()) {
            throw new StockException('Your cart is empty.');
        }

        $group = Str::random(40);
        $orders = [];

        // Cash on delivery is all or nothing for the cart (spec ch 13).
        $payBy = $lines->every(fn ($i) => (bool) (Listing::find($i['l'])?->cod_allowed ?? true)) ? 'cod' : 'online';

        DB::transaction(function () use ($lines, $buyer, $ship, $note, $useToken, $group, $payBy, &$orders) {
            foreach ($lines as $n => $i) {
                $listing = Listing::with(['sector', 'seller'])->find($i['l']) ?? throw new StockException('This product is not available.');

                try {
                    $order = $this->orders->place($listing, $buyer, $ship, (float) $i['q'], $note, $useToken && $n === 0, null, $i['v'], false, $payBy);
                } catch (StockException $e) {
                    throw new StockException($listing->title.': '.__($e->getMessage()));
                }

                $order->update(['group_token' => $group]);
                $orders[] = $order->fresh();
            }
        });

        $this->clear();

        return ['group' => $group, 'orders' => $orders];
    }
}
