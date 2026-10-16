import { iconFor } from '@/components/home/form-tiles';
import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { type MedicineSummary } from './types';
import { useMedicinePrice } from './use-medicine-price';

/** The pack photo on a soft stage, contained and never cropped. No photo (or a broken one): a fine line icon of the right kind of medicine. */
function Stage({ medicine }: { medicine: MedicineSummary }) {
    const [failed, setFailed] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const form = (medicine.form ?? '').toLowerCase().replace(/\s+/g, '-');

    return (
        <div className="relative aspect-[4/3] overflow-hidden bg-gradient-to-b from-white to-[var(--pt-sage)]">
            {!medicine.image_url || failed ? (
                <div aria-hidden className="flex size-full items-center justify-center text-[var(--pt-teal)]/70">
                    <span className="size-16 [&>svg]:size-full">{iconFor(form)}</span>
                </div>
            ) : (
                <>
                    {!loaded && <div aria-hidden className="absolute inset-0 animate-pulse bg-[var(--pt-line)]/40" />}
                    <img
                        src={medicine.image_url}
                        alt=""
                        width={320}
                        height={240}
                        loading="lazy"
                        decoding="async"
                        referrerPolicy="no-referrer"
                        onLoad={() => setLoaded(true)}
                        onError={() => setFailed(true)}
                        className={`size-full object-contain p-4 mix-blend-multiply transition duration-300 motion-safe:group-hover:scale-105 ${loaded ? 'opacity-100' : 'opacity-0'}`}
                    />
                </>
            )}
        </div>
    );
}

/** One medicine as a card: photo, name and strength, what it is and who makes it, any warnings, and the printed price. A whole-card link, equal height in a grid. */
export default function MedicineCard({ medicine, priceBasis = 'headline' }: { medicine: MedicineSummary; priceBasis?: 'headline' | 'piece' }) {
    const { t, money } = useI18n();
    const headline = useMedicinePrice();
    const price = priceBasis === 'piece' && medicine.mrp_piece ? { amount: money(medicine.mrp_piece), unit: t('per piece') } : headline(medicine);
    const flags = [medicine.prescription_required && t('Prescription required'), medicine.is_cold_chain && t('Keep refrigerated'), medicine.is_controlled && t('Controlled')].filter(Boolean) as string[];

    return (
        <li className="flex">
            <Link
                href={route('medicines.show', medicine.id)}
                className="group flex w-full flex-col overflow-hidden rounded-2xl border border-[var(--pt-line)] bg-[var(--pt-surface)] outline-none transition duration-200 hover:-translate-y-0.5 hover:border-[var(--pt-amber)] hover:shadow-[0_12px_32px_-12px_rgba(20,18,15,0.28)] focus-visible:ring-2 focus-visible:ring-[var(--pt-amber)] motion-reduce:transition-none motion-reduce:hover:translate-y-0"
            >
                <div className="relative">
                    <Stage medicine={medicine} />
                    {flags.length > 0 && (
                        <ul className="absolute top-2 left-2 flex max-w-[calc(100%-1rem)] flex-wrap gap-1">
                            {flags.map((f) => (
                                <li key={f} className="rounded-full bg-[var(--pt-deep)]/85 px-2 py-0.5 text-[11px] font-semibold text-white backdrop-blur">
                                    {f}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="flex flex-1 flex-col gap-1 p-3.5">
                    <h3 className="line-clamp-2 text-[15px] leading-snug">
                        <span className="font-semibold">{medicine.brand_name}</span> {medicine.strength && <span className="font-bold">{medicine.strength}</span>}
                    </h3>
                    <p className="line-clamp-1 text-[13px] text-[var(--pt-muted)]">{[medicine.form && t(medicine.form), medicine.generic_name].filter(Boolean).join(' · ')}</p>
                    <p className="line-clamp-1 text-xs text-[var(--pt-muted)]/90">{medicine.company?.name}</p>

                    {price && (
                        <div className="tabular mt-auto flex items-baseline justify-between gap-2 border-t border-dashed border-[var(--pt-line)] pt-3">
                            <span className="text-lg font-bold">{price.amount}</span>
                            <span className="text-xs text-[var(--pt-muted)]">{price.unit}</span>
                        </div>
                    )}
                </div>
            </Link>
        </li>
    );
}
