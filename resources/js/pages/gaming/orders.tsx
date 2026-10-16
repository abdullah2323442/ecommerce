import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

interface Row {
    token: string;
    number: string;
    game: string;
    package: string;
    price: number;
    status: string;
    at: string;
}

export default function GamingOrders({ orders }: { orders: Row[] }) {
    const { t, money, dateTime } = useI18n();
    const label = (s: string) => t(s === 'placed' ? 'Pay' : s === 'claimed' ? 'We check the payment' : s === 'paid' ? 'Top-up in progress' : s === 'topped_up' ? 'Topped up' : s === 'failed' ? 'Failed' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('Your top-ups')} />
            <div className="mx-auto max-w-3xl space-y-4">
                <h1 className="font-display text-3xl font-extrabold">{t('Your top-ups')}</h1>
                {orders.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('Nothing ordered yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {orders.map((o) => (
                            <li key={o.token}>
                                <Link href={`/gaming/orders/${o.token}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <span className="min-w-32 flex-1 font-medium">
                                        {o.game} · {o.package}
                                    </span>
                                    <span className="text-sm text-[var(--pt-muted)]">{dateTime(o.at)}</span>
                                    <span className="text-sm font-semibold">{label(o.status)}</span>
                                    <span className="tabular w-24 text-right">{money(o.price)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
