import { Rosette } from '@/components/home/ornaments';
import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ChevronLeft, ChevronRight, Pause, Play } from 'lucide-react';
import { useEffect, useState } from 'react';

export interface ShowItem {
    id: number;
    href?: string;
    title: string;
    blurb: string;
    price: number;
    unit: string | null;
    photo: string | null;
}

export interface ShowShop {
    slug: string;
    href?: string;
    name: string;
    count: number;
    items: ShowItem[];
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

/**
 * One window for every shop. The shops are a list on the left, the chosen shop's best piece fills the right in an arch, and three more
 * pieces from the same shop sit under it. It turns by itself every few seconds, stops when you touch it or press Pause, and does not turn at
 * all for anyone who asked their device for less motion.
 */
export default function Showcase({ shops }: { shops: ShowShop[] }) {
    const { t, num, money } = useI18n();
    const [at, setAt] = useState(0);
    const [paused, setPaused] = useState(false);
    const [still, setStill] = useState(false);
    const [hover, setHover] = useState(false);

    useEffect(() => {
        const q = window.matchMedia('(prefers-reduced-motion: reduce)');
        setStill(q.matches);
        const on = () => setStill(q.matches);
        q.addEventListener('change', on);

        return () => q.removeEventListener('change', on);
    }, []);

    useEffect(() => {
        if (paused || hover || still || shops.length < 2) return;
        const id = window.setInterval(() => setAt((i) => (i + 1) % shops.length), 6500);

        return () => window.clearInterval(id);
    }, [paused, hover, still, shops.length]);

    if (shops.length === 0) return null;

    const go = (n: number) => setAt((n + shops.length) % shops.length);
    const shop = shops[at];
    // The window shows the dearest piece that has a photograph; the rest of the shop sits under it.
    const ranked = [...shop.items].filter((i) => i.photo).sort((a, b) => b.price - a.price);
    const lead = ranked[0];
    const more = ranked.slice(1, 4);

    if (!lead) return null;

    return (
        <section
            aria-roledescription="carousel"
            aria-label={t('In the window')}
            onMouseEnter={() => setHover(true)}
            onMouseLeave={() => setHover(false)}
            onFocus={() => setHover(true)}
            onBlur={() => setHover(false)}
            className="reveal mt-28"
        >
            <div className="flex items-end justify-between gap-4">
                <h2 style={DISPLAY} className="text-6xl leading-[1.05] font-normal tracking-tight text-balance">
                    {t('In the window')}
                </h2>
                <div className="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        onClick={() => setPaused((p) => !p)}
                        aria-label={paused || still ? t('Play') : t('Pause')}
                        className="grid size-11 place-items-center rounded-full border border-[var(--pt-ink)] hover:bg-[var(--pt-ink)] hover:text-[var(--pt-paper)]"
                    >
                        {paused || still ? <Play aria-hidden className="size-4" /> : <Pause aria-hidden className="size-4" />}
                    </button>
                    <button
                        type="button"
                        onClick={() => go(at - 1)}
                        aria-label={t('Previous')}
                        className="grid size-11 place-items-center rounded-full border border-[var(--pt-ink)] hover:bg-[var(--pt-ink)] hover:text-[var(--pt-paper)]"
                    >
                        <ChevronLeft aria-hidden className="size-5" />
                    </button>
                    <button
                        type="button"
                        onClick={() => go(at + 1)}
                        aria-label={t('Next')}
                        className="grid size-11 place-items-center rounded-full border border-[var(--pt-ink)] hover:bg-[var(--pt-ink)] hover:text-[var(--pt-paper)]"
                    >
                        <ChevronRight aria-hidden className="size-5" />
                    </button>
                </div>
            </div>

            <div className="mt-10 grid grid-cols-12 gap-14">
                <ul role="tablist" aria-label={t('Shops')} className="no-scrollbar col-span-3 mx-0 flex flex-col gap-0 overflow-visible px-0">
                    {shops.map((s, i) => (
                        <li key={s.slug} role="presentation" className="shrink-0">
                            <button
                                type="button"
                                role="tab"
                                aria-selected={i === at}
                                onClick={() => setAt(i)}
                                className={`flex w-full items-center gap-3 rounded-none border-0 border-b px-0 py-3.5 text-left transition-colors duration-300 ${i === at ? 'border-[var(--pt-ink)] text-[var(--pt-ink)]' : 'border-[var(--pt-line)] text-[var(--pt-muted)] hover:text-[var(--pt-ink)]'}`}
                            >
                                <span
                                    aria-hidden
                                    className={`block size-2 shrink-0 rotate-45 transition-colors duration-300 ${i === at ? 'bg-[var(--art-gold-2)]' : 'bg-[var(--pt-line)]'}`}
                                />
                                <span style={DISPLAY} className="text-2xl whitespace-nowrap">
                                    {s.name}
                                </span>
                                <span className="tabular ml-auto block text-sm">{num(s.count)}</span>
                            </button>
                        </li>
                    ))}
                </ul>

                <div key={shop.slug} role="tabpanel" aria-live="off" className="art-swap col-span-9 grid grid-cols-2 items-center gap-8">
                    <Link href={lead.href ?? route('market.show', lead.id)} className="relative isolate block">
                        <Rosette className="absolute top-1/2 left-1/2 -z-10 w-[112%] max-w-none -translate-x-1/2 -translate-y-1/2 opacity-30" />
                        <div className="art-zoom rounded-t-full border border-[var(--art-gold-2)] bg-[var(--pt-paper)] p-2">
                            <div className="art-zoom overflow-hidden rounded-t-full">
                                <img src={lead.photo ?? ''} alt="" decoding="async" className="aspect-[4/5] w-full object-cover" />
                            </div>
                        </div>
                    </Link>
                    <div>
                        <p className="text-sm text-[var(--pt-muted)]">{shop.name}</p>
                        <h3 style={DISPLAY} className="mt-2 text-5xl leading-[1.08] font-normal text-balance">
                            {lead.title}
                        </h3>
                        {lead.blurb && <p className="mt-4 max-w-md leading-relaxed text-[var(--pt-muted)]">{lead.blurb}</p>}
                        <p style={DISPLAY} className="tabular mt-5 text-3xl">
                            {money(lead.price)}
                            {lead.unit ? <span className="text-base text-[var(--pt-muted)]"> / {lead.unit}</span> : null}
                        </p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <Link
                                href={lead.href ?? route('market.show', lead.id)}
                                className="art-btn inline-flex h-12 items-center gap-2 rounded-full bg-[var(--pt-ink)] px-7 text-[var(--pt-paper)]"
                            >
                                {t('See this piece')}
                                <ArrowUpRight aria-hidden className="size-5" strokeWidth={1.8} />
                            </Link>
                            <Link
                                href={shop.href ?? `/market?sector=${shop.slug}`}
                                className="inline-flex h-12 items-center rounded-full border border-[var(--pt-ink)] px-7"
                            >
                                {t('More from {shop}', { shop: shop.name })}
                            </Link>
                        </div>
                        {more.length > 0 && (
                            <ul className="mt-8 grid grid-cols-3 gap-3 border-t border-[var(--pt-line)] pt-6">
                                {more.map((m) => (
                                    <li key={m.id}>
                                        <Link href={m.href ?? route('market.show', m.id)} className="block">
                                            <span className="art-zoom block rounded-t-full">
                                                <img
                                                    src={m.photo ?? ''}
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                    className="aspect-[4/5] w-full rounded-t-full object-cover"
                                                />
                                            </span>
                                            <span className="mt-2 line-clamp-1 block text-sm">{m.title}</span>
                                            <span className="tabular block text-sm text-[var(--pt-muted)]">{money(m.price)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
