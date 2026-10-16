<?php

namespace App\Services\Sales;

use App\Models\Medicine;
use App\Models\OfferProduct;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Pricing\PriceRounding;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Offer products (spec 9.1). Same stock, tagged. Only lots bought at the offer's discount or better count, the
 * offer is in whole boxes with a minimum, and an order can never be more than those boxes. When they run out the
 * listing stays for a while, greyed, then disappears; new qualifying stock inside the month brings it back.
 */
class OfferProducts
{
    public const VIEWERS = ['paikari', 'public', 'wholesale'];

    public function __construct(private StockService $stock) {}

    /** Lots that are on offer for this product right now (only the higher-discount lots, spec 9.1). */
    public function lotsFor(OfferProduct $offer): Collection
    {
        return StockLot::where('medicine_id', $offer->medicine_id)->where('units_remaining', '>', 0)
            ->where('purchase_percent', '>=', $offer->lot_percent)->get();
    }

    public function available(OfferProduct $offer): float
    {
        return round((float) $this->lotsFor($offer)->sum('units_remaining'), 4);
    }

    /** @return list<int> */
    public function lotIds(OfferProduct $offer): array
    {
        return $this->lotsFor($offer)->pluck('id')->all();
    }

    /**
     * live: can be ordered. sold_out: ran out a moment ago, shown greyed, cannot be ordered. gone: hidden.
     *
     * @return 'live'|'sold_out'|'gone'
     */
    public function state(OfferProduct $offer): string
    {
        if (! $offer->is_on || $offer->ends_on->lt(today())) {
            return 'gone';
        }

        if ($this->available($offer) >= 1) {
            if ($offer->soldout_at !== null) {
                $offer->forceFill(['soldout_at' => null])->save(); // new stock inside the month: back on offer
            }

            return 'live';
        }

        if ($offer->soldout_at === null) {
            $offer->forceFill(['soldout_at' => now()])->save();
        }

        $grey = (int) Setting::read('offers.grey_minutes', 90);

        return $offer->soldout_at->diffInMinutes(now()) <= $grey ? 'sold_out' : 'gone';
    }

    /** Offers a viewer may see, in the order staff want them. Staff at the counter see every offer. */
    public function visibleTo(string $viewer, bool $everything = false): Collection
    {
        return OfferProduct::with('medicine.company')->where('is_on', true)->whereDate('ends_on', '>=', today())->latest('id')->get()
            ->filter(fn (OfferProduct $o) => ($everything || in_array($viewer, (array) $o->visible_to, true)) && $this->state($o) !== 'gone')->values();
    }

    /** Price of one box for an order of $boxes: the offer rate plus the extra % for boxes above the minimum. */
    public function boxPrice(OfferProduct $offer, int $boxes, ?string $viewer = null): float
    {
        $extra = max(0, $boxes - (int) $offer->min_boxes) * (float) $offer->step_percent;
        $percent = min(95.0, $this->rateFor($offer, $viewer) + $extra);

        return PriceRounding::round((float) $offer->medicine->mrp_box * (1 - $percent / 100));
    }

    /** The offer rate for one group: its own rate when the admin set one, else the common rate (spec ch 9: each group at its own price). */
    public function rateFor(OfferProduct $offer, ?string $viewer): float
    {
        $own = $viewer !== null ? ((array) $offer->viewer_percent)[$viewer] ?? null : null;

        return $own !== null ? (float) $own : (float) $offer->percent;
    }

    public function start(Medicine $medicine, array $data, User $by): OfferProduct
    {
        if ($medicine->packaging_type !== 'box') {
            throw new StockException('Offers are in whole boxes, so only boxed medicines can go on offer.');
        }

        $percent = (float) $data['percent'];
        if ($percent <= 0 || $percent >= 100) {
            throw new StockException('An offer rate is between 0 and 100 percent.');
        }

        $best = StockLot::where('medicine_id', $medicine->id)->where('units_remaining', '>', 0)->orderByDesc('purchase_percent')->first();
        if (! $best) {
            throw new StockException('There is nothing in the shop to put on offer.');
        }

        // The cheapest lot on offer must still leave the minimum margin (setting offers.min_margin).
        $margin = (float) Setting::read('offers.min_margin', 3);
        $own = [];
        foreach (self::VIEWERS as $v) {
            $r = ((array) ($data['viewer_percent'] ?? []))[$v] ?? null;

            if ($r !== null && $r !== '') {
                if ((float) $r <= 0 || (float) $r >= 100) {
                    throw new StockException('An offer rate is between 0 and 100 percent.');
                }
                $own[$v] = (float) $r;
            }
        }

        // The deepest rate any group gets must still leave the margin.
        $price = (float) $medicine->mrp_box * (1 - max($percent, ...array_values($own ?: [0])) / 100);
        $cost = (float) StockLot::where('medicine_id', $medicine->id)->where('units_remaining', '>', 0)->where('purchase_percent', '>=', $best->purchase_percent)->min('unit_cost');
        if ($price < $cost * (1 + $margin / 100)) {
            throw new StockException('This rate leaves less than the minimum profit margin.');
        }

        $viewers = array_values(array_intersect(self::VIEWERS, (array) ($data['visible_to'] ?? ['paikari'])));
        if ($viewers === []) {
            throw new StockException('Choose who can see this offer.');
        }

        OfferProduct::where('medicine_id', $medicine->id)->where('is_on', true)->update(['is_on' => false]);

        return OfferProduct::create([
            'medicine_id' => $medicine->id, 'percent' => $percent, 'lot_percent' => $best->purchase_percent,
            'min_boxes' => max(1, (int) ($data['min_boxes'] ?? 1)), 'step_percent' => (float) ($data['step_percent'] ?? 0), 'visible_to' => $viewers,
            'viewer_percent' => array_intersect_key($own, array_flip($viewers)) ?: null,
            'stock_mode' => ($data['stock_mode'] ?? 'in_stock') === 'exact' ? 'exact' : 'in_stock',
            'weight_kg' => $data['weight_kg'] ?? null, 'delivery_charge' => $data['delivery_charge'] ?? null,
            'ends_on' => ($data['ends_on'] ?? null) ? Carbon::parse($data['ends_on'])->startOfDay() : now()->endOfMonth()->startOfDay(), 'created_by' => $by->id,
        ]);
    }

    /** Boxes left at which the admin is warned (setting offers.alert_boxes, default 2). */
    public function lowOnes(): Collection
    {
        $alert = (float) Setting::read('offers.alert_boxes', 2);

        return OfferProduct::with('medicine:id,brand_name,strength')->where('is_on', true)->whereDate('ends_on', '>=', today())->get()
            ->map(fn (OfferProduct $o) => ['offer' => $o, 'left' => $this->available($o)])
            ->filter(fn (array $r) => $r['left'] > 0 && $r['left'] <= $alert)->values();
    }
}
