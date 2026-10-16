import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export interface Comparison {
    label: string;
    form: string;
    brands_total: number;
    min: number;
    max: number;
    spread: number | null;
    items: { id: number; brand: string; company: string | null; price: number }[];
}

/**
 * Price comparison: what each brand charges for one piece of the same medicine. Same generic, strength and
 * form only, and it states the gap as a fact, never advice about switching.
 */
export default function PriceCompare({ comparisons }: { comparisons: Comparison[] }) {
    const { t, num, decimal, money } = useI18n();
    const [index, setIndex] = useState(0);
    const [grown, setGrown] = useState(false);
    const current = comparisons[index];

    // Restart the bars whenever the medicine changes.
    useEffect(() => {
        setGrown(false);
        let second = 0;
        const first = requestAnimationFrame(() => {
            second = requestAnimationFrame(() => setGrown(true));
        });
        return () => {
            cancelAnimationFrame(first);
            cancelAnimationFrame(second);
        };
    }, [index]);

    if (!current) return null;

    return (
        <div>
            <div className="overflow-hidden rounded-3xl border border-[var(--pt-line)] bg-[var(--pt-surface)] shadow-xl">
                <div
                    role="tablist"
                    aria-label={t('Choose a medicine to compare')}
                    className="flex gap-2 overflow-x-auto border-b border-[var(--pt-line)] bg-[var(--pt-paper)] px-4 py-3 sm:flex-wrap sm:px-6"
                >
                    {comparisons.map((c, i) => (
                        <button
                            key={c.label}
                            role="tab"
                            id={`cmp-tab-${i}`}
                            aria-selected={i === index}
                            aria-controls="cmp-panel"
                            onClick={() => setIndex(i)}
                            className={`min-h-10 shrink-0 cursor-pointer rounded-full px-4 py-1.5 text-sm font-semibold transition-colors outline-none focus-visible:ring-4 focus-visible:ring-[var(--pt-amber)]/50 ${
                                i === index
                                    ? 'bg-[var(--pt-ink)] text-[var(--pt-paper)]'
                                    : 'border border-[var(--pt-line)] bg-[var(--pt-surface)] hover:border-[var(--pt-ink)]'
                            }`}
                        >
                            {c.label}
                        </button>
                    ))}
                </div>

                <div id="cmp-panel" role="tabpanel" aria-labelledby={`cmp-tab-${index}`} className="grid gap-8 p-5 sm:p-8 lg:grid-cols-5">
                    <div className="lg:col-span-2">
                        {current.spread && current.spread > 1 ? (
                            <>
                                <p className="tabular font-display text-8xl leading-none font-extrabold tracking-tight text-[var(--pt-ink)] sm:text-9xl">
                                    {decimal(current.spread)}
                                    <span className="text-[var(--pt-amber)]">×</span>
                                </p>
                                <p className="font-display mt-4 max-w-xs text-xl leading-snug font-semibold">
                                    {t('from the cheapest to the dearest {label} {form}.', { label: current.label, form: t(current.form) })}
                                </p>
                            </>
                        ) : (
                            <p className="font-display text-3xl leading-tight font-bold">
                                {t('Prices are very close for {label}.', { label: current.label })}
                            </p>
                        )}
                        <p className="mt-4 max-w-xs text-sm leading-relaxed text-[var(--pt-muted)]">
                            {t('{n} brands, one per company.', { n: num(current.brands_total) })}{' '}
                            {current.items.length === current.brands_total ? t('All shown.') : t('Cheapest four and dearest three shown.')}{' '}
                            {t('Same active ingredient, strength and form. Ask your pharmacist or doctor before switching brands.')}
                        </p>
                        <Link
                            href={route('medicines.index', { q: current.label })}
                            className="mt-4 inline-block text-sm font-semibold text-[var(--pt-ink)] underline underline-offset-4"
                        >
                            {t('See all {n} brands', { n: num(current.brands_total) })}
                        </Link>
                    </div>

                    <ul className="space-y-1 lg:col-span-3">
                        {current.items.map((item, i) => {
                            const pct = Math.max(7, (item.price / current.max) * 100);
                            // Mark by price, not position, so brands that tie for lowest or highest are all marked.
                            const isLow = item.price === current.min;
                            const isHigh = item.price === current.max && current.max !== current.min;

                            return (
                                <li key={item.id}>
                                    <Link
                                        href={route('medicines.show', item.id)}
                                        className="block rounded-xl px-3 py-2.5 transition-colors outline-none hover:bg-[var(--pt-paper)] focus-visible:ring-2 focus-visible:ring-[var(--pt-amber)]"
                                    >
                                        <span className="flex items-baseline justify-between gap-3">
                                            <span className="min-w-0 truncate">
                                                <span className="font-display text-lg font-bold">{item.brand}</span>{' '}
                                                <span className="text-sm text-[var(--pt-muted)]">{item.company}</span>
                                            </span>
                                            <span className="tabular font-display shrink-0 text-xl font-bold">{money(item.price)}</span>
                                        </span>
                                        <span className="mt-1.5 flex items-center gap-3">
                                            <span className="h-2.5 flex-1 rounded-full bg-[var(--pt-paper)]">
                                                <span
                                                    className={`block h-full rounded-full transition-[width] duration-700 ease-out motion-reduce:transition-none ${isLow ? 'bg-[#1f9a98]' : isHigh ? 'bg-[var(--pt-ink)]' : 'bg-[var(--pt-muted)]/45'}`}
                                                    style={{ width: grown ? `${pct}%` : '0%', transitionDelay: grown ? `${i * 70}ms` : '0ms' }}
                                                />
                                            </span>
                                            <span className={`w-16 shrink-0 text-xs font-bold ${isLow ? 'text-[#0f6f6f] dark:text-[#5fd0cd]' : ''}`}>
                                                {isLow ? t('Lowest') : isHigh ? t('Highest') : ''}
                                            </span>
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </div>
            <p className="mt-3 text-xs text-[var(--pt-muted)]">{t('Price per piece, calculated from the printed MRP.')}</p>
        </div>
    );
}
