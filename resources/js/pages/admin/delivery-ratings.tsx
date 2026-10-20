import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Avg {
    id: number;
    name: string;
    average: number;
    count: number;
    worst: number;
}

interface Low {
    id: number;
    person: string | null;
    staff: string | null;
    delivery: number;
    service: number;
    comment: string | null;
    at: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Delivery ratings', href: '/admin/delivery-ratings' }];

function Board({ title, rows }: { title: string; rows: Avg[] }) {
    const { t, num } = useI18n();

    return (
        <section className="space-y-2">
            <h2 className="font-medium">{title}</h2>
            {rows.length === 0 ? (
                <p className="rounded-md border border-dashed p-5 text-center text-sm text-muted-foreground">{t('No rating yet.')}</p>
            ) : (
                <ul className="divide-y rounded-md border bg-card text-sm">
                    {rows.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2">
                            <span className="font-medium">{r.name}</span>
                            <span className="tabular text-muted-foreground">{t('{n} ratings', { n: num(r.count) })}</span>
                            <span className={`tabular font-bold ${r.average < 3 ? 'text-danger' : ''}`}>★ {num(r.average)}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function DeliveryRatings({ people, staff, low }: { people: Avg[]; staff: Avg[]; low: Low[] }) {
    const { t, num, dateTime } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Delivery ratings')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="text-2xl font-bold">{t('Delivery ratings')}</h1>
                    <p className="text-sm text-muted-foreground">{t('What buyers thought of the delivery and the service. Buyers never see these names.')}</p>
                </div>
                <Board title={t('Delivery people')} rows={people} />
                <Board title={t('Staff')} rows={staff} />
                {low.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Low ratings')}</h2>
                        <ul className="divide-y rounded-md border border-danger/40 bg-card text-sm">
                            {low.map((l) => (
                                <li key={l.id} className="space-y-0.5 px-4 py-2">
                                    <p className="tabular">
                                        {t('Delivery')} {num(l.delivery)}★ · {t('Service')} {num(l.service)}★ · {l.person ?? '—'} / {l.staff ?? '—'}
                                    </p>
                                    {l.comment && <p>{l.comment}</p>}
                                    <p className="text-xs text-muted-foreground">{dateTime(l.at)}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
