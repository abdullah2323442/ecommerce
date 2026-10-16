import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

interface Row {
    token: string;
    number: string;
    restaurant: string;
    status: string;
    total: number;
    at: string;
}

export default function FoodOrders({ orders }: { orders: Row[] }) {
    const { t, money, dateTime } = useI18n();
    const label = (s: string) => t(s === 'pending' ? 'Pending' : s === 'confirmed' ? 'Confirmed: cooking' : s === 'packing' ? 'Packing' : s === 'out' ? 'Out for delivery' : s === 'ready' ? 'Ready' : s === 'delivered' ? 'Delivered' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('Your food orders')} />
            <div className="mx-auto max-w-3xl space-y-4">
                <h1 className="font-display text-3xl font-extrabold">{t('Your food orders')}</h1>
                {orders.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('Nothing ordered yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {orders.map((o) => (
                            <li key={o.token}>
                                <Link href={`/food/orders/${o.token}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <span className="min-w-32 flex-1 font-medium">
                                        {o.restaurant} · {o.number}
                                    </span>
                                    <span className="text-sm text-[var(--pt-muted)]">{dateTime(o.at)}</span>
                                    <span className="text-sm font-semibold">{label(o.status)}</span>
                                    <span className="tabular w-24 text-right">{money(o.total)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
