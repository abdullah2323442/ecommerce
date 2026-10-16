import { iconFor } from '@/components/home/form-tiles';
import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { type MedicineSummary } from './types';
import { useMedicinePrice } from './use-medicine-price';

/**
 * Photo of the pack. Many medicines have no photo yet, so the fallback is a fine line icon of the right
 * kind of medicine (tablet, capsule, bottle, syringe, tube) instead of a blank tile with a letter.
 */
export function MedicineThumb({ medicine, className = 'size-16' }: { medicine: MedicineSummary; className?: string }) {
    const [failed, setFailed] = useState(false);
    const [loaded, setLoaded] = useState(false);

    if (!medicine.image_url || failed) {
        return (
            <div aria-hidden className={`${className} flex shrink-0 items-center justify-center overflow-hidden rounded-md border border-[var(--pt-line)] bg-[var(--pt-sage)] text-[var(--pt-teal)]`}>
                <span className="size-3/5 max-h-12 max-w-12 [&>svg]:size-full">{iconFor((medicine.form ?? '').toLowerCase().replace(/\s+/g, '-'))}</span>
            </div>
        );
    }

    return (
        <div className={`${className} relative shrink-0 overflow-hidden rounded-md border border-[var(--pt-line)] bg-white`}>
            {/* Skeleton shows until the image arrives, so slow remote images never leave a blank box. */}
            {!loaded && <div aria-hidden className="absolute inset-0 animate-pulse bg-[var(--pt-line)]/50" />}
            <img
                src={medicine.image_url}
                alt=""
                loading="lazy"
                decoding="async"
                referrerPolicy="no-referrer"
                onLoad={() => setLoaded(true)}
                onError={() => setFailed(true)}
                className={`size-full object-contain transition-opacity duration-200 ${loaded ? 'opacity-100' : 'opacity-0'}`}
            />
        </div>
    );
}

export function MedicineFlags({ medicine }: { medicine: MedicineSummary }) {
    const { t } = useI18n();
    const flags = [medicine.prescription_required && t('Prescription required'), medicine.is_cold_chain && t('Keep refrigerated'), medicine.is_controlled && t('Controlled')].filter(Boolean) as string[];

    if (flags.length === 0) return null;

    return (
        <ul className="mt-1 flex flex-wrap gap-1.5">
            {flags.map((flag) => (
                <li key={flag} className="rounded bg-[var(--pt-amber)]/20 px-1.5 py-0.5 text-xs font-medium text-[var(--pt-amber-ink)]">
                    {flag}
                </li>
            ))}
        </ul>
    );
}

interface RowProps {
    medicine: MedicineSummary;
    /** 'piece' shows the per-piece price, so brands with different pack sizes can be compared fairly. */
    priceBasis?: 'headline' | 'piece';
}

export default function MedicineRow({ medicine, priceBasis = 'headline' }: RowProps) {
    const { t, money } = useI18n();
    const headline = useMedicinePrice();
    const price = priceBasis === 'piece' && medicine.mrp_piece ? { amount: money(medicine.mrp_piece), unit: t('per piece') } : headline(medicine);

    return (
        <li>
            <Link
                href={route('medicines.show', medicine.id)}
                className="group relative flex items-center gap-4 border-b border-[var(--pt-line)] px-3 py-4 outline-none transition-colors before:absolute before:inset-y-2 before:left-0 before:w-1 before:origin-center before:scale-y-0 before:rounded-full before:bg-[var(--pt-amber)] before:transition-transform hover:bg-[var(--pt-surface)] hover:before:scale-y-100 focus-visible:bg-[var(--pt-surface)] focus-visible:ring-2 focus-visible:ring-[var(--pt-amber)] focus-visible:ring-inset motion-reduce:before:transition-none"
            >
                <MedicineThumb medicine={medicine} />

                <div className="min-w-0 flex-1">
                    <p className="truncate text-base leading-snug">
                        <span className="font-medium">{medicine.brand_name}</span> {medicine.strength && <strong className="font-bold">{medicine.strength}</strong>}
                    </p>
                    <p className="truncate text-sm text-[var(--pt-muted)]">{[medicine.form && t(medicine.form), medicine.generic_name].filter(Boolean).join(' · ')}</p>
                    <p className="truncate text-sm text-[var(--pt-muted)]">{medicine.company?.name}</p>
                    <MedicineFlags medicine={medicine} />
                </div>

                {price && (
                    <div className="tabular shrink-0 text-right">
                        <p className="text-base font-semibold">{price.amount}</p>
                        <p className="text-xs text-[var(--pt-muted)]">{price.unit}</p>
                    </div>
                )}
            </Link>
        </li>
    );
}
