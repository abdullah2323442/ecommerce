<?php

namespace App\Services\Sales;

use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\TierOffer;
use App\Models\User;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tier offers (spec 9.2). An offer is a temporary rate on one medicine for every paikari buyer. It is live only
 * while it is switched on, inside its dates and with stock in the shop; the moment stock hits zero it stops and
 * comes back with new stock inside the month. Nothing goes on offer without the admin saying so.
 */
class TierOffers
{
    public function __construct(private StockService $stock) {}

    /** The live offer on a medicine, if any. Never reveals stock: callers only ever see the percent. */
    public function liveFor(Medicine $medicine, ?Carbon $on = null): ?TierOffer
    {
        $day = ($on ?? now())->toDateString();

        $offer = TierOffer::where('medicine_id', $medicine->id)->where('is_on', true)
            ->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->latest('id')->first();

        return $offer && $this->stock->onHand($medicine) > 0 ? $offer : null;
    }

    /**
     * Put a medicine on offer. The selling rate must still leave the minimum profit margin over the cheapest lot
     * in the shop (setting `offers.min_margin`, default 3%).
     */
    public function start(Medicine $medicine, float $percent, User $by, ?Carbon $endsOn = null): TierOffer
    {
        if ($percent <= 0 || $percent >= 100) {
            throw new StockException('An offer rate is between 0 and 100 percent.');
        }

        $this->guardMargin($medicine, $percent);

        $end = ($endsOn ?? now()->endOfMonth())->copy()->startOfDay();

        if ($end->lt(today())) {
            throw new StockException('The offer cannot end before today.');
        }

        return DB::transaction(function () use ($medicine, $percent, $by, $end) {
            // A medicine has one offer at a time: a new one replaces the old.
            TierOffer::where('medicine_id', $medicine->id)->where('is_on', true)->update(['is_on' => false, 'decided_at' => now()]);

            return TierOffer::create(['medicine_id' => $medicine->id, 'percent' => $percent, 'starts_on' => today(), 'ends_on' => $end, 'created_by' => $by->id]);
        });
    }

    public function turnOff(TierOffer $offer): void
    {
        $offer->update(['is_on' => false, 'decided_at' => now()]);
    }

    /** After the month: the admin chooses to run it again for this month, or leaves it off. */
    public function renew(TierOffer $offer, User $by): TierOffer
    {
        $offer->update(['decided_at' => now(), 'is_on' => false]);

        return $this->start($offer->medicine, (float) $offer->percent, $by);
    }

    public function leaveOff(TierOffer $offer): void
    {
        $offer->update(['decided_at' => now(), 'is_on' => false]);
    }

    /** @return Collection<int, TierOffer> offers past their end date still waiting for the admin's answer */
    public function awaitingDecision()
    {
        return TierOffer::with('medicine:id,brand_name,strength')->where('is_on', true)->whereNull('decided_at')->whereDate('ends_on', '<', today())->get();
    }

    /**
     * Lots bought at a high discount that are still on the shelf (the "ask me" path of spec 9.1/9.2). The admin sees
     * the percent the goods were bought at, never "10+2".
     *
     * @return list<array{medicine_id: int, name: string, strength: ?string, purchase_percent: float, boxes: float, suggested: float}>
     */
    public function suggestions(): array
    {
        $threshold = (float) Setting::read('offers.notify_percent', 20);
        $margin = (float) Setting::read('offers.min_margin', 3);

        $live = TierOffer::where('is_on', true)->whereDate('ends_on', '>=', today())->pluck('medicine_id')->all();

        return StockLot::query()->join('medicines', 'medicines.id', '=', 'stock_lots.medicine_id')
            ->where('stock_lots.units_remaining', '>', 0)->where('stock_lots.purchase_percent', '>=', $threshold)
            ->whereNotIn('stock_lots.medicine_id', $live)->orderByDesc('stock_lots.purchase_percent')->limit(30)
            ->get(['medicines.id as medicine_id', 'medicines.brand_name', 'medicines.strength', 'medicines.mrp_box', 'medicines.mrp_piece', 'medicines.packaging_type',
                'stock_lots.purchase_percent', 'stock_lots.units_remaining', 'stock_lots.unit_cost'])
            ->map(function ($r) use ($margin) {
                $mrp = (float) ($r->packaging_type === 'box' ? $r->mrp_box : $r->mrp_piece);
                $floor = $mrp > 0 ? (1 - ((float) $r->unit_cost * (1 + $margin / 100)) / $mrp) * 100 : 0; // the deepest discount that keeps the margin

                return ['medicine_id' => $r->medicine_id, 'name' => $r->brand_name, 'strength' => $r->strength, 'purchase_percent' => (float) $r->purchase_percent,
                    'boxes' => (float) $r->units_remaining, 'suggested' => round(max(0, floor(max(0, $floor) * 2) / 2), 1)];
            })->all();
    }

    private function guardMargin(Medicine $medicine, float $percent): void
    {
        $margin = (float) Setting::read('offers.min_margin', 3);
        $mrp = StockService::unitMrp($medicine);
        $cheapest = StockLot::where('medicine_id', $medicine->id)->where('units_remaining', '>', 0)->min('unit_cost');

        if ($cheapest === null) {
            throw new StockException('There is nothing in the shop to put on offer.');
        }

        if ($mrp * (1 - $percent / 100) < (float) $cheapest * (1 + $margin / 100)) {
            throw new StockException('This rate leaves less than the minimum profit margin.');
        }
    }
}
