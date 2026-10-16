<?php

namespace App\Services\Medicines;

use App\Models\Medicine;

/**
 * Builds the "same medicine, different prices" data: for one generic, strength and form, the
 * per-piece MRP of several brands from different companies, cheapest to dearest.
 *
 * It compares only identical generic + strength + form, and it states the spread, never advice
 * about switching: that is a pharmacist's or doctor's call.
 */
class PriceComparison
{
    /** Generics with many brands and a visible price spread. Each must still pass the data checks. */
    public const FEATURED = [
        ['label' => 'Azithromycin 500 mg', 'generic' => 'Azithromycin', 'strength' => '500 mg', 'form' => 'tablet'],
        ['label' => 'Esomeprazole 20 mg', 'generic' => 'Esomeprazole', 'strength' => '20 mg', 'form' => 'tablet'],
        ['label' => 'Omeprazole 20 mg', 'generic' => 'Omeprazole', 'strength' => '20 mg', 'form' => 'capsule'],
        ['label' => 'Cefixime 200 mg', 'generic' => 'Cefixime', 'strength' => '200 mg', 'form' => 'capsule'],
        ['label' => 'Paracetamol 500 mg', 'generic' => 'Paracetamol', 'strength' => '500 mg', 'form' => 'tablet'],
    ];

    private const MIN_BRANDS = 6;

    /** @return list<array<string, mixed>> */
    public function featured(): array
    {
        $out = [];
        foreach (self::FEATURED as $spec) {
            $comparison = $this->for($spec['generic'], $spec['strength'], $spec['form'], $spec['label']);
            if ($comparison) {
                $out[] = $comparison;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null null when there are too few brands to compare */
    public function for(string $generic, string $strength, string $form, ?string $label = null): ?array
    {
        $rows = Medicine::query()->active()
            ->where('generic_name', 'like', addcslashes($generic, '%_\\').'%')
            ->where('strength', $strength)
            ->where('form', $form)
            ->where('mrp_piece', '>', 0)
            ->with('company:id,name')
            ->orderBy('mrp_piece')
            ->get()
            ->unique('company_id') // one brand per company, the cheapest of theirs
            ->values();

        if ($rows->count() < self::MIN_BRANDS) {
            return null;
        }

        // Show the whole range honestly: the cheapest four and the dearest three.
        $picked = $rows->take(4)->merge($rows->slice(-3))->unique('id')->values();
        $min = (float) $rows->first()->mrp_piece;
        $max = (float) $rows->last()->mrp_piece;

        return [
            'label' => $label ?? trim("{$generic} {$strength}"),
            'form' => $form,
            'brands_total' => $rows->count(),
            'min' => $min,
            'max' => $max,
            'spread' => $min > 0 ? round($max / $min, 1) : null,
            'items' => $picked->map(fn (Medicine $m) => [
                'id' => $m->id,
                'brand' => $m->brand_name,
                'company' => $m->company?->name,
                'price' => (float) $m->mrp_piece,
            ])->all(),
        ];
    }
}
