import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Item {
    id: number;
    title: string;
    price: number;
    premium: boolean;
    near: boolean;
    unit: string;
    sector: string;
    sector_name: string;
    photo: string | null;
    seller: string;
    trusted?: boolean;
    sellers?: number;
    stars?: { average: number; count: number } | null;
}

interface Hero {
    slug: string;
    name: string;
    name_bn: string | null;
    tagline: string | null;
    tagline_bn: string | null;
    image: string | null;
}

interface Props {
    hero: Hero | null;
    sectors: { slug: string; name: string; count: number }[];
    ranges: { from: number; to: number | null }[];
    hasPremium: boolean;
    items: Item[];
    total: number;
    page: number;
    perPage: number;
    filters: { sector: string | null; premium: boolean; q: string; from: number | null; to: number | null; sort: string; stars: number | null };
}

export const unitLabel = (u: string) => u.charAt(0).toUpperCase() + u.slice(1);

export default function MarketIndex({ hero, sectors, ranges, hasPremium, items, total, page, perPage, filters }: Props) {
    const { t, num, money, isBn } = useI18n();
    const [q, setQ] = useState(filters.q);
    const go = (next: Record<string, string | number | boolean | null>) => {
        const merged: Record<string, string | number | boolean | null> = { ...filters, page: 1, ...next };
        router.get('/market', Object.fromEntries(Object.entries(merged).filter(([k, v]) => v !== null && v !== '' && v !== false && !(k === 'sort' && v === 'near') && !(k === 'page' && v === 1))), { preserveState: true, replace: true });
    };
    const chip = (on: boolean) => `rounded-full border px-3 py-1 text-sm ${on ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)]'}`;
    const range = (r: { from: number; to: number | null }) => (r.to === null ? t('{a} and above', { a: money(r.from) }) : r.from === 0 ? t('Under {b}', { b: money(r.to) }) : `${money(r.from)} – ${money(r.to)}`);

    return (
        <PublicLayout>
            <Head title={t('Market')} />
            <div className="mx-auto max-w-5xl space-y-6">
                {hero && (
                    <section aria-label={isBn && hero.name_bn ? hero.name_bn : hero.name} className="relative isolate -mx-4 flex min-h-[15rem] items-end overflow-hidden rounded-3xl bg-[var(--pt-deep)] text-white sm:mx-0 sm:min-h-[22rem]">
                        {hero.image && <img src={hero.image} alt="" fetchPriority="high" decoding="async" className="absolute inset-0 -z-20 size-full object-cover" />}
                        <div aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-r from-black/65 via-black/30 to-transparent" />
                        <div className="max-w-xl px-6 py-8 sm:px-12 sm:py-12">
                            <h2 style={{ fontFamily: "'Cormorant Garamond', 'Hind Siliguri', Georgia, serif" }} className="text-4xl leading-none font-medium tracking-tight sm:text-6xl">
                                {isBn && hero.name_bn ? hero.name_bn : hero.name}
                            </h2>
                            {(isBn && hero.tagline_bn ? hero.tagline_bn : hero.tagline) && <p className="mt-3 max-w-md text-[15px] leading-relaxed text-white/85 sm:text-lg">{isBn && hero.tagline_bn ? hero.tagline_bn : hero.tagline}</p>}
                        </div>
                    </section>
                )}

                <div>
                    <Link href="/market-cart" className="float-right text-sm font-semibold underline underline-offset-4">
                        {t('Your market cart')}
                    </Link>
                    <h1 className="font-display text-4xl font-extrabold tracking-tight">{t('Market')}</h1>
                    <p className="mt-1 max-w-prose text-[var(--pt-muted)]">{t('Choose what you are looking for. Prices are the same for everyone.')}</p>
                </div>

                {sectors.length > 0 && (
                    <nav aria-label={t('Kinds of products')} className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {sectors.map((s) => (
                            <button
                                key={s.slug}
                                type="button"
                                aria-pressed={filters.sector === s.slug}
                                onClick={() => go({ sector: filters.sector === s.slug ? null : s.slug })}
                                className={`rounded-2xl border p-4 text-left transition ${filters.sector === s.slug ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)] bg-[var(--pt-surface)] hover:border-[var(--pt-ink)]'}`}
                            >
                                <span className="block font-display text-xl font-bold">{s.name}</span>
                                <span className="text-sm opacity-70">{t('{n} products', { n: num(s.count) })}</span>
                            </button>
                        ))}
                    </nav>
                )}

                <section aria-label={t('Your budget')} className="space-y-2">
                    <p className="text-sm font-medium">{t('What is your budget?')}</p>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" className={chip(filters.from === null && !filters.premium)} onClick={() => go({ from: null, to: null, premium: false })}>
                            {t('Any')}
                        </button>
                        {ranges.map((r) => (
                            <button key={r.from} type="button" className={chip(filters.from === r.from && !filters.premium)} onClick={() => go({ from: r.from, to: r.to, premium: false })}>
                                {range(r)}
                            </button>
                        ))}
                        {hasPremium && (
                            <button type="button" className={chip(filters.premium)} onClick={() => go({ premium: !filters.premium, from: null, to: null })}>
                                {t('Premium')}
                            </button>
                        )}
                    </div>
                </section>

                <form
                    role="search"
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({ q });
                    }}
                >
                    <label htmlFor="mk-q" className="sr-only">
                        {t('Search')}
                    </label>
                    <input id="mk-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Search')} className="h-11 min-w-48 flex-1 rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] px-3" />
                    <label htmlFor="mk-sort" className="sr-only">
                        {t('Sort')}
                    </label>
                    <select id="mk-sort" value={filters.sort} onChange={(e) => go({ sort: e.target.value })} className="h-11 rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] px-3 text-sm">
                        <option value="near">{t('Nearest and newest')}</option>
                        <option value="new">{t('Newest')}</option>
                        <option value="low">{t('Price, low to high')}</option>
                        <option value="high">{t('Price, high to low')}</option>
                        <option value="popular">{t('Most ordered')}</option>
                    </select>
                    <label htmlFor="mk-stars" className="sr-only">
                        {t('Rating')}
                    </label>
                    <select id="mk-stars" value={filters.stars ?? ''} onChange={(e) => go({ stars: e.target.value === '' ? null : Number(e.target.value) })} className="h-11 rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] px-3 text-sm">
                        <option value="">{t('Any rating')}</option>
                        <option value="4">{t('4 stars and up')}</option>
                        <option value="3">{t('3 stars and up')}</option>
                    </select>
                    <Button type="submit" variant="secondary">
                        {t('Search')}
                    </Button>
                </form>

                {items.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('Nothing here yet.')}</p>
                ) : (
                    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {items.map((i) => (
                            <li key={i.id}>
                                <Link href={`/market/${i.id}`} className="block overflow-hidden rounded-2xl border border-[var(--pt-line)] bg-[var(--pt-surface)] hover:border-[var(--pt-ink)]">
                                    {i.photo ? <img src={i.photo} alt="" loading="lazy" className="aspect-square w-full object-cover" /> : <div aria-hidden className="aspect-square w-full bg-[var(--pt-sage)]" />}
                                    <div className="space-y-0.5 p-3">
                                        <p className="line-clamp-2 font-semibold">{i.title}</p>
                                        <p className="tabular font-display text-lg font-bold">
                                            {money(i.price)} <span className="text-xs font-normal text-[var(--pt-muted)]">/ {unitLabel(i.unit)}</span>
                                        </p>
                                        <p className="text-xs text-[var(--pt-muted)]">
                                            {i.seller}
                                            {i.trusted && <span className="ms-2 rounded bg-emerald-600/10 px-1.5 text-xs font-semibold text-emerald-800">{t('Trusted seller')}</span>}
                                            {i.stars && <span className="tabular ms-2 font-semibold text-[var(--pt-ink)]">★ {num(i.stars.average)} ({num(i.stars.count)})</span>}
                                            {(i.sellers ?? 1) > 1 && <span className="ms-2 text-xs font-semibold text-emerald-800">{t('{n} sellers', { n: num(i.sellers ?? 1) })}</span>}
                                            {i.near && ` · ${t('Near you')}`}
                                            {i.premium && ` · ${t('Premium')}`}
                                        </p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {total > perPage && (
                    <div className="flex justify-between">
                        <Button variant="outline" disabled={page <= 1} onClick={() => go({ page: page - 1 })}>
                            {t('Previous')}
                        </Button>
                        <Button variant="outline" disabled={page * perPage >= total} onClick={() => go({ page: page + 1 })}>
                            {t('Next')}
                        </Button>
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
