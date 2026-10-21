import SectionHero from '@/components/home/section-hero';
import { Breadcrumb, type CategoryNode, SubTiles, TreeNav, type TreeNode } from '@/components/market/category-nav';
import { FashionGrid, FashionTop, TechGrid } from '@/components/market/layouts';
import Storefront, { type StoreFront } from '@/components/market/tech-storefront';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';

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
    blurb?: string;
    warranty?: number | null;
    cod?: boolean;
    category?: string | null;
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
    category: CategoryNode | null;
    tree: TreeNode[];
    storefront: StoreFront | null;
    hero: Hero | null;
    sectors: { slug: string; name: string; count: number }[];
    ranges: { from: number; to: number | null }[];
    hasPremium: boolean;
    items: Item[];
    total: number;
    page: number;
    perPage: number;
    filters: {
        category: string | null;
        sector: string | null;
        premium: boolean;
        all: boolean;
        q: string;
        from: number | null;
        to: number | null;
        sort: string;
        stars: number | null;
    };
}

export const unitLabel = (u: string) => u.charAt(0).toUpperCase() + u.slice(1);

export default function MarketIndex({ category, tree, storefront, hero, sectors, ranges, hasPremium, items, total, page, perPage, filters }: Props) {
    const { t, num, money, isBn } = useI18n();
    const go = (next: Record<string, string | number | boolean | null>) => {
        const merged: Record<string, string | number | boolean | null> = { ...filters, page: 1, ...next };
        router.get(
            '/market',
            Object.fromEntries(
                Object.entries(merged).filter(
                    ([k, v]) => v !== null && v !== '' && v !== false && !(k === 'sort' && v === 'near') && !(k === 'page' && v === 1),
                ),
            ),
            { preserveState: true, replace: true },
        );
    };
    const chip = (on: boolean) =>
        `rounded-full border px-4 py-1.5 text-sm transition-colors duration-300 ${on ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-paper)]' : 'border-[var(--pt-line)] hover:border-[var(--pt-ink)]'}`;
    const range = (r: { from: number; to: number | null }) =>
        r.to === null
            ? t('{a} and above', { a: money(r.from) })
            : r.from === 0
              ? t('Under {b}', { b: money(r.to) })
              : `${money(r.from)} to ${money(r.to)}`;

    const layout = filters.sector === 'electronics' ? 'tech' : filters.sector === 'clothing' ? 'fashion' : 'default';
    const heroTitle = hero ? (isBn && hero.name_bn ? hero.name_bn : hero.name) : t('Market');
    const heroLine = hero ? ((isBn && hero.tagline_bn ? hero.tagline_bn : hero.tagline) ?? undefined) : undefined;

    return (
        <PublicLayout>
            <Head title={t('Market')} />
            <div className="mx-auto max-w-5xl space-y-6">
                {storefront && <Storefront data={storefront} />}
                {layout === 'fashion' && !category && !storefront && (
                    <FashionTop
                        title={heroTitle}
                        line={heroLine ?? t('Made to wear, well.')}
                        photos={[hero?.image ?? null, ...items.map((i) => i.photo)].filter(Boolean).slice(0, 2)}
                    />
                )}
                {layout === 'default' && !category && !storefront && !filters.all && (
                    <SectionHero
                        {...(hero ? { src: hero.image } : { image: '/images/home/bazaar' })}
                        title={hero ? heroTitle : t('Market')}
                        line={hero ? (heroLine ?? undefined) : t('Choose what you are looking for. Prices are the same for everyone.')}
                    >
                        <Link
                            href="/market-cart"
                            className={`art-btn inline-flex h-12 items-center rounded-full px-6 ${hero ? 'border border-[var(--art-gold-2)]' : 'bg-[var(--art-gold-2)] font-medium text-[var(--art-night)]'}`}
                        >
                            {t('Your market cart')}
                        </Link>
                    </SectionHero>
                )}

                {hero && !storefront && (category || layout === 'tech') && (
                    <header className="space-y-4">
                        <Breadcrumb sector={hero.slug} sectorName={heroTitle} category={category} />
                        <h1
                            style={{ fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" }}
                            className="text-4xl leading-none tracking-tight sm:text-6xl"
                        >
                            {category?.name ?? heroTitle}
                        </h1>
                        {category && (
                            <div className="lg:hidden">
                                <SubTiles sector={hero.slug} nodes={category.children} />
                            </div>
                        )}
                    </header>
                )}

                {!storefront && !hero && !category && filters.all && (
                    <header className="space-y-2">
                        <h1
                            style={{ fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" }}
                            className="text-4xl leading-none tracking-tight sm:text-6xl"
                        >
                            {t('All products')}
                        </h1>
                        <p className="tabular text-[var(--pt-muted)]">{t('{n} products', { n: num(total) })}</p>
                    </header>
                )}

                <div
                    id="all-products"
                    hidden={!!storefront}
                    className={tree.length > 0 ? 'grid scroll-mt-24 gap-8 lg:grid-cols-[14rem_minmax(0,1fr)]' : 'space-y-6'}
                >
                    {tree.length > 0 && filters.sector && (
                        <aside className="hidden lg:block">
                            <div className="sticky top-32">
                                <TreeNav sector={filters.sector} tree={tree} current={category} />
                            </div>
                        </aside>
                    )}
                    <div className="min-w-0 space-y-6">
                        <div className="space-y-3 border-b border-[var(--pt-line)] pb-5">
                            <div role="group" aria-label={t('Your budget')} className="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    aria-pressed={filters.from === null && !filters.premium}
                                    className={chip(filters.from === null && !filters.premium)}
                                    onClick={() => go({ from: null, to: null, premium: false })}
                                >
                                    {t('Any')}
                                </button>
                                {ranges.map((r) => (
                                    <button
                                        key={r.from}
                                        type="button"
                                        aria-pressed={filters.from === r.from && !filters.premium}
                                        className={chip(filters.from === r.from && !filters.premium)}
                                        onClick={() => go({ from: r.from, to: r.to, premium: false })}
                                    >
                                        {range(r)}
                                    </button>
                                ))}
                                {hasPremium && (
                                    <button
                                        type="button"
                                        aria-pressed={filters.premium}
                                        className={chip(filters.premium)}
                                        onClick={() => go({ premium: !filters.premium, from: null, to: null })}
                                    >
                                        {t('Premium')}
                                    </button>
                                )}
                            </div>
                            <div role="group" aria-label={t('Sort')} className="flex flex-wrap items-center gap-2 text-sm">
                                <span className="tabular me-2 text-[var(--pt-muted)]">{t('{n} products', { n: num(total) })}</span>
                                {(
                                    [
                                        ['near', 'Nearest and newest'],
                                        ['new', 'Newest'],
                                        ['low', 'Price, low to high'],
                                        ['high', 'Price, high to low'],
                                        ['popular', 'Most ordered'],
                                    ] as const
                                ).map(([k, label]) => (
                                    <button
                                        key={k}
                                        type="button"
                                        aria-pressed={filters.sort === k}
                                        className={chip(filters.sort === k)}
                                        onClick={() => go({ sort: k })}
                                    >
                                        {t(label)}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {items.length === 0 ? (
                            <p className="rounded-[1.75rem] border border-dashed border-[var(--pt-ink)] p-10 text-center text-[var(--pt-muted)]">
                                {t('Nothing here yet.')}
                            </p>
                        ) : layout === 'tech' ? (
                            <TechGrid items={items} />
                        ) : layout === 'fashion' ? (
                            <FashionGrid items={items} />
                        ) : (
                            <ul className="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-4">
                                {items.map((i) => (
                                    <li key={i.id} className="art-lift">
                                        <Link href={route('market.show', i.id)} className="block">
                                            <div className="art-zoom aspect-[4/5] rounded-t-full rounded-b-[1.25rem] bg-[var(--pt-line)]">
                                                {i.photo && (
                                                    <img src={i.photo} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
                                                )}
                                            </div>
                                            <p className="mt-3 line-clamp-2 leading-snug">{i.title}</p>
                                            <p
                                                style={{ fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" }}
                                                className="tabular mt-1 text-xl"
                                            >
                                                {money(i.price)} <span className="text-xs text-[var(--pt-muted)]">/ {unitLabel(i.unit)}</span>
                                            </p>
                                            <p className="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-[var(--pt-muted)]">
                                                <span>{i.seller}</span>
                                                {i.stars && (
                                                    <span className="tabular font-semibold text-[var(--pt-ink)]">
                                                        {num(i.stars.average)} / 5 ({num(i.stars.count)})
                                                    </span>
                                                )}
                                                {i.trusted && <span className="font-semibold text-[var(--pt-ink)]">{t('Trusted seller')}</span>}
                                                {(i.sellers ?? 1) > 1 && <span>{t('{n} sellers', { n: num(i.sellers ?? 1) })}</span>}
                                                {i.near && <span>{t('Near you')}</span>}
                                                {i.premium && <span className="font-semibold text-[var(--pt-ink)]">{t('Premium')}</span>}
                                            </p>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {total > perPage && (
                            <div className="flex justify-between">
                                <Button variant="outline" className="h-11 px-6" disabled={page <= 1} onClick={() => go({ page: page - 1 })}>
                                    {t('Previous')}
                                </Button>
                                <Button
                                    variant="outline"
                                    className="h-11 px-6"
                                    disabled={page * perPage >= total}
                                    onClick={() => go({ page: page + 1 })}
                                >
                                    {t('Next')}
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </PublicLayout>
    );
}
