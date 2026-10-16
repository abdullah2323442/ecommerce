/** The public shape of a medicine (App\Support\MedicinePresenter). MRP only: never stock, never purchase price. */
export interface MedicineSummary {
    id: number;
    brand_name: string;
    generic_name: string | null;
    strength: string | null;
    form: string | null;
    volume: string | null;
    company: { id: number; name: string; logo: string | null } | null;
    packaging_type: 'box' | 'pcs';
    strips_per_box: number | null;
    pieces_per_strip: number | null;
    mrp_piece: string | null;
    mrp_strip: string | null;
    mrp_box: string | null;
    /** Null when there is no photo, or when photos are switched off in config/catalog.php. */
    image_url: string | null;
    prescription_required: boolean;
    is_cold_chain: boolean;
    is_controlled: boolean;
}

export type Unit = 'box' | 'strip' | 'piece';
