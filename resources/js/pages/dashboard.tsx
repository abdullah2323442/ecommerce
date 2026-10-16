import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Info } from 'lucide-react';

interface Alert {
    level: 'warning' | 'info';
    title: string;
    detail: string;
    href: string | null;
    params: Record<string, string | number>;
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
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

function Stat({ label, value, note, tone }: { label: string; value: string; note: string; tone?: 'warn' }) {
    return (
        <div className="px-5 py-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className={`mt-1 text-3xl font-bold tracking-tight whitespace-nowrap tabular-nums ${tone === 'warn' ? 'text-[var(--destructive)]' : ''}`}>{value}</dd>
            <dd className="mt-1 text-sm text-muted-foreground">{note}</dd>
        </div>
    );
}

export default function Dashboard({ alerts, stats, recent }: Props) {
    const { t, num, isBn, date } = useI18n();
    const { customers, medicines, security, backup } = stats;
    const maxType = Math.max(1, ...Object.values(customers.by_type));

    const timeAgo = (iso: string | null): string => {
        if (!iso) return t('Never');
        const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
        const rtf = new Intl.RelativeTimeFormat(isBn ? 'bn' : 'en', { numeric: 'auto', style: 'short' });
        const steps: [Intl.RelativeTimeFormatUnit, number][] = [
            ['day', 86400],
            ['hour', 3600],
            ['minute', 60],
        ];
        for (const [unit, size] of steps) {
            if (Math.abs(seconds) >= size) return rtf.format(Math.round(seconds / size), unit);
        }
        return t('just now');
    };

    const fill = (params: Alert['params']) => Object.fromEntries(Object.entries(params ?? {}).map(([k, v]) => [k, typeof v === 'number' ? num(v) : v]));

    const typeLabel: Record<string, string> = { public: t('Public'), paikari: t('Paikari'), wholesaler: t('Wholesale') };

    const actionLabel: Record<string, string> = {
        'customer.tier_changed': t('changed a customer’s tier'),
        'customer.suspended': t('suspended an account'),
        'customer.reactivated': t('reactivated an account'),
        'customer.manual_rate_set': t('saved a manual rate'),
        'customer.manual_rate_removed': t('removed a manual rate'),
        'tier.created': t('created a tier'),
        'tier.updated': t('changed a tier’s discount'),
        'tier.deleted': t('deleted a tier'),
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Dashboard')} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <header className="relative overflow-hidden rounded-2xl border bg-card">
                    <div className="relative z-10 px-5 py-5">
                        <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Dashboard')}</h1>
                        <p className="text-sm text-muted-foreground">{date(new Date().toISOString(), { weekday: 'long', day: 'numeric', month: 'long' })}</p>
                    </div>
                </header>

                <section aria-labelledby="attention">
                    <h2 id="attention" className="mb-2 text-sm font-semibold">
                        {t('Needs attention')}
                    </h2>
                    {alerts.length === 0 ? (
                        <p className="flex items-center gap-2 rounded-xl border bg-card px-4 py-3 text-sm">
                            <CheckCircle2 aria-hidden className="size-4 text-emerald-600" />
                            {t('Nothing needs attention right now.')}
                        </p>
                    ) : (
                        <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                            {alerts.map((a) => (
                                <li key={a.title} className={`flex gap-3 border-l-4 px-4 py-3 ${a.level === 'warning' ? 'border-l-[var(--ring)]' : 'border-l-transparent'}`}>
                                    {a.level === 'warning' ? <AlertTriangle aria-hidden className="mt-0.5 size-4 shrink-0 text-[var(--ring)]" /> : <Info aria-hidden className="mt-0.5 size-4 shrink-0 text-muted-foreground" />}
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold">{t(a.title, fill(a.params))}</p>
                                        <p className="text-sm text-muted-foreground">{t(a.detail, fill(a.params))}</p>
                                    </div>
                                    {a.href && (
                                        <Link href={a.href} className="shrink-0 self-center text-sm font-medium underline underline-offset-4">
                                            {t('Open')}
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <dl className="grid grid-cols-2 divide-x divide-y overflow-hidden rounded-xl border bg-card md:grid-cols-4 md:divide-y-0">
                    <Stat label={t('Customers')} value={num(customers.total)} note={customers.suspended > 0 ? t('{n} suspended', { n: num(customers.suspended) }) : t('None suspended')} />
                    <Stat label={t('Medicines')} value={num(medicines.total)} note={t('{n} with a local photo', { n: num(medicines.local_image) })} />
                    <Stat
                        label={t('Suspicious logins')}
                        value={num(security.suspicious_7d)}
                        note={t('last 7 days · {n} failed today', { n: num(security.failed_24h) })}
                        tone={security.suspicious_7d > 0 ? 'warn' : undefined}
                    />
                    <Stat label={t('Last backup')} value={timeAgo(backup.latest_at)} note={backup.disk === 'local' ? t('Stored on this server') : t('Stored on {disk}', { disk: backup.disk })} />
                </dl>

                <div className="grid gap-6 md:grid-cols-5">
                    <section aria-labelledby="recent" className="md:col-span-3">
                        <h2 id="recent" className="mb-2 text-sm font-semibold">
                            {t('Recent activity')}
                        </h2>
                        {recent.length === 0 ? (
                            <p className="rounded-xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">{t('Nothing yet. Changes to tiers, rates and accounts will appear here.')}</p>
                        ) : (
                            <ul className="divide-y rounded-xl border bg-card">
                                {recent.map((r) => (
                                    <li key={r.id} className="flex items-baseline justify-between gap-4 px-4 py-3 text-sm">
                                        <span className="min-w-0">
                                            <strong className="font-semibold">{r.actor ?? t('The system')}</strong> {actionLabel[r.action] ?? r.action}
                                        </span>
                                        <time dateTime={r.at} className="shrink-0 text-muted-foreground">
                                            {timeAgo(r.at)}
                                        </time>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="types" className="md:col-span-2">
                        <h2 id="types" className="mb-2 text-sm font-semibold">
                            {t('Customers by type')}
                        </h2>
                        <ul className="space-y-4 rounded-xl border bg-card p-4">
                            {Object.entries(customers.by_type).map(([type, count]) => (
                                <li key={type}>
                                    <div className="flex items-baseline justify-between text-sm">
                                        <span>{typeLabel[type] ?? type}</span>
                                        <span className="font-semibold tabular-nums">{num(count)}</span>
                                    </div>
                                    <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-muted" role="presentation">
                                        <div className="h-full rounded-full bg-[var(--chart-1)]" style={{ width: `${(count / maxType) * 100}%` }} />
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <Link href="/admin/customers" className="mt-3 inline-block text-sm font-medium underline underline-offset-4">
                            {t('All customers')}
                        </Link>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
