import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    buyer: string;
    request: string;
    quantity: string | null;
    status: string;
    total: number | null;
    note: string | null;
    valid_until: string | null;
    at: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Price requests', href: '/admin/quotes' }];

function Card({ r, answer }: { r: Row; answer?: boolean }) {
    const { t, money, date, dateTime } = useI18n();
    const [total, setTotal] = useState(r.total !== null ? String(r.total) : '');
    const [days, setDays] = useState('7');
    const [note, setNote] = useState(r.note ?? '');

    return (
        <li className="space-y-1 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {r.buyer} · {r.request}
                    {r.quantity && ` · ${r.quantity}`}
                </p>
                <span className="text-xs text-muted-foreground">{dateTime(r.at)}</span>
            </div>
            {r.total !== null && (
                <p className="tabular text-sm">
                    {money(r.total)}
                    {r.valid_until && ` · ${t('holds until {date}', { date: date(r.valid_until) })}`}
                </p>
            )}
            {answer && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('Total price')} className="h-8 w-32" inputMode="decimal" placeholder={t('Total price')} value={total} onChange={(e) => setTotal(e.target.value)} />
                    <Input aria-label={t('Days it holds')} className="h-8 w-20" inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} />
                    <Input aria-label={t('Note')} className="h-8 w-64" placeholder={t('A note for the buyer (optional)')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button size="sm" disabled={Number(total) <= 0} onClick={() => router.post(`/admin/quotes/${r.id}/answer`, { total, days, note }, { preserveScroll: true })}>
                        {t('Send the price')}
                    </Button>
                </div>
            )}
        </li>
    );
}

export default function Quotes({ asking, accepted, quoted, closed }: { asking: Row[]; accepted: Row[]; quoted: Row[]; closed: Row[] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const section = (title: string, items: Row[], empty: string, answer?: boolean) => (
        <section className="space-y-2">
            <h2 className="font-medium">{title}</h2>
            {items.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{empty}</p> : <ul className="divide-y rounded-md border bg-card">{items.map((r) => <Card key={r.id} r={r} answer={answer} />)}</ul>}
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Price requests')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Price requests')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Bulk buyers ask for a price. Send one total and the days it holds. When they accept, place the order at that price.')}</p>
                </div>
                <Flash />
                <InputError message={errors.quote} />
                {section(t('Waiting for a price'), asking, t('No requests waiting.'), true)}
                {section(t('Accepted: place the order'), accepted, t('Nothing accepted.'))}
                {section(t('With the buyer'), quoted, t('Nothing with a buyer.'), true)}
                {section(t('Closed'), closed, t('Nothing closed.'))}
            </div>
        </AppLayout>
    );
}
