import { type MediaItem } from '@/components/market/market-home-sections';
import { DISPLAY, SectionHead } from '@/components/storefront/shared';
import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

export interface ToneItem extends MediaItem {
    tone: string | null;
}

interface Words {
    id: number;
    title: string | null;
    text: string | null;
    button: string | null;
    link: string | null;
    count?: number;
    image?: string | null;
}

/**
 * The opening banner: a deep panel with the headline on one side and a drifting collage of picture tiles on the other. Each tile wears a
 * jewel tone the admin chooses, so the opening is colourful while the type stays quiet.
 */
export function Showcase({ b, items, href }: { b: Words; items: ToneItem[]; href: string }) {
    const { t, num } = useI18n();

    return (
        <section
            aria-labelledby={`sf-${b.id}`}
            className="relative isolate -mx-4 grid gap-10 overflow-hidden bg-[var(--t-navy)] px-6 py-12 text-white sm:mx-0 sm:rounded-3xl sm:px-12 sm:py-16 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center lg:gap-14"
        >
            <span aria-hidden className="absolute -top-24 -right-24 -z-10 size-96 rounded-full bg-[var(--tone-rose)] opacity-20 blur-3xl" />
            <span aria-hidden className="absolute -bottom-32 left-1/4 -z-10 size-96 rounded-full bg-[var(--tone-saffron)] opacity-15 blur-3xl" />
            <div>
                <h1 id={`sf-${b.id}`} style={DISPLAY} className="art-rise text-5xl leading-[1.02] text-balance sm:text-6xl lg:text-7xl">
                    {b.title}
                </h1>
                {b.text && <p className="art-rise art-d1 mt-5 max-w-md text-lg text-white/80">{b.text}</p>}
                <div className="art-rise art-d2 mt-8 flex flex-wrap items-center gap-4">
                    {b.button && (
                        <Link
                            href={b.link ?? href}
                            className="art-btn inline-flex h-14 items-center gap-2 rounded-full bg-[var(--t-orange)] px-8 font-semibold text-[var(--t-navy)]"
                        >
                            {b.button}
                            <ArrowUpRight aria-hidden className="size-5" strokeWidth={2} />
                        </Link>
                    )}
                    <span className="tabular text-sm text-white/70">{t('{n} products', { n: num(b.count ?? 0) })}</span>
                </div>
            </div>
            <ul className="grid grid-cols-3 gap-3 sm:gap-4">
                {items.slice(0, 8).map((i, n) => {
                    const cls = `tone-${i.tone ?? 'saffron'} art-zoom relative isolate flex aspect-[3/4] items-end overflow-hidden rounded-2xl bg-[var(--tone)] p-2.5`;
                    const inner = (
                        <>
                            {i.image && (
                                <img
                                    src={i.image}
                                    alt=""
                                    loading={n < 4 ? 'eager' : 'lazy'}
                                    decoding="async"
                                    className="absolute inset-0 -z-20 size-full object-cover"
                                />
                            )}
                            <span aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-t from-black/70 via-black/5 to-transparent" />
                            <span className="rounded-full bg-[var(--tone)] px-2.5 py-0.5 text-xs font-semibold text-[var(--t-navy)]">{i.title}</span>
                        </>
                    );

                    return (
                        <li key={`${i.title}-${n}`} className={`art-drift ${n % 2 === 1 ? 'mt-6' : 'art-drift-b'}`}>
                            {i.link ? (
                                <Link href={i.link} className={cls}>
                                    {inner}
                                </Link>
                            ) : (
                                <div className={cls}>{inner}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** A thin line of short promises that slides past, in the same loop as the category tiles. */
export function Ticker({ items }: { items: MediaItem[] }) {
    const list = items.map((i) => i.title);

    return (
        <div className="art-marquee-wrap -mx-4 overflow-hidden border-y border-[var(--t-line)] py-4 motion-reduce:overflow-x-auto sm:mx-0">
            <ul className="art-marquee flex w-max">
                {[...list, ...list].map((x, n) => (
                    <li key={n} aria-hidden={n >= list.length || undefined} className="flex items-center whitespace-nowrap">
                        <span style={DISPLAY} className="px-8 text-xl text-[var(--t-ink)] sm:text-2xl">
                            {x}
                        </span>
                        <span aria-hidden className="size-1.5 rounded-full bg-[var(--t-orange)]" />
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Big figures with a line under each. */
export function Numbers({ title, items }: { title: string | null; items: MediaItem[] }) {
    return (
        <section aria-label={title ?? undefined} className="rounded-3xl bg-[var(--t-bg)] px-6 py-12 sm:px-12 sm:py-16">
            {title && (
                <h2 style={DISPLAY} className="text-center text-4xl leading-none text-balance text-[var(--t-ink)]">
                    {title}
                </h2>
            )}
            <dl className="mt-10 grid grid-cols-2 gap-x-4 gap-y-10 lg:grid-cols-4">
                {items.map((i, n) => (
                    <div key={`${i.title}-${n}`} className="text-center">
                        <dt style={DISPLAY} className="tabular text-6xl leading-none text-[var(--t-ink)] sm:text-7xl">
                            {i.title}
                        </dt>
                        <dd className="mt-3 text-[var(--t-muted)]">{i.text}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

/** A tall picture beside a short story and a few linked points. The side the picture sits on alternates down the page. */
export function Feature({ b, items, flip, href }: { b: Words; items: MediaItem[]; flip: boolean; href: string }) {
    return (
        <section aria-labelledby={`sf-${b.id}`} className="grid items-center gap-8 lg:grid-cols-2 lg:gap-16">
            <div className={`art-zoom aspect-[4/5] overflow-hidden rounded-3xl bg-[var(--t-bg)] ${flip ? 'lg:order-2' : ''}`}>
                {b.image && <img src={b.image} alt="" loading="lazy" decoding="async" className="size-full object-cover" />}
            </div>
            <div>
                <h2 id={`sf-${b.id}`} style={DISPLAY} className="text-4xl leading-none text-balance text-[var(--t-ink)] sm:text-6xl">
                    {b.title}
                </h2>
                {b.text && <p className="mt-6 max-w-md text-lg leading-relaxed text-[var(--t-muted)]">{b.text}</p>}
                {items.length > 0 && (
                    <ul className="mt-8 border-t border-[var(--t-line)]">
                        {items.map((i, n) => (
                            <li key={`${i.title}-${n}`} className="border-b border-[var(--t-line)]">
                                {i.link ? (
                                    <Link href={i.link} className="group flex min-h-14 items-center justify-between gap-4 py-3 text-[var(--t-ink)]">
                                        <span style={DISPLAY} className="text-xl sm:text-2xl">
                                            {i.title}
                                        </span>
                                        <ArrowUpRight
                                            aria-hidden
                                            className="size-5 shrink-0 text-[var(--t-muted)] transition-transform duration-300 group-hover:translate-x-1 group-hover:-translate-y-1"
                                            strokeWidth={1.6}
                                        />
                                    </Link>
                                ) : (
                                    <span style={DISPLAY} className="block py-3 text-xl sm:text-2xl">
                                        {i.title}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {b.button && (
                    <Link
                        href={b.link ?? href}
                        className="art-btn mt-8 inline-flex h-12 items-center gap-2 rounded-full bg-[var(--t-navy)] px-7 font-semibold text-white"
                    >
                        {b.button}
                        <ArrowUpRight aria-hidden className="size-5" strokeWidth={2} />
                    </Link>
                )}
            </div>
        </section>
    );
}

/** Round pictures with a name under each, in a row that scrolls sideways. */
export function Rail({ title, text, items }: { title: string | null; text: string | null; items: MediaItem[] }) {
    return (
        <section aria-label={title ?? undefined}>
            <SectionHead title={title} text={text} />
            <ul className="no-scrollbar -mx-4 mt-8 flex snap-x gap-6 overflow-x-auto px-4 pb-2 sm:mx-0 sm:justify-center sm:px-0">
                {items.map((i, n) => {
                    const inner = (
                        <>
                            <span className="art-zoom block size-28 overflow-hidden rounded-full bg-[var(--t-bg)] ring-1 ring-[var(--t-line)] sm:size-36">
                                {i.image && <img src={i.image} alt="" loading="lazy" decoding="async" className="size-full object-cover" />}
                            </span>
                            <span style={DISPLAY} className="mt-3 block text-center text-lg">
                                {i.title}
                            </span>
                        </>
                    );

                    return (
                        <li key={`${i.title}-${n}`} className="shrink-0 snap-start">
                            {i.link ? (
                                <Link href={i.link} className="group block">
                                    {inner}
                                </Link>
                            ) : (
                                inner
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
