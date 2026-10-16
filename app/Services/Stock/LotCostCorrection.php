<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\MedicineRate;
use App\Models\OfferProduct;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Correct the purchase price of one lot (admin only). The price stays locked in taka after this; only the admin can change it, and
 * the profit record of goods already sold from the lot is worked out again. If the new cost puts a live offer or a fixed rate at a
 * loss, the admin is told first and must say yes (spec 9, "This offer is now at a loss").
 */
class LotCostCorrection
{
    /** What the lot would cost at this total percent off MRP, and what would be at a loss because of it. */
    public function preview(StockLot $lot, float $percent): array
    {
        $cost = round((float) $lot->mrp_unit_at_purchase * (1 - $percent / 100), 4);
        $medicine = $lot->medicine;
        $unitMrp = StockService::unitMrp($medicine);

        $offers = OfferProduct::where('medicine_id', $medicine->id)->where('is_on', true)->whereDate('ends_on', '>=', today())
            ->get()->filter(fn (OfferProduct $o) => $unitMrp * (1 - (float) $o->percent / 100) < $cost)->count();

        $rates = MedicineRate::where('medicine_id', $medicine->id)->whereNotNull('discount_percent')
            ->get()->filter(fn (MedicineRate $r) => $unitMrp * (1 - (float) $r->discount_percent / 100) < $cost)->count();

        $sold = StockMovement::where('stock_lot_id', $lot->id)->where('type', 'sale')->where('unit_price', '<', $cost)->count();

        return ['unit_cost' => $cost, 'offers_at_loss' => $offers, 'rates_at_loss' => $rates, 'sales_at_loss' => $sold, 'loss' => ($offers + $rates + $sold) > 0];
    }

    /** @throws StockException */
    public function apply(StockLot $lot, float $percent, User $by, bool $confirmLoss): array
    {
        if ($by->account_type !== AccountType::Admin) {
            throw StockException::adminOnly();
        }

        if ($percent < 0 || $percent >= 100 || (float) $lot->mrp_unit_at_purchase <= 0) {
            throw new StockException('Enter a percent between 0 and 99.9.');
        }

        $preview = $this->preview($lot, $percent);

        if ($preview['loss'] && ! $confirmLoss) {
            throw new StockException('At this cost something is sold at a loss. Confirm to go on.');
        }

        DB::transaction(function () use ($lot, $percent, $preview) {
            $lot->update(['unit_cost' => $preview['unit_cost'], 'purchase_percent' => round($percent, 3)]);

            // Goods already sold from this lot: the cost and the loss mark are worked out again.
            StockMovement::where('stock_lot_id', $lot->id)->where('type', 'sale')->get()->each(function (StockMovement $m) use ($preview) {
                $m->update(['unit_cost' => $preview['unit_cost'], 'is_loss' => (float) $m->unit_price < $preview['unit_cost']]);
            });
        });

        return $preview;
    }
}
