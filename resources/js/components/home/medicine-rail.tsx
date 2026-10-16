import { MedicineThumb } from '@/components/medicines/medicine-row';
import { type MedicineSummary } from '@/components/medicines/types';
import { useMedicinePrice } from '@/components/medicines/use-medicine-price';
import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

/**
 * A shelf of real packs: photo, name with the strength in bold (similar names that differ only by strength
 * are a safety risk), company, and the printed price. A swipeable row on phones, a grid on desktop.
 */
export default function MedicineRail({ medicines }: { medicines: MedicineSummary[] }) {
    const { t, isBn } = useI18n();
    const priceOf = useMedicinePrice();

    return (
        <ul className="-mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-3 sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0 lg:grid-cols-5">
            {medicines.map((m) => {
                const price = priceOf(m);

                return (
                    <li key={m.id} className="w-44 shrink-0 snap-start sm:w-auto">
                        <Link
                            href={route('medicines.show', m.id)}
                            className="group flex h-full flex-col overflow-hidden rounded-3xl border border-[var(--pt-line)] bg-[var(--pt-surface)] transition-[box-shadow,border-color] duration-150 outline-none hover:border-[var(--pt-amber)] hover:shadow-xl focus-visible:ring-4 focus-visible:ring-[var(--pt-amber)]/40"
                        >
                            <div className="bg-white p-3">
                                <MedicineThumb medicine={m} className="aspect-square w-full !rounded-2xl !border-0" />
                            </div>
                            <div className="flex flex-1 flex-col gap-0.5 border-t border-[var(--pt-line)] p-4">
                                <p className="min-h-[3.1rem] leading-snug">
                                    <span className="font-semibold">{m.brand_name}</span> <strong className="font-extrabold">{m.strength}</strong>
                                </p>
                                <p className="truncate text-sm text-[var(--pt-muted)]">{m.company?.name}</p>
                                <p className="truncate text-sm text-[var(--pt-muted)]">{m.form && t(m.form)}</p>
                                {price && (
                                    <p lang={isBn ? 'bn' : 'en'} className="tabular font-display mt-2 text-xl font-bold">
                                        {price.amount}
                                        <span className="ml-1 text-sm font-normal text-[var(--pt-muted)]">{price.unit}</span>
                                    </p>
                                )}
                            </div>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
