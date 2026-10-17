import { Bars, Delta, Donut, Spark, StackedArea } from '@/components/admin/charts';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info } from 'lucide-react';
import { type ReactNode } from 'react';

interface Alert {
    level: 'warning' | 'info';
    title: string;
    detail: string;
    href: string | null;
    params: Record<string, string | number>;
}

interface Kpi {
    value: number;
    was: number;
}

interface Commerce {
    days: number;
    kpis: { revenue: Kpi; orders: Kpi; average: Kpi; customers: Kpi };
    series: ({ day: string; orders: number } & Record<string, number | string>)[];
    sources: { key: string; label: string; money: number; orders: number }[];
    categories: { name: string; name_bn: string | null; money: number }[];
    pipeline: { waiting: number; moving: number; done: number; cancelled: number };
    payment: { online: number; cash: number };
    recent: { source: string; label: string; total: number; status: string; at: string; href: string | null }[];
    open: { tickets: number; listings_waiting: number; refunds_due: number };
}

interface Props {
    alerts: Alert[];
    stats: {
        customers: { total: number; by_type: Record<string, number>; suspended: number; without_tier: number };
        medicines: { total: number; local_image: number; remote_image: number; no_image: number; flagged: number };
        security: { suspicious_7d: number; failed_24h: number; blocked_ips: number };
        backup: { latest_at: string | null; disk: string };
    };
    recent: { id: number; action: string; actor: string | null; at: string }[];
    commerce: Commerce;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

/** One colour for each part of the business, used the same way in every chart on the page. */
const COLORS: Record<string, string> = { pharmacy: '#2dd4bf', market: '#c9a24b', food: '#fb7185', games: '#818cf8', custom: '#94a3b8' };

function Card({ title, hint, children, className = '' }: { title?: string; hint?: string; children: ReactNode; className?: string }) {
    return (
        <section className={`glass rounded-3xl p-5 sm:p-6 ${className}`}>
            {title && (
                <header className="mb-4">
                    <h2 className="classic text-xl font-semibold tracking-tight">{title}</h2>
                    {hint && <p className="mt-0.5 text-xs opacity-60">{hint}</p>}
                </header>
            )}
            {children}
        </section>
    );
}

export default function Dashboard({ alerts, stats, recent, commerce }: Props) {
    const { t, num, money, isBn, date } = useI18n();
    const { customers, medicines, security, backup } = stats;
    const { kpis, series, sources, categories, pipeline, payment, open } = commerce;
    const sourceLabel = (key: string, label: string) => t(label);

    const short = (n: number) => (n >= 1_000_000 ? `${num(Math.round(n / 100_000) / 10)}M` : n >= 1000 ? `${num(Math.round(n / 100) / 10)}k` : num(Math.round(n)));
    const dayLabel = (iso: string) => date(`${iso}T00:00:00`, { day: 'numeric', month: 'short' });
    const keys = sources.filter((s) => s.money > 0).map((s) => ({ key: s.key, label: sourceLabel(s.key, s.label), color: COLORS[s.key] }));
    const daily = (k: 'orders' | 'revenue') => series.map((r) => (k === 'orders' ? Number(r.orders) : sources.reduce((a, s) => a + Number(r[s.key] ?? 0), 0)));
    const total = sources.reduce((a, s) => a + s.money, 0);
    const journey = [
        { key: 'waiting', label: t('Waiting'), color: '#c9a24b', n: pipeline.waiting },
        { key: 'moving', label: t('On the way'), color: '#818cf8', n: pipeline.moving },
        { key: 'done', label: t('Completed'), color: '#2dd4bf', n: pipeline.done },
        { key: 'cancelled', label: t('Cancelled'), color: '#fb7185', n: pipeline.cancelled },
    ];
    const journeyTotal = Math.max(1, journey.reduce((a, j) => a + j.n, 0));
    const payTotal = payment.online + payment.cash;

    const timeAgo = (iso: string | null): string => {
        if (!iso) return t('Never');
        const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
        const rtf = new Intl.RelativeTimeFormat(isBn ? 'bn' : 'en', { numeric: 'auto', style: 'short' });
        for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]] as [Intl.RelativeTimeFormatUnit, number][]) {
            if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
        }

        return t('just now');
    };

    const fill = (params: Alert['params']) => Object.fromEntries(Object.entries(params ?? {}).map(([k, v]) => [k, typeof v === 'number' ? num(v) : v]));
    const statusWord = (s: string) => t(({ placed: 'Waiting', verified: 'Checked', final: 'Completed', accepted: 'Accepted', shipped: 'On the way', delivered: 'Completed', cancelled: 'Cancelled' } as Record<string, string>)[s] ?? 'In progress');
    const typeLabel: Record<string, string> = { public: t('Public'), paikari: t('Paikari'), wholesaler: t('Wholesale') };

    const kpiCards = [
        { label: t('Sales'), value: money(kpis.revenue.value), k: kpis.revenue, spark: daily('revenue'), color: '#c9a24b' },
        { label: t('Orders'), value: num(kpis.orders.value), k: kpis.orders, spark: daily('orders'), color: '#2dd4bf' },
        { label: t('Average order'), value: money(kpis.average.value), k: kpis.average, spark: daily('revenue').map((v, i) => (Number(series[i]?.orders) > 0 ? v / Number(series[i].orders) : 0)), color: '#818cf8' },
        { label: t('New customers'), value: num(kpis.customers.value), k: kpis.customers, spark: [], color: '#fb7185' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Dashboard')} />

            <div className="glass-stage">
                <div className="relative z-10 mx-auto w-full max-w-7xl space-y-5 p-4 md:p-8">
                    <header className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h1 className="classic text-4xl font-semibold tracking-tight sm:text-5xl">{t('Dashboard')}</h1>
                            <p className="mt-1 text-sm opacity-65">{date(new Date().toISOString(), { weekday: 'long', day: 'numeric', month: 'long' })}</p>
                        </div>
                        <p className="glass rounded-full px-4 py-1.5 text-xs">{t('Last {n} days, all shops', { n: num(commerce.days) })}</p>
                    </header>

                    <dl className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                        {kpiCards.map((c) => (
                            <div key={c.label} className="glass rounded-3xl p-4 sm:p-5">
                                <dt className="text-xs opacity-65">{c.label}</dt>
                                <dd className="classic mt-1 text-[1.3rem] leading-tight font-semibold tabular-nums sm:text-3xl">{c.value}</dd>
                                <dd className="mt-2 flex flex-wrap items-end justify-between gap-2">
                                    <Delta now={c.k.value} was={c.k.was} label={t('vs the 30 days before')} />
                                    {c.spark.length > 1 && <Spark values={c.spark} color={c.color} />}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card title={t('Sales by day')} hint={t('Every shop, stacked')} className="lg:col-span-2">
                            {keys.length === 0 ? <p className="py-10 text-center text-sm opacity-60">{t('No sales in this period yet.')}</p> : <StackedArea rows={series} series={keys} format={money} short={short} dayLabel={dayLabel} />}
                            <ul className="mt-3 flex flex-wrap gap-x-5 gap-y-1.5 text-xs">
                                {keys.map((k) => (
                                    <li key={k.key} className="flex items-center gap-1.5">
                                        <span aria-hidden className="size-2.5 rounded-full" style={{ background: k.color }} />
                                        {k.label}
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        <Card title={t('Where sales come from')} hint={t('Share of the last {n} days', { n: num(commerce.days) })}>
                            <Donut
                                parts={sources.filter((s) => s.money > 0).map((s) => ({ key: s.key, label: sourceLabel(s.key, s.label), value: s.money, color: COLORS[s.key] }))}
                                center={short(total)}
                                sub={t('in sales')}
                                format={money}
                            />
                        </Card>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card title={t('Best-selling categories')} hint={t('Market, by sales')}>
                            {categories.length === 0 ? <p className="py-6 text-center text-sm opacity-60">{t('Nothing sold yet.')}</p> : <Bars rows={categories.map((c) => ({ label: isBn && c.name_bn ? c.name_bn : c.name, value: c.money }))} format={money} color="#c9a24b" />}
                        </Card>

                        <Card title={t('Order journey')} hint={t('Where this month’s orders are')}>
                            <div className="flex h-3 overflow-hidden rounded-full bg-current/10" role="img" aria-label={journey.map((j) => `${j.label} ${j.n}`).join(', ')}>
                                {journey.map((j) => (
                                    <span key={j.key} style={{ width: `${(j.n / journeyTotal) * 100}%`, background: j.color }} />
                                ))}
                            </div>
                            <ul className="mt-5 grid grid-cols-2 gap-x-4 gap-y-4">
                                {journey.map((j) => (
                                    <li key={j.key}>
                                        <p className="flex items-center gap-1.5 text-xs opacity-70">
                                            <span aria-hidden className="size-2.5 rounded-full" style={{ background: j.color }} />
                                            {j.label}
                                        </p>
                                        <p className="classic text-2xl font-semibold tabular-nums">{num(j.n)}</p>
                                    </li>
                                ))}
                            </ul>
                        </Card>

                        <Card title={t('How people pay')} hint={t('Online against cash on delivery')}>
                            <Donut
                                parts={[
                                    { key: 'online', label: t('Online'), value: payment.online, color: '#2dd4bf' },
                                    { key: 'cash', label: t('Cash on delivery'), value: payment.cash, color: '#c9a24b' },
                                ]}
                                center={payTotal > 0 ? `${num(Math.round((payment.online / payTotal) * 100))}%` : '–'}
                                sub={t('paid online')}
                                format={money}
                            />
                        </Card>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card title={t('Latest orders')} hint={t('Every shop, newest first')} className="lg:col-span-2">
                            {commerce.recent.length === 0 ? (
                                <p className="py-6 text-center text-sm opacity-60">{t('No orders yet.')}</p>
                            ) : (
                                <ul className="divide-y divide-current/10">
                                    {commerce.recent.map((o, i) => {
                                        const row = (
                                            <div className="flex items-center gap-3 py-2.5 text-sm">
                                                <span aria-hidden className="size-2.5 shrink-0 rounded-full" style={{ background: COLORS[o.source] }} />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate font-medium">{o.label}</span>
                                                    <span className="block text-xs opacity-60">
                                                        {sourceLabel(o.source, sources.find((s) => s.key === o.source)?.label ?? o.source)} · {timeAgo(o.at)}
                                                    </span>
                                                </span>
                                                <span className="hidden rounded-full bg-current/10 px-2.5 py-0.5 text-xs sm:inline">{statusWord(o.status)}</span>
                                                <span className="w-24 text-right font-medium tabular-nums">{money(o.total)}</span>
                                            </div>
                                        );

                                        return <li key={i}>{o.href ? <Link href={o.href} className="block rounded-lg hover:bg-current/5">{row}</Link> : row}</li>;
                                    })}
                                </ul>
                            )}
                        </Card>

                        <Card title={t('Needs attention')}>
                            <ul className="mb-4 grid grid-cols-3 gap-2 text-center">
                                {[
                                    { label: t('Open requests'), n: open.tickets, href: '/admin/support' },
                                    { label: t('Products to approve'), n: open.listings_waiting, href: '/admin/market' },
                                    { label: t('Refunds due'), n: open.refunds_due, href: '/admin/payments' },
                                ].map((x) => (
                                    <li key={x.label}>
                                        <Link href={x.href} className="block rounded-2xl bg-current/5 px-1 py-3 hover:bg-current/10">
                                            <span className="classic block text-2xl font-semibold tabular-nums">{num(x.n)}</span>
                                            <span className="block text-[11px] leading-tight opacity-65">{x.label}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                            {alerts.length === 0 ? (
                                <p className="flex items-center gap-2 text-sm">
                                    <CheckCircle2 aria-hidden className="size-4 text-emerald-500" />
                                    {t('Nothing needs attention right now.')}
                                </p>
                            ) : (
                                <ul className="space-y-3">
                                    {alerts.slice(0, 4).map((a) => (
                                        <li key={a.title} className="flex gap-2.5 text-sm">
                                            {a.level === 'warning' ? <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0 text-amber-500" /> : <Info aria-hidden className="mt-0.5 size-4 shrink-0 opacity-60" />}
                                            <div className="min-w-0 flex-1">
                                                <p className="font-medium">{t(a.title, fill(a.params))}</p>
                                                <p className="text-xs opacity-65">{t(a.detail, fill(a.params))}</p>
                                                {a.href && (
                                                    <Link href={a.href} className="text-xs font-medium underline underline-offset-4">
                                                        {t('Open')}
                                                    </Link>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3">
                        <Card title={t('Customers')}>
                            <p className="classic text-4xl font-semibold tabular-nums">{num(customers.total)}</p>
                            <p className="mb-4 text-xs opacity-60">{customers.suspended > 0 ? t('{n} suspended', { n: num(customers.suspended) }) : t('None suspended')}</p>
                            <Bars rows={Object.entries(customers.by_type).map(([k, v]) => ({ label: typeLabel[k] ?? k, value: v }))} format={num} color="#818cf8" />
                            <Link href="/admin/customers" className="mt-4 inline-block text-sm font-medium underline underline-offset-4">
                                {t('All customers')}
                            </Link>
                        </Card>

                        <Card title={t('Catalogue and safety')}>
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <dt className="text-xs opacity-65">{t('Medicines')}</dt>
                                    <dd className="classic text-2xl font-semibold tabular-nums">{num(medicines.total)}</dd>
                                    <dd className="text-xs opacity-60">{t('{n} with a local photo', { n: num(medicines.local_image) })}</dd>
                                </div>
                                <div>
                                    <dt className="text-xs opacity-65">{t('Suspicious logins')}</dt>
                                    <dd className={`classic text-2xl font-semibold tabular-nums ${security.suspicious_7d > 0 ? 'text-rose-500' : ''}`}>{num(security.suspicious_7d)}</dd>
                                    <dd className="text-xs opacity-60">{t('last 7 days · {n} failed today', { n: num(security.failed_24h) })}</dd>
                                </div>
                                <div className="col-span-2">
                                    <dt className="text-xs opacity-65">{t('Last backup')}</dt>
                                    <dd className="font-medium">{timeAgo(backup.latest_at)}</dd>
                                    <dd className="text-xs opacity-60">{backup.disk === 'local' ? t('Stored on this server') : t('Stored on {disk}', { disk: backup.disk })}</dd>
                                </div>
                            </dl>
                        </Card>

                        <Card title={t('Recent activity')}>
                            {recent.length === 0 ? (
                                <p className="py-4 text-sm opacity-60">{t('Nothing yet. Changes to tiers, rates and accounts will appear here.')}</p>
                            ) : (
                                <ul className="space-y-2.5 text-sm">
                                    {recent.slice(0, 6).map((r) => (
                                        <li key={r.id} className="flex items-baseline justify-between gap-3">
                                            <span className="min-w-0 truncate">
                                                <strong className="font-medium">{r.actor ?? t('The system')}</strong> <span className="opacity-70">{r.action}</span>
                                            </span>
                                            <time dateTime={r.at} className="shrink-0 text-xs opacity-60">
                                                {timeAgo(r.at)}
                                            </time>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
