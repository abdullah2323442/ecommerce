import { WasPrice } from '@/components/market/sale-price';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import PageHead from '@/components/shell/page-head';
import { Link, router, usePage } from '@inertiajs/react';
import { unitLabel } from '@/lib/units';

interface Item {
    id: number;
    title: string;
    price: number;
    was?: number | null;
    unit: string;
    photo: string | null;
    sector_name: string;
}

interface Shop {
    id: number;
    name: string;
    area: string | null;
    months: number;
    rating: 'New' | 'Excellent' | 'Good' | 'Needs care';
    followers: number;
    following: boolean;
    can_follow: boolean;
    paused: boolean;
}

/** A shop's own page (spec ch 13): its products, how it is rated, how long it has been here, and Follow. */
export default function MarketShop({ shop, items }: { shop: Shop; items: Item[] }) {
    const { t, money, num } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const here = shop.months >= 12 ? t('{n} years here', { n: num(Math.floor(shop.months / 12)) }) : shop.months >= 1 ? t('{n} months here', { n: num(shop.months) }) : t('New here');

    return (
        <PublicLayout>
            <PageHead title={shop.name} description={`${shop.name} · ${shop.rating}`} />
            <div className="mx-auto max-w-4xl space-y-6">
                <Link href="/market" className="text-sm underline underline-offset-4">
                    {t('Back to the market')}
                </Link>
                <header className="flex flex-wrap items-end justify-between gap-4 rounded-[1.5rem] border bg-[var(--pt-surface)] p-5">
                    <div className="space-y-1">
                        <h1 className="font-display text-3xl font-extrabold">{shop.name}</h1>
                        <p className="tabular text-sm text-[var(--pt-muted)]">
                            {t(shop.rating)} · {here}
                            {shop.area && ` · ${shop.area}`} · {t('{n} follow', { n: num(shop.followers) })}
                        </p>
                        {shop.paused && <p className="text-sm text-warning">{t('This shop is on holiday. New orders are off.')}</p>}
                    </div>
                    {shop.can_follow && (
                        <Button variant={shop.following ? 'outline' : 'default'} onClick={() => (shop.following ? router.delete(`/shop/${shop.id}/follow`, { preserveScroll: true }) : router.post(`/shop/${shop.id}/follow`, {}, { preserveScroll: true }))}>
                            {shop.following ? t('Following') : t('Follow')}
                        </Button>
                    )}
                </header>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}

                {items.length === 0 ? (
                    <p className="rounded-[1.5rem] border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('No products to show now.')}</p>
                ) : (
                    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        {items.map((i) => (
                            <li key={i.id}>
                                <Link href={`/market/${i.id}`} className="block space-y-2">
                                    {i.photo ? <img src={i.photo} alt="" className="aspect-square w-full rounded-[1.25rem] border border-[var(--pt-line)] object-cover" /> : <div aria-hidden className="aspect-square w-full rounded-[1.25rem] bg-[var(--pt-sage)]" />}
                                    <p className="text-sm font-semibold">{i.title}</p>
                                    <p className="tabular text-sm">
                                        <WasPrice was={i.was} className="text-xs" />
                                        {money(i.price)} <span className="text-[var(--pt-muted)]">/ {unitLabel(i.unit)}</span>
                                    </p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
