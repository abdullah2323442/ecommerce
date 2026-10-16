<?php

namespace App\Services\Sales;

use App\Models\Medicine;
use App\Models\QuantityStep;
use App\Services\Pricing\PriceRounding;
use Illuminate\Support\Collection;

/**
 * Quantity-based discount for the public price (spec ch 4): the same price for everyone, and the more you take the less each costs.
 * Steps are set per medicine and unit. The step with the highest quantity not above what is taken applies; a medicine
 * with no steps has no discount.
 */
class QuantitySteps
{
    /** @return Collection<int, QuantityStep> every step of the medicine, lowest quantity first */
    public function all(Medicine $medicine): Collection
    {
        return QuantityStep::where('medicine_id', $medicine->id)->orderBy('unit')->orderBy('min_qty')->get();
    }

    /** @return Collection<int, QuantityStep> */
    public function forUnit(Medicine $medicine, string $unit): Collection
    {
        return $this->all($medicine)->where('unit', $unit)->values();
    }

    public function stepFor(Medicine $medicine, string $unit, float $quantity): ?QuantityStep
    {
        return $this->forUnit($medicine, $unit)->filter(fn (QuantityStep $s) => $quantity >= $s->min_qty)->sortByDesc('min_qty')->first();
    }

    /** The price of one unit after the step. Never below zero. */
    public function apply(float $price, QuantityStep $step): float
    {
        $off = $step->kind === 'percent' ? $price * (float) $step->value / 100 : (float) $step->value;

        return PriceRounding::round(max(0.0, $price - $off));
    }

    /**
     * What to tell the buyer about a quantity: the discount they get now (percent off the printed price) and what the next step
     * would give, so "take 2 more for 10% off" can be said.
     *
     * @return array{percent: float, next: ?array{needs: int, percent: float}}
     */
    public function hint(Medicine $medicine, string $unit, float $quantity, float $mrp): array
    {
        $now = $this->stepFor($medicine, $unit, $quantity);
        $nextStep = $this->forUnit($medicine, $unit)->first(fn (QuantityStep $s) => $s->min_qty > $quantity);

        $percent = fn (?QuantityStep $s) => $s && $mrp > 0 ? round((1 - $this->apply($mrp, $s) / $mrp) * 100, 2) : 0.0;

        return [
            'percent' => $percent($now),
            'next' => $nextStep ? ['needs' => (int) ceil($nextStep->min_qty - $quantity), 'percent' => $percent($nextStep)] : null,
        ];
    }

    /**
     * The steps as a visitor sees them on the product page: from how many, and how much off a unit.
     *
     * @return list<array{unit: string, min_qty: int, percent: float, price: float}>
     */
    public function forPage(Medicine $medicine): array
    {
        return $this->all($medicine)->map(function (QuantityStep $s) use ($medicine) {
            $mrp = (float) match ($s->unit) {
                'box' => $medicine->mrp_box, 'strip' => $medicine->mrp_strip, default => $medicine->mrp_piece,
            };

            $price = $this->apply($mrp, $s);

            return ['unit' => $s->unit, 'min_qty' => $s->min_qty, 'percent' => $mrp > 0 ? round((1 - $price / $mrp) * 100, 2) : 0.0, 'price' => $price];
        })->values()->all();
    }
}
