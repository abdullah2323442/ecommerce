<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\SavedCart;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;

/**
 * The cart of a visitor or a signed-in public buyer (spec ch 13). A visitor's lives in the session and a signed-in person's in their account (see CartStore): it
 * takes no stock and has no owner until the order is sent. Prices are the printed retail prices, the same for everyone.
 */
class PublicCart
{
    public const KEY = 'cart';

    public const MAX_QUANTITY = 1000;

    public function __construct(private InvoiceService $invoices) {}

    /** The units this medicine can be bought in. */
    public static function unitsFor(Medicine $m): array
    {
        return $m->packaging_type === 'box' ? ['box', 'strip', 'piece'] : ['piece'];
    }

    /** @return list<array{m: int, u: string, q: int}> */
    public function raw(): array
    {
        return CartStore::get(self::KEY);
    }

    /** How many different items are in the cart (the number on the cart button). */
    public function count(): int
    {
        return count($this->raw());
    }

    public function key(int $medicineId, string $unit): string
    {
        return $medicineId.':'.$unit;
    }

    /**
     * @throws StockException
     */
    public function add(Medicine $medicine, string $unit, int $quantity): void
    {
        if (! $medicine->is_active) {
            throw new StockException('This medicine is not available to order.');
        }

        if (! in_array($unit, self::unitsFor($medicine), true)) {
            throw new StockException('Pick how you want to buy it.');
        }

        $items = collect($this->raw());
        $key = $this->key($medicine->id, $unit);
        $found = $items->search(fn ($i) => $this->key($i['m'], $i['u']) === $key);

        if ($found === false) {
            $items->push(['m' => $medicine->id, 'u' => $unit, 'q' => 0]);
            $found = $items->count() - 1;
        }

        $row = $items->get($found);
        $row['q'] = min(self::MAX_QUANTITY, $row['q'] + max(1, $quantity));
        $items->put($found, $row);

        CartStore::put(self::KEY, $items->values()->all());
        $this->touch();
    }

    public function set(string $key, int $quantity): void
    {
        $items = collect($this->raw())->map(fn ($i) => $this->key($i['m'], $i['u']) === $key ? [...$i, 'q' => min(self::MAX_QUANTITY, max(1, $quantity))] : $i);

        CartStore::put(self::KEY, $items->values()->all());
        $this->touch();
    }

    public function remove(string $key): void
    {
        CartStore::put(self::KEY, collect($this->raw())->reject(fn ($i) => $this->key($i['m'], $i['u']) === $key)->values()->all());
        $this->touch();
    }

    public function clear(): void
    {
        CartStore::forget(self::KEY);
        $this->touch();
    }

    /** A signed-in buyer's cart is noted (not copied) so a reminder can go if they walk away from it (spec ch 27, Phase 2). */
    private function touch(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $n = count($this->raw());

        if ($n === 0) {
            SavedCart::where('user_id', $user->id)->delete();

            return;
        }

        SavedCart::updateOrCreate(['user_id' => $user->id], ['items' => $n, 'touched_at' => now(), 'reminded_at' => null]);
    }

    /**
     * Tell a buyer once that they left things in their cart, a day after they last touched it.
     *
     * @return int how many were told
     */
    public function remind(int $hours = 24): int
    {
        $told = 0;

        SavedCart::whereNull('reminded_at')->where('touched_at', '<=', now()->subHours($hours))->with('user')->each(function (SavedCart $c) use (&$told) {
            if ($c->user === null) {
                return;
            }

            app(\App\Services\Messages\Messenger::class)->send($c->user, 'cart_left', ['name' => (string) $c->items], '/cart');
            $c->update(['reminded_at' => now()]);
            $told++;
        });

        return $told;
    }

    /**
     * The cart as the buyer sees it, at the printed price. A medicine that is no longer listed drops out quietly.
     *
     * @return Collection<int, array{key: string, medicine: Medicine, unit: string, quantity: int, unit_price: float, total: float}>
     */
    public function lines(): Collection
    {
        $items = collect($this->raw());
        $medicines = Medicine::query()->with('company:id,name')->where('is_active', true)->whereIn('id', $items->pluck('m'))->get()->keyBy('id');
        $probe = new Invoice(['kind' => 'retail', 'channel' => 'online']);

        $lines = $items->filter(fn ($i) => $medicines->has($i['m']) && in_array($i['u'], self::unitsFor($medicines[$i['m']]), true))->map(function ($i) use ($medicines, $probe) {
            $m = $medicines[$i['m']];
            $rate = $this->invoices->rate($probe, $m, $i['u'], null, (float) $i['q']);
            $price = $rate['price'];

            return [
                'key' => $this->key($i['m'], $i['u']), 'medicine' => $m, 'unit' => $i['u'], 'quantity' => $i['q'], 'unit_price' => $price, 'total' => round($price * $i['q'], 2),
                'mrp' => $rate['mrp'], 'step' => app(QuantitySteps::class)->hint($m, $i['u'], (float) $i['q'], $rate['mrp']),
            ];
        })->values();

        // Tidy the session if something dropped out.
        if ($lines->count() !== $items->count()) {
            CartStore::put(self::KEY, $lines->map(fn ($l) => ['m' => $l['medicine']->id, 'u' => $l['unit'], 'q' => $l['quantity']])->all());
        }

        return $lines;
    }

    public function subtotal(): float
    {
        return round((float) $this->lines()->sum('total'), 2);
    }
}
