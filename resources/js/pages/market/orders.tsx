import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

export default function MarketOrders({ orders }: { orders: { token: string; number: string; title: string; status: string; total: number; at: string }[] }) {
    const { t, money, dateTime } = useI18n();
    const state = (s: string) => t(s === 'placed' ? 'Sent' : s === 'accepted' ? 'Accepted' : s === 'shipped' ? 'On the way' : s === 'delivered' ? 'Done' : s === 'not_received' ? 'Not received' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('Your market orders')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Your market orders')}</h1>
                    <Link href="/market-offers" className="text-sm font-semibold underline underline-offset-4">
                        {t('Your price offers')}
                    </Link>
                </div>
                {orders.length === 0 ? (
                    <p className="rounded-[1.5rem] border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('No orders yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-[1.5rem] border bg-[var(--pt-surface)]">
                        {orders.map((o) => (
                            <li key={o.token}>
                                <Link href={`/market/orders/${o.token}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <span className="min-w-40 flex-1 font-medium">{o.title}</span>
                                    <span className="text-sm text-[var(--pt-muted)]">{dateTime(o.at)}</span>
                                    <span className="text-sm font-semibold">{state(o.status)}</span>
                                    <span className="tabular w-28 text-right">{money(o.total)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
