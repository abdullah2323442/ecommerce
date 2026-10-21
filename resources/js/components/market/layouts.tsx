import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { Check, ShieldCheck, Truck } from 'lucide-react';
import { type ReactNode } from 'react';

export interface ShelfItem {
    id: number;
    title: string;
    price: number;
    unit: string;
    photo: string | null;
    seller: string;
    blurb?: string;
    warranty?: number | null;
    cod?: boolean;
    category?: string | null;
    stars?: { average: number; count: number } | null;
    trusted?: boolean;
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

/** The sentences of a description, as the short spec lines a tech shop shows under a name. */
const specLines = (text: string | undefined, max = 3) =>
    (text ?? '')
        .split(/(?<=[.!?])\s+/)
        .map((x) => x.replace(/[.!?]+$/, '').trim())
        .filter((x) => x.length > 3)
        .slice(0, max);

/* ---------------------------------------------------------------- electronics: a spec shop, in the manner of Star Tech */

/** The top of the electronics page: a navy bar with the title and count, and the promises a tech buyer looks for. */
export function TechTop({ title, line, count, image }: { title: string; line: string; count: number; image?: string | null }) {
    const { t, num } = useI18n();

    return (
        <section
            aria-labelledby="shelf-title"
            className="tech relative isolate -mx-4 overflow-hidden bg-[var(--t-navy)] px-5 py-10 text-white sm:mx-0 sm:rounded-2xl sm:px-10 sm:py-14"
        >
            {image && (
                <>
                    <img
                        src={image}
                        alt=""
                        fetchPriority="high"
                        decoding="async"
                        className="absolute inset-y-0 right-0 -z-20 h-full w-full object-cover sm:w-3/4"
                    />
                    <span
                        aria-hidden
                        className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--t-navy)] via-[var(--t-navy)]/80 to-transparent"
                    />
                </>
            )}
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 id="shelf-title" style={DISPLAY} className="text-4xl leading-none sm:text-6xl">
                        {title}
                    </h1>
                    <p className="mt-2 max-w-lg text-white/75">{line}</p>
                </div>
                <p className="tabular rounded-full bg-[var(--t-orange)] px-4 py-1.5 text-sm font-semibold text-[var(--t-navy)]">
                    {t('{n} products', { n: num(count) })}
                </p>
            </div>
            <ul className="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/80">
                {[
                    [ShieldCheck, t('Warranty shown on each product')],
                    [Truck, t('Delivery across Bangladesh')],
                    [Check, t('Price is the price you pay')],
                ].map(([Icon, label]) => {
                    const I = Icon as typeof ShieldCheck;

                    return (
                        <li key={label as string} className="flex items-center gap-2">
                            <I aria-hidden className="size-4 text-[var(--t-orange)]" strokeWidth={2} />
                            {label as string}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** Spec cards: a square photo on white, the name, three spec lines, the price large, and Buy now. */
export function TechGrid({ items, render, badge }: { items: ShelfItem[]; render?: (i: ShelfItem) => ReactNode; badge?: string }) {
    const { t, money, num } = useI18n();

    return (
        <ul className="tech grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {items.map((i) => (
                <li
                    key={i.id}
                    className="relative flex flex-col rounded-xl border border-[var(--t-line)] bg-white p-3 text-[var(--t-ink)] transition-shadow duration-300 hover:shadow-lg"
                >
                    {badge && (
                        <span className="absolute top-5 left-5 z-10 rounded-full bg-[var(--t-navy)] px-2.5 py-0.5 text-[0.65rem] font-semibold text-white">
                            {badge}
                        </span>
                    )}
                    <Link
                        href={route('market.show', i.id)}
                        aria-hidden
                        tabIndex={-1}
                        className="art-zoom block aspect-square rounded-lg bg-[var(--t-bg)]"
                    >
                        {i.photo && <img src={i.photo} alt="" loading="lazy" decoding="async" className="size-full object-contain p-2" />}
                    </Link>
                    <Link
                        href={route('market.show', i.id)}
                        className="mt-3 line-clamp-2 min-h-6 leading-snug font-semibold hover:text-[var(--t-gold-ink)]"
                    >
                        {i.title}
                    </Link>
                    <ul className="mt-2 space-y-1 text-xs text-[var(--t-muted)]">
                        {specLines(i.blurb).map((l) => (
                            <li key={l} className="line-clamp-1">
                                {l}
                            </li>
                        ))}
                        {i.warranty ? <li>{t('{n} months warranty', { n: num(i.warranty) })}</li> : null}
                    </ul>
                    <p style={DISPLAY} className="tabular mt-auto pt-3 text-2xl text-[var(--t-ink)]">
                        {money(i.price)}
                    </p>
                    {i.stars && (
                        <p className="tabular text-xs text-[var(--t-muted)]">
                            {num(i.stars.average)} / 5 ({num(i.stars.count)})
                        </p>
                    )}
                    <Link
                        href={route('market.show', i.id)}
                        className="art-btn mt-3 inline-flex h-10 items-center justify-center rounded-lg bg-[var(--t-navy)] text-sm font-semibold text-white hover:bg-[var(--t-orange)] hover:text-[var(--t-navy)]"
                    >
                        {t('Buy now')}
                    </Link>
                    {render?.(i)}
                </li>
            ))}
        </ul>
    );
}

/* ---------------------------------------------------------------- clothing: an editorial fashion shop, in the manner of Levi's */

/** The top of the clothing page: a two-picture diptych with a large plain headline beside it. */
export function FashionTop({ title, line, photos }: { title: string; line: string; photos: (string | null)[] }) {
    return (
        <section aria-labelledby="shelf-title" className="fashion -mx-4 bg-white text-[var(--f-ink)] sm:mx-0">
            <div className="grid sm:grid-cols-12">
                <div className="flex flex-col justify-center px-5 py-10 sm:col-span-5 sm:px-10 sm:py-16">
                    <h1 id="shelf-title" className="text-5xl leading-[0.95] font-extrabold tracking-tight sm:text-7xl">
                        {title}
                    </h1>
                    <p className="mt-4 max-w-sm text-lg text-[var(--f-muted)]">{line}</p>
                </div>
                <div className="grid grid-cols-2 gap-px bg-[var(--f-line)] sm:col-span-7">
                    {photos.slice(0, 2).map((p, i) => (
                        <div key={i} className="aspect-[3/4] bg-[var(--f-bg)]">
                            {p && (
                                <img src={p} alt="" fetchPriority={i === 0 ? 'high' : 'auto'} decoding="async" className="size-full object-cover" />
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/** Edge-to-edge portrait photographs with the name and price under each, nothing else. */
export function FashionGrid({ items, render }: { items: ShelfItem[]; render?: (i: ShelfItem) => ReactNode }) {
    const { money, num } = useI18n();

    return (
        <ul className="fashion -mx-4 grid grid-cols-2 gap-px bg-[var(--f-line)] sm:mx-0 md:grid-cols-3 lg:grid-cols-4">
            {items.map((i) => (
                <li key={i.id} className="bg-white text-[var(--f-ink)]">
                    <Link href={route('market.show', i.id)} className="group block">
                        <span className="art-zoom block aspect-[3/4] bg-[var(--f-bg)]">
                            {i.photo && <img src={i.photo} alt="" loading="lazy" decoding="async" className="size-full object-cover" />}
                        </span>
                        <span className="block px-3 pt-3 pb-4">
                            <span className="line-clamp-2 block leading-snug font-medium group-hover:underline">{i.title}</span>
                            <span className="tabular mt-1 block font-semibold text-[var(--f-red)]">{money(i.price)}</span>
                            {i.stars && (
                                <span className="tabular block text-xs text-[var(--f-muted)]">
                                    {num(i.stars.average)} / 5 ({num(i.stars.count)})
                                </span>
                            )}
                        </span>
                    </Link>
                    {render?.(i)}
                </li>
            ))}
        </ul>
    );
}
