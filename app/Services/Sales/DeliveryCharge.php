<?php

namespace App\Services\Sales;

use App\Models\Courier;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The delivery charge (spec ch 20), worked out in three layers, highest first:
 *   1. a mandatory charge a product carries (one price inside the shop's district, another outside) wins over everything;
 *   2. when every product has a weight and couriers are set up, each courier's charge by weight: the first kilo plus each kilo
 *      after it, by the zone of the address, plus the courier's cash-on-delivery share. The buyer picks one;
 *   3. otherwise the area-based flat charge: one price inside the shop's own district and another outside.
 * Free over a set amount applies to layers 2 and 3, and nothing is charged for collecting from the shop. Staff can still change
 * the charge on the order (the delivery charge draft). Charges are typed by hand; there is no courier integration.
 */
class DeliveryCharge
{
    public const ROUNDING = ['up_kg' => 'Round up to a whole kilo', 'up_half' => 'Round up to half a kilo', 'exact' => 'Exact weight'];

    public function shopDistrict(): string
    {
        return (string) Setting::read('delivery.shop_district', 'Dhaka');
    }

    public function shopArea(): string
    {
        return (string) Setting::read('delivery.shop_area', '');
    }

    public function inside(): float
    {
        return (float) Setting::read('delivery.charge_inside', 60);
    }

    public function outside(): float
    {
        return (float) Setting::read('delivery.charge_outside', 120);
    }

    /** The amount past which delivery is free: the one set for this shop when there is one, else the site-wide amount. */
    public function freeOver(?User $shop = null): float
    {
        if ($shop && $shop->free_delivery_over !== null) {
            return (float) $shop->free_delivery_over;
        }

        return (float) Setting::read('delivery.free_over', 0);
    }

    /** Collecting from the shop needs a shop address to send people to. */
    public function pickupAvailable(): bool
    {
        return filled(config('brand.address'));
    }

    /** own (the shop's own area), district (same district) or outside. */
    public function zone(?string $district, ?string $area): string
    {
        if ($district === null || mb_strtolower($district) !== mb_strtolower($this->shopDistrict())) {
            return 'outside';
        }

        $own = mb_strtolower(trim($this->shopArea()));

        return $own !== '' && mb_strtolower(trim((string) $area)) === $own ? 'own' : 'district';
    }

    /**
     * What this order would cost to send, and how that was decided.
     *
     * @param  Collection<int, array{medicine: Medicine, unit: string, quantity: float|int}>  $items
     * @return array{source: string, weight_kg: ?float, zone: string, cold: bool, options: list<array{courier_id: ?int, name: string, charge: float}>}
     */
    public function quote(Collection $items, string $mode, ?string $district, ?string $area, float $subtotal): array
    {
        $zone = $this->zone($district, $area);
        // A medicine that must stay cold never goes by courier: only our own delivery or pickup (spec ch 11).
        $cold = $items->contains(fn ($i) => (bool) $i['medicine']->is_cold_chain);
        $none = fn (string $source, float $charge, string $name = 'Delivery', ?float $kg = null) => [
            'source' => $source, 'weight_kg' => $kg, 'zone' => $zone, 'cold' => $cold, 'options' => [['courier_id' => null, 'name' => $name, 'charge' => round($charge, 2)]],
        ];

        if ($mode !== 'delivery') {
            return $none('none', 0.0);
        }

        // Delivery zones (spec ch 20): outside the shop's district can be switched off; the buyer can still collect from the shop.
        if ($zone === 'outside' && ! Setting::read('delivery.outside_enabled', true)) {
            return ['source' => 'closed', 'weight_kg' => null, 'zone' => $zone, 'cold' => $cold, 'options' => []];
        }

        // Beyond our own delivery area only a courier could carry it, and it cannot go by courier.
        if ($cold && $zone === 'outside') {
            return ['source' => 'blocked', 'weight_kg' => null, 'zone' => $zone, 'cold' => true, 'options' => []];
        }

        // 1. A mandatory charge on a product is the highest and wins.
        $fixed = $items->map(fn ($i) => $zone === 'outside' ? $i['medicine']->delivery_fixed_out : $i['medicine']->delivery_fixed_in)->filter(fn ($v) => $v !== null);
        if ($fixed->isNotEmpty()) {
            return $none('fixed', (float) $fixed->max());
        }

        $free = $this->freeOver();
        if ($free > 0 && $subtotal >= $free) {
            return $none('free', 0.0);
        }

        // 2. By weight, when every product has one and a courier can carry it to this zone.
        $weighed = $items->every(fn ($i) => (int) $i['medicine']->weight_g > 0);
        if (! $cold && $weighed && $items->isNotEmpty()) {
            $kg = round($items->sum(fn ($i) => (int) $i['medicine']->weight_g * $i['quantity'] * InvoiceService::fraction($i['medicine'], $i['unit'])) / 1000, 3);
            $billable = $this->billable($kg);

            $options = Courier::where('is_active', true)->with('rates')->orderBy('name')->get()->map(function (Courier $c) use ($zone, $billable, $subtotal) {
                $rate = $c->rates->firstWhere('zone', $zone);
                if (! $rate) {
                    return null;
                }

                $charge = (float) $rate->first_kg + max(0.0, $billable - 1) * (float) $rate->extra_kg + $subtotal * (float) $c->cod_percent / 100;

                return ['courier_id' => $c->id, 'name' => $c->name, 'charge' => round($charge, 2)];
            })->filter()->sortBy('charge')->values()->all();

            if ($options) {
                return ['source' => 'courier', 'weight_kg' => $kg, 'zone' => $zone, 'cold' => $cold, 'options' => $options];
            }
        }

        // 3. The area-based flat charge: one price inside the district, another outside it.
        return $none('flat', $zone === 'outside' ? $this->outside() : $this->inside());
    }

    /** The weight a courier bills for: couriers round up, and the rule is a setting (spec ch 20 dev note). */
    public function billable(float $kg): float
    {
        return match ((string) Setting::read('delivery.round_mode', 'up_kg')) {
            'exact' => max(1.0, $kg),
            'up_half' => max(1.0, ceil($kg * 2) / 2),
            default => max(1.0, (float) ceil($kg)),
        };
    }

    /** Pick the option the buyer chose; with no choice, the cheapest. Null when the choice is not one of the options. */
    public function choose(array $quote, ?int $courierId): ?array
    {
        if ($courierId === null) {
            return $quote['options'][0] ?? null;
        }

        return collect($quote['options'])->firstWhere('courier_id', $courierId);
    }

    /** Old callers: the flat charge only. */
    public function charge(string $mode, ?string $district, float $subtotal): float
    {
        if ($mode !== 'delivery') {
            return 0.0;
        }

        $free = $this->freeOver();
        if ($free > 0 && $subtotal >= $free) {
            return 0.0;
        }

        return $this->zone($district, null) === 'outside' ? $this->outside() : $this->inside();
    }
}
