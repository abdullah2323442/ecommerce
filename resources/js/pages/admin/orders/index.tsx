import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';

interface Order {
    id: number;
    status: 'placed' | 'verified';
    cancel_requested: boolean;
    customer: string | null;
    phone: string | null;
    lines: number;
    minutes: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Online orders', href: '/admin/orders' }];

interface Missed {
    name: string;
    strength: string | null;
    times: number;
    quantity: number;
}

export default function Orders({ orders, missed }: { orders: Order[]; missed: Missed[] }) {
    const { t, num } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Online orders')} />
            <div className="mx-auto w-full max-w-4xl space-y-4 p-4">
                <h1 className="font-display text-2xl font-extrabold">{t('Online orders')}</h1>
                {orders.length === 0 ? (
                    <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No orders waiting.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border">
                        {orders.map((o) => (
                            <li key={o.id}>
                                <Link href={`/admin/orders/${o.id}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-muted/50">
                                    <div className="min-w-40 flex-1">
                                        <p className="font-medium">{o.customer}</p>
                                        <p className="text-sm text-muted-foreground">{o.phone}</p>
                                    </div>
                                    <p className="tabular text-sm">{t('{n} items', { n: num(o.lines) })}</p>
                                    <p className={`tabular text-sm ${o.minutes > 60 ? 'font-semibold text-warning' : 'text-muted-foreground'}`}>{t('{n} min open', { n: num(o.minutes) })}</p>
                                    <p className="text-sm font-semibold">{o.cancel_requested ? <span className="text-danger">{t('Wants to cancel')}</span> : t(o.status === 'placed' ? 'To check' : 'Checked')}</p>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {missed.length > 0 && (
                    <section aria-labelledby="missed-h" className="space-y-2">
                        <h2 id="missed-h" className="font-medium">
                            {t('Asked for but not available (30 days)')}
                        </h2>
                        <ul className="divide-y rounded-md border">
                            {missed.map((m) => (
                                <li key={`${m.name}-${m.strength}`} className="flex items-center gap-4 px-4 py-2 text-sm">
                                    <span className="min-w-40 flex-1 font-medium">
                                        {m.name} <span className="font-normal text-muted-foreground">{m.strength}</span>
                                    </span>
                                    <span className="tabular">{t('{n} times', { n: num(m.times) })}</span>
                                    <span className="tabular w-20 text-right">{num(Number(m.quantity))}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
