import SellerBrand from '@/components/seller-brand';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

interface Product {
    id: number;
    title: string;
    views: number;
    orders: number;
    sold: number;
    earned: number;
    left: number | null;
    low: boolean;
    cost: number | null;
    profit: number | null;
    hint: 'photo' | 'price' | null;
}

interface Month {
    month: string;
    sales: number;
    commission: number;
    orders: number;
    change: number | null;
}

interface Rank {
    score: number;
    proven: boolean;
    ship_hours: number | null;
    return_rate: number;
    missing_rate: number;
    finished: number;
    label: 'New' | 'Excellent' | 'Good' | 'Needs care';
}

/** The seller's own business, in plain words (spec ch 14: when sellers understand their business they do better). */
export default function Reports({ products, months, rank, proven, businesses, business }: { products: Product[]; months: Month[]; rank: Rank; proven: number; businesses: { slug: string; name: string }[]; business: string | null }) {
    const { t, money, num, date } = useI18n();
    const best = Math.max(1, ...months.map((m) => m.sales));
    const top = products.find((p) => p.earned > 0);
    // The best and the slowest by how many were sold, and what is running low (spec ch 15: basic stock for sellers).
    const bySold = [...products].sort((a, b) => b.sold - a.sold);
    const topSold = bySold.filter((p) => p.sold > 0).slice(0, 3);
    const slowest = [...products].sort((a, b) => a.sold - b.sold).slice(0, 3).filter((p) => !topSold.some((b) => b.id === p.id) || products.length <= 3);
    const low = products.filter((p) => p.low);

    return (
        <PublicLayout>
            <Head title={t('How you are doing')} />
            <div className="mx-auto max-w-3xl space-y-8">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <SellerBrand />
                    <h1 className="font-display text-3xl font-extrabold">{t('How you are doing')}</h1>
                    <Link href="/wholesale/sell" className="text-sm font-semibold underline underline-offset-4">
                        {t('Your listings')}
                    </Link>
                </div>

                {businesses.length > 1 && (
                    <nav aria-label={t('Your businesses')} className="flex flex-wrap gap-2 text-sm">
                        <Link href="/wholesale/sell/reports" className={`rounded-full border px-3 py-1 ${business === null ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)] font-semibold' : ''}`}>
                            {t('All together')}
                        </Link>
                        {businesses.map((b) => (
                            <Link key={b.slug} href={`/wholesale/sell/reports?business=${b.slug}`} className={`rounded-full border px-3 py-1 ${business === b.slug ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)] font-semibold' : ''}`}>
                                {b.name}
                            </Link>
                        ))}
                    </nav>
                )}

                <section className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="font-display text-xl font-bold">{t('Your rank')}</h2>
                        <p className="tabular text-2xl font-extrabold">
                            {rank.proven ? num(rank.score) : '–'} <span className="text-sm font-semibold text-[var(--pt-muted)]">{t(rank.label)}</span>
                        </p>
                    </div>
                    <p className="text-sm text-[var(--pt-muted)]">{t('Sellers who send fast and have few returns are shown higher.')}</p>
                    {rank.proven ? (
                        <ul className="tabular text-sm">
                            <li>{rank.ship_hours !== null ? t('You send an order in about {n} hours.', { n: num(Math.round(rank.ship_hours)) }) : t('No sent orders yet.')}</li>
                            <li>{t('Sent back: {n}%', { n: num(rank.return_rate) })}</li>
                            <li>{t('Buyer said it did not arrive: {n}%', { n: num(rank.missing_rate) })}</li>
                        </ul>
                    ) : (
                        <p className="text-sm">{t('Your rank starts after {n} finished orders.', { n: num(proven) })}</p>
                    )}
                </section>

                {low.length > 0 && (
                    <section role="alert" className="space-y-1 rounded-[1.5rem] border border-warning/40 bg-warning/10 p-4 text-sm">
                        <h2 className="font-semibold">{t('Running low')}</h2>
                        <ul className="tabular">
                            {low.map((p) => (
                                <li key={p.id}>
                                    {p.title}: {t('{n} left', { n: num(p.left ?? 0) })}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {products.length > 1 && (
                    <section className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                            <h2 className="font-semibold">{t('Sells the most')}</h2>
                            {topSold.length === 0 ? <p className="text-[var(--pt-muted)]">{t('Nothing sold yet.')}</p> : <ol className="tabular list-decimal ps-5">{topSold.map((p) => <li key={p.id}>{p.title} · {num(p.sold)}</li>)}</ol>}
                        </div>
                        <div className="space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                            <h2 className="font-semibold">{t('Sells the least')}</h2>
                            <ol className="tabular list-decimal ps-5">
                                {slowest.map((p) => (
                                    <li key={p.id}>
                                        {p.title} · {num(p.sold)}
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Your products')}</h2>
                    {top && (
                        <p className="text-sm">
                            {t('Earns the most')}: <strong>{top.title}</strong> ({money(top.earned)})
                        </p>
                    )}
                    {products.length === 0 ? (
                        <p className="rounded-[1.5rem] border border-dashed p-8 text-center text-[var(--pt-muted)]">{t('No published products yet.')}</p>
                    ) : (
                        <ul className="space-y-2">
                            {products.map((p) => (
                                <li key={p.id} className="space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-3 text-sm">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <p className="font-semibold">{p.title}</p>
                                        <p className="tabular font-semibold">{money(p.earned)}</p>
                                    </div>
                                    <p className="tabular text-xs text-[var(--pt-muted)]">
                                        {t('Looked at by {n} people', { n: num(p.views) })} · {t('{n} orders', { n: num(p.orders) })} · {t('Sold')} {num(p.sold)}
                                    </p>
                                    {p.left !== null && (
                                        <p className={`tabular text-xs ${p.low ? 'font-semibold text-warning' : 'text-[var(--pt-muted)]'}`}>
                                            {t('{n} left', { n: num(p.left) })}
                                            {p.profit !== null && ` · ${t('You made {amount} after your own cost', { amount: money(p.profit) })}`}
                                        </p>
                                    )}
                                    {p.left === null && p.profit !== null && <p className="tabular text-xs text-[var(--pt-muted)]">{t('You made {amount} after your own cost', { amount: money(p.profit) })}</p>}
                                    {p.hint && <p className="rounded-md bg-warning/10 p-2 text-warning">{p.hint === 'photo' ? t('People look at this but nobody orders. It has no picture. Add one.') : t('People look at this but nobody orders. Look at the price.')}</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Month by month')}</h2>
                    <ul className="space-y-1">
                        {months.map((m) => (
                            <li key={m.month} className="grid grid-cols-[7rem_1fr_auto] items-center gap-3 text-sm">
                                <span>{date(`${m.month}-15T12:00:00`, { month: 'short', year: 'numeric' })}</span>
                                <span className="h-3 rounded-full bg-muted">
                                    <span className="block h-3 rounded-full bg-[var(--pt-ink)]" style={{ width: `${(m.sales / best) * 100}%` }} />
                                </span>
                                <span className="tabular text-end">
                                    {money(m.sales)} {m.change !== null && m.change > 0 && <strong className="text-success">▲ {num(m.change)}%</strong>}
                                    {m.change !== null && m.change < 0 && <span className="text-[var(--pt-muted)]">▼ {num(Math.abs(m.change))}%</span>}
                                    {m.commission > 0 && <span className="block text-xs text-[var(--pt-muted)]">{t('Commission owed: {amount}', { amount: money(m.commission) })}</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </PublicLayout>
    );
}
