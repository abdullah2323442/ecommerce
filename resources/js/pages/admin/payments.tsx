import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Row {
    id: number;
    ref: string;
    amount: number;
    status: string;
    live: boolean;
    method: string | null;
    order: string | null;
    order_id: number | null;
    market?: boolean;
    at: string;
    risky: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Online payments', href: '/admin/payments' }];

function List({ rows, refund }: { rows: Row[]; refund?: boolean }) {
    const { t, money, dateTime } = useI18n();

    return (
        <ul className="divide-y rounded-md border bg-card text-sm">
            {rows.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2">
                    {p.order_id ? (
                        <Link href={`/admin/orders/${p.order_id}`} className="min-w-28 font-medium hover:underline">
                            {p.order}
                        </Link>
                    ) : (
                        <span className="min-w-28 font-medium">{p.order}</span>
                    )}
                    <span className="tabular w-24 text-end font-semibold">{money(p.amount)}</span>
                    <span className="text-muted-foreground">{p.method ?? '—'}</span>
                    {!p.live && <span className="rounded bg-warning/15 px-1.5 text-xs font-semibold text-warning">{t('Practice')}</span>}
                    {p.risky && <span className="rounded bg-danger/15 px-1.5 text-xs font-semibold text-danger">{t('Looks risky')}</span>}
                    <span className="text-xs text-muted-foreground">{dateTime(p.at)}</span>
                    <span className="text-xs font-semibold">{t(p.status === 'paid' ? 'Paid' : p.status === 'refunded' ? 'Refunded' : p.status === 'refund_due' ? 'Refund due' : p.status === 'cancelled' ? 'Cancelled' : 'Failed')}</span>
                    {refund && (
                        <Button size="sm" variant="outline" onClick={() => window.confirm(t('Did you refund {amount} in the payment company\'s panel?', { amount: money(p.amount) })) && router.post(`/admin/payments/${p.id}/refunded`, {}, { preserveScroll: true })}>
                            {t('I refunded it')}
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}

interface Payout {
    seller_id: number;
    seller: string;
    orders: number;
    amount: number;
}

export default function Payments({ refunds, recent, failed, today, enabled, payouts }: { refunds: Row[]; recent: Row[]; failed: Row[]; today: number; enabled: boolean; payouts: Payout[] }) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Online payments')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="text-2xl font-bold">{t('Online payments')}</h1>
                    <p className="tabular text-sm">
                        {t('Received today')}: <strong>{money(today)}</strong>
                    </p>
                </div>
                {!enabled && <p className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">{t('Online payment is not switched on. Set it up in Connections.')}</p>}
                <InputError message={errors.payment} />

                {refunds.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium text-danger">{t('To refund')}</h2>
                        <p className="text-sm text-muted-foreground">{t('These orders were cancelled after they were paid. Refund them in the payment company\'s panel, then press the button.')}</p>
                        <List rows={refunds} refund />
                    </section>
                )}

                {payouts.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('To pay to sellers')}</h2>
                        <p className="text-sm text-muted-foreground">{t('Buyers paid us for these sales and the goods were delivered. Pay each seller what is left after our commission, then press the button.')}</p>
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {payouts.map((s) => (
                                <li key={s.seller_id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2">
                                    <span className="min-w-40 flex-1 font-medium">{s.seller}</span>
                                    <span className="text-muted-foreground">{t('{n} orders', { n: num(s.orders) })}</span>
                                    <span className="tabular w-28 text-end font-semibold">{money(s.amount)}</span>
                                    <Button size="sm" variant="outline" onClick={() => window.confirm(t('Did you pay {amount} to {seller}?', { amount: money(s.amount), seller: s.seller })) && router.post(`/admin/payments/payout/${s.seller_id}`, {}, { preserveScroll: true })}>
                                        {t('I paid the seller')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Received')}</h2>
                    {recent.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No online payment yet.')}</p> : <List rows={recent} />}
                </section>

                {failed.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Did not go through, last 7 days')}</h2>
                        <List rows={failed} />
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
