import { TechGrid, type ShelfItem } from '@/components/market/layouts';
import { FaqList, ServiceStrip, StepsList } from '@/components/storefront/shared';
import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useState } from 'react';

export interface StoreBlock {
    id: number;
    kind: 'hero' | 'categories' | 'products' | 'premium' | 'banner' | 'budget' | 'service' | 'faq' | 'cta' | 'steps';
    title: string | null;
    text: string | null;
    button: string | null;
    image?: string | null;
    count?: number;
    source?: string | null;
    categories?: { slug: string; name: string; count: number; image: string | null }[];
    products?: ShelfItem[];
    product?: ShelfItem;
    ranges?: { from: number; to: number | null }[];
    items?: { title: string; text: string }[];
}

export interface StoreFront {
    sector: string;
    count: number;
    blocks: StoreBlock[];
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

/**
 * The front page of a shop. It draws whatever sections the admin built, in the order the admin put them. Every word, picture and product comes
 * from the backend (Admin > Shop fronts), so a change there shows here with no edit to this file.
 */
export default function Storefront({ data }: { data: StoreFront }) {
    const { t, num, money } = useI18n();
    const allHref = route('market.index', { sector: data.sector, all: 1 });

    return (
        <div className="tech space-y-16">
            {data.blocks.map((b) => {
                switch (b.kind) {
                    case 'hero':
                        return <Hero key={b.id} b={b} href={allHref} />;
                    case 'categories':
                        return <Categories key={b.id} b={b} sector={data.sector} />;
                    case 'products':
                        return <Shelf key={b.id} b={b} href={allHref} />;
                    case 'premium':
                        return <Premium key={b.id} b={b} />;
                    case 'banner':
                        return <Banner key={b.id} b={b} />;
                    case 'budget':
                        return <Budget key={b.id} b={b} sector={data.sector} />;
                    case 'service':
                        return <ServiceStrip key={b.id} items={b.items ?? []} label={t('Why shop here')} />;
                    case 'faq':
                        return <FaqList key={b.id} title={b.title} items={b.items ?? []} />;
                    case 'steps':
                        return <StepsList key={b.id} title={b.title} items={b.items ?? []} accent="var(--t-gold-ink)" />;
                    case 'cta':
                        return (
                            <div key={b.id} className="flex justify-center">
                                <Link
                                    href={allHref}
                                    className="art-btn inline-flex h-12 items-center gap-2 rounded-full border border-[var(--t-ink)] px-8 font-semibold text-[var(--t-ink)]"
                                >
                                    {(b.button ?? t('Explore products')).replace('{n}', num(b.count ?? data.count))}
                                    <ArrowUpRight aria-hidden className="size-5" strokeWidth={2} />
                                </Link>
                            </div>
                        );
                    default:
                        return null;
                }
            })}
        </div>
    );
}

function Hero({ b, href }: { b: StoreBlock; href: string }) {
    const { t, num, money } = useI18n();
    return (
        <section
            aria-labelledby={`sf-${b.id}`}
            className="relative isolate -mx-4 overflow-hidden bg-[var(--t-navy)] text-white sm:mx-0 sm:rounded-3xl"
        >
            {b.image && (
                <img
                    src={b.image}
                    alt=""
                    fetchPriority="high"
                    decoding="async"
                    className="absolute inset-y-0 right-0 -z-20 h-full w-full object-cover sm:w-2/3"
                />
            )}
            <span aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--t-navy)] via-[var(--t-navy)]/85 to-transparent" />
            <div className="px-6 py-14 sm:px-12 sm:py-24">
                <h1 id={`sf-${b.id}`} style={DISPLAY} className="art-rise max-w-xl text-5xl leading-[1.02] text-balance sm:text-7xl">
                    {b.title}
                </h1>
                {b.text && <p className="art-rise art-d1 mt-5 max-w-md text-lg text-white/80">{b.text}</p>}
                <div className="art-rise art-d2 mt-8 flex flex-wrap items-center gap-4">
                    {b.button && (
                        <Link
                            href={href}
                            className="art-btn inline-flex h-14 items-center gap-2 rounded-full bg-[var(--t-orange)] px-8 font-semibold text-[var(--t-navy)]"
                        >
                            {b.button}
                            <ArrowUpRight aria-hidden className="size-5" strokeWidth={2} />
                        </Link>
                    )}
                    <span className="tabular text-sm text-white/70">{t('{n} products', { n: num(b.count ?? 0) })}</span>
                </div>
            </div>
        </section>
    );
}

