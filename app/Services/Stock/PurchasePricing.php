<?php

namespace App\Services\Stock;

use App\Models\CompanyPurchaseRate;
use App\Models\Medicine;
use App\Models\MedicinePurchaseRate;
use App\Models\StockSource;

/**
 * Purchase-price arithmetic (spec STK-2 to STK-4). Pure maths plus the three-layer rate lookup.
 *
 * Discounts cascade, they are never added: MRP 100, 12% then 10% of the result is 79.20, a total of 20.8%.
 * A bonus ("10+2") is turned into a true price per box received, and shown as a percent, never as "10+2".
 */
final class PurchasePricing
{
    /** Total % off MRP for discounts applied one after another. cascade(12, 10) = 20.8. */
    public static function cascade(float ...$percents): float
    {
        $remaining = 1.0;
        foreach ($percents as $percent) {
            $remaining *= 1 - $percent / 100;
        }

        return round((1 - $remaining) * 100, 3);
    }

    /**
     * One purchase line.
     *
     * @param  float  $mrpUnit  MRP of one unit (box, or piece for loose items)
     * @param  list<float>  $percents  discounts in order, each taken off the result of the one before
     * @return array{unit_cost: float, percent: float, received: float, total_paid: float}
     */
    public static function line(float $mrpUnit, array $percents, float $paid, float $bonus = 0): array
    {
        $received = $paid + $bonus;
        $perPaidUnit = $mrpUnit * (1 - self::cascade(...$percents) / 100);
        $totalPaid = $paid * $perPaidUnit;
        $unitCost = $received > 0 ? $totalPaid / $received : 0.0;

        return [
            'unit_cost' => round($unitCost, 4),
            'percent' => $mrpUnit > 0 ? round((1 - $unitCost / $mrpUnit) * 100, 3) : 0.0,
            'received' => round($received, 4),
            'total_paid' => round($totalPaid, 2),
        ];
    }

    /** STK-2: product rate, then company rate, then the source default. The first one set wins. */
    public function resolvePercent(StockSource $source, Medicine $medicine): ?float
    {
        $product = MedicinePurchaseRate::where('medicine_id', $medicine->id)->value('percent');
        if ($product !== null) {
            return (float) $product;
        }

        $company = $medicine->company_id ? CompanyPurchaseRate::where('company_id', $medicine->company_id)->value('percent') : null;
        if ($company !== null) {
            return (float) $company;
        }

        return $source->default_percent !== null ? (float) $source->default_percent : null;
    }
}
