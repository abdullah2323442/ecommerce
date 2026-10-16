<?php

namespace App\Support;

use App\Models\Medicine;

/**
 * The one public shape of a medicine: MRP only. No stock, no purchase price, no legacy ids.
 * Every public page and API goes through this, so a private field can never leak by accident.
 */
final class MedicinePresenter
{
    public static function one(Medicine $m): array
    {
        return [
            'id' => $m->id,
            'brand_name' => $m->brand_name,
            'generic_name' => $m->generic_name,
            'strength' => $m->strength,
            'form' => $m->form,
            'volume' => $m->volume,
            'company' => $m->company ? ['id' => $m->company->id, 'name' => $m->company->name, 'logo' => $m->company->logo] : null,
            'packaging_type' => $m->packaging_type,
            'strips_per_box' => $m->strips_per_box,
            'pieces_per_strip' => $m->pieces_per_strip,
            'mrp_piece' => $m->mrp_piece,
            'mrp_strip' => $m->mrp_strip,
            'mrp_box' => $m->mrp_box,
            // Off switch for the MedEx-watermarked photos (config/catalog.php): no URL leaves the server.
            'image_url' => config('catalog.medicine_photos') ? $m->image_url : null,
            'prescription_required' => $m->prescription_required,
            'is_cold_chain' => $m->is_cold_chain,
            'is_controlled' => $m->is_controlled,
        ];
    }

    /** @param  iterable<Medicine>  $medicines */
    public static function many(iterable $medicines): array
    {
        $rows = [];
        foreach ($medicines as $m) {
            $rows[] = self::one($m);
        }

        return $rows;
    }
}