function Categories({ b, sector }: { b: StoreBlock; sector: string }) {
    const { t, num, money } = useI18n();
    const list = b.categories ?? [];

    return (
        <section aria-label={b.title ?? t('Browse by type')}>
            {b.title && (
                <h2 style={DISPLAY} className="text-center text-4xl leading-none text-[var(--t-ink)]">
                    {b.title}
                </h2>
            )}
            <div className="art-marquee-wrap no-scrollbar -mx-4 mt-8 overflow-hidden motion-reduce:overflow-x-auto sm:mx-0">
                <ul className="art-marquee flex w-max">
                    {[...list, ...list].map((c, n) => {
                        const copy = n >= list.length;

                        return (
                            <li key={`${c.slug}-${n}`} aria-hidden={copy || undefined} className="w-44 shrink-0 pe-3 sm:w-56">
                                <Link
                                    href={route('market.index', { sector, category: c.slug })}
                                    tabIndex={copy ? -1 : undefined}
                                    className="art-zoom art-lift relative isolate flex aspect-[3/4] flex-col justify-end overflow-hidden rounded-2xl bg-[var(--t-navy)] p-4 text-white"
                                >
                                    {c.image && (
                                        <img
                                            src={c.image}
                                            alt=""
                                            loading="lazy"
                                            decoding="async"
                                            className="absolute inset-0 -z-20 size-full object-cover"
                                        />
                                    )}
                                    <span
                                        aria-hidden
                                        className="absolute inset-0 -z-10 bg-gradient-to-t from-black/70 via-transparent to-transparent"
                                    />
                                    <span style={DISPLAY} className="text-xl leading-tight">
                                        {c.name}
                                    </span>
                                    <span className="tabular text-xs text-white/75">{t('{n} products', { n: num(c.count) })}</span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </div>
        </section>
    );
}

function Shelf({ b, href }: { b: StoreBlock; href: string }) {
    const { t, num, money } = useI18n();
    const items = b.products ?? [];
    const types = Array.from(new Set(items.map((i) => i.category).filter((c): c is string => !!c)));
    const [tab, setTab] = useState<string | null>(null);
    const shown = tab === null ? items : items.filter((i) => i.category === tab);
    const badge = b.source === 'best' ? t('Best') : b.source === 'fresh' ? t('New') : undefined;
    const chip = (on: boolean) =>
        `rounded-full border px-4 py-1.5 text-sm transition-colors duration-300 ${on ? 'border-[var(--t-navy)] bg-[var(--t-navy)] text-white' : 'border-[var(--t-line)] bg-white hover:border-[var(--t-navy)]'}`;

    return (
        <section aria-label={b.title ?? undefined}>
            <div className="flex items-end justify-between gap-4">
                {b.title && (
                    <h2 style={DISPLAY} className="text-4xl leading-none text-[var(--t-ink)]">
                        {b.title}
                    </h2>
                )}
                <Link href={href} className="inline-flex min-h-10 items-center gap-1 text-sm underline underline-offset-4">
                    {t('View all products')}
                    <ArrowUpRight aria-hidden className="size-4" strokeWidth={1.6} />
                </Link>
            </div>
            {b.text && <p className="mt-3 max-w-xl text-[var(--t-muted)]">{b.text}</p>}
            {types.length > 1 && (
                <div role="group" aria-label={t('Kind of product')} className="mt-6 flex flex-wrap gap-2">
                    <button type="button" aria-pressed={tab === null} onClick={() => setTab(null)} className={chip(tab === null)}>
                        {t('All')}
                    </button>
                    {types.map((x) => (
                        <button key={x} type="button" aria-pressed={tab === x} onClick={() => setTab(x)} className={chip(tab === x)}>
                            {x}
                        </button>
                    ))}
                </div>
            )}
            <div className="mt-6">
                <TechGrid items={shown} badge={badge} />
            </div>
        </section>
    );
}

function Banner({ b }: { b: StoreBlock }) {
    const { t, num, money } = useI18n();
    const p = b.product;

    if (!p) return null;

    return (
        <section
            aria-label={b.title ?? undefined}
            className="relative isolate -mx-4 overflow-hidden bg-[var(--t-navy)] text-white sm:mx-0 sm:rounded-3xl"
        >
            {b.image && (
                <img
                    src={b.image}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="absolute inset-y-0 right-0 -z-20 h-full w-full object-cover sm:w-3/5"
                />
            )}
            <span aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--t-navy)] via-[var(--t-navy)]/80 to-transparent" />
            <div className="max-w-md px-6 py-14 sm:px-12 sm:py-20">
                {b.title && <p className="text-sm text-white/70">{b.title}</p>}
                <p style={DISPLAY} className="mt-2 text-4xl leading-tight text-balance sm:text-5xl">
                    {p.title}
                </p>
                <p style={DISPLAY} className="tabular mt-4 text-3xl text-[var(--t-orange)]">
                    {money(p.price)}
                </p>
                {b.button && (
                    <Link
                        href={route('market.show', p.id)}
                        className="art-btn mt-6 inline-flex h-12 items-center gap-2 rounded-full bg-white px-7 font-semibold text-[var(--t-navy)]"
                    >
                        {b.button}
                        <ArrowUpRight aria-hidden className="size-5" strokeWidth={2} />
                    </Link>
                )}
            </div>
        </section>
    );
}

function Budget({ b, sector }: { b: StoreBlock; sector: string }) {
    const { t, num, money } = useI18n();
    const label = (r: { from: number; to: number | null }) =>
        r.to === null
            ? t('{a} and above', { a: money(r.from) })
            : r.from === 0
              ? t('Under {b}', { b: money(r.to) })
              : `${money(r.from)} - ${money(r.to)}`;

    return (
        <section aria-label={b.title ?? undefined} className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-16">
            <div>
                {b.title && (
                    <h2 style={DISPLAY} className="text-4xl leading-none text-[var(--t-ink)] sm:text-5xl">
                        {b.title}
                    </h2>
                )}
                {b.text && <p className="mt-4 max-w-xs text-[var(--t-muted)]">{b.text}</p>}
            </div>
            <ul className="border-t border-[var(--t-line)]">
                {(b.ranges ?? []).map((r) => (
                    <li key={r.from} className="border-b border-[var(--t-line)]">
                        <Link
                            href={route('market.index', { sector, all: 1, from: r.from, ...(r.to === null ? {} : { to: r.to }) })}
                            className="group flex min-h-16 items-center justify-between gap-4 py-4 text-[var(--t-ink)]"
                        >
                            <span style={DISPLAY} className="tabular text-2xl sm:text-3xl">
                                {label(r)}
                            </span>
                            <ArrowUpRight
                                aria-hidden
                                className="size-6 shrink-0 text-[var(--t-muted)] transition-transform duration-300 group-hover:translate-x-1 group-hover:-translate-y-1 group-hover:text-[var(--t-ink)]"
                                strokeWidth={1.6}
                            />
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Premium({ b }: { b: StoreBlock }) {
    const { t, num, money } = useI18n();
    const [lead, ...rest] = b.products ?? [];

    if (!lead) return null;

    return (
        <section aria-label={b.title ?? undefined}>
            {b.title && (
                <h2 style={DISPLAY} className="text-4xl leading-none text-[var(--t-ink)] sm:text-5xl">
                    {b.title}
                </h2>
            )}
            <div className="mt-8 grid gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <Link
                    href={route('market.show', lead.id)}
                    className="art-zoom group flex flex-col overflow-hidden rounded-3xl border border-[var(--t-line)] bg-white"
                >
                    <span className="block aspect-[4/3] bg-[var(--t-bg)]">
                        {lead.photo && <img src={lead.photo} alt="" loading="lazy" decoding="async" className="size-full object-contain p-6" />}
                    </span>
                    <span className="flex flex-wrap items-end justify-between gap-3 p-6">
                        <span style={DISPLAY} className="max-w-sm text-3xl leading-tight text-[var(--t-ink)]">
                            {lead.title}
                        </span>
                        <span style={DISPLAY} className="tabular text-3xl text-[var(--t-ink)]">
                            {money(lead.price)}
                        </span>
                    </span>
                </Link>
                {rest.length > 0 && (
                    <ul className="flex flex-col gap-4">
                        {rest.map((i) => (
                            <li key={i.id} className="flex-1">
                                <Link
                                    href={route('market.show', i.id)}
                                    className="art-lift flex h-full items-center gap-4 rounded-2xl border border-[var(--t-line)] bg-white p-4 text-[var(--t-ink)]"
                                >
                                    <span className="block size-24 shrink-0 rounded-xl bg-[var(--t-bg)] sm:size-28">
                                        {i.photo && (
                                            <img src={i.photo} alt="" loading="lazy" decoding="async" className="size-full object-contain p-2" />
                                        )}
                                    </span>
                                    <span className="min-w-0">
                                        <span className="line-clamp-2 block leading-snug font-semibold">{i.title}</span>
                                        <span style={DISPLAY} className="tabular mt-1 block text-2xl">
                                            {money(i.price)}
                                        </span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
