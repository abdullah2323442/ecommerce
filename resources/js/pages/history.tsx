import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

interface Row {
    kind: 'medicine' | 'market' | 'food' | 'game' | 'custom';
    title: string;
    number: string | null;
    state: 'open' | 'done' | 'cancelled';
    total: number;
    at: string;
    link: string;
}

const kindName = { medicine: 'Medicine', market: 'Market', food: 'Food', game: 'Game top-up', custom: 'Made to order' } as const;

/** Everything the buyer has ordered, in one list (spec ch 13). */
export default function History({ orders }: { orders: Row[] }) {
    const { t, money, dateTime } = useI18n();
    const state = (s: Row['state']) => t(s === 'open' ? 'In progress' : s === 'done' ? 'Done' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('All your orders')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('All your orders')}</h1>
                {orders.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('No orders yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {orders.map((o) => (
                            <li key={o.link}>
                                <Link href={o.link} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <span className="min-w-40 flex-1">
                                        <span className="block font-medium">{o.title}</span>
                                        <span className="text-xs text-[var(--pt-muted)]">{t(kindName[o.kind])}{o.number ? ` · ${o.number}` : ''}</span>
                                    </span>
                                    <span className="text-sm text-[var(--pt-muted)]">{dateTime(o.at)}</span>
                                    <span className="text-sm font-semibold">{state(o.state)}</span>
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
