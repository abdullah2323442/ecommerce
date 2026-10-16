import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** The seller's own business name over their screens, so the dashboard feels like theirs (spec ch 14: white-label). */
export default function SellerBrand() {
    const { sellerBrand } = usePage<SharedData>().props;

    if (!sellerBrand) {
        return null;
    }

    return <p className="text-sm font-semibold tracking-wide text-[var(--pt-muted)]">{sellerBrand}</p>;
}
