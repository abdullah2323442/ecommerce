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
    month: string;
    seller: string;
    seller_id: number;
    orders: number;
    sales: number;
    commission: number;
    status: string;
    seller_note: string | null;
    our_note: string | null;
    sent_at: string;
    stopped: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Market', href: '/admin/market' },
    { title: 'Statements', href: '/admin/market/statements' },
];

function Card({ r, check }: { r: Row; check?: boolean }) {
    const { t, money, num, dateTime } = useI18n();
    const [note, setNote] = useState('');

    return (
        <li className="space-y-1 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {r.seller} · {r.month}
                </p>
                <p className="tabular font-semibold">{money(r.commission)}</p>
            </div>
            <p className="tabular text-xs text-muted-foreground">
                {t('{n} orders', { n: num(r.orders) })} · {t('Sold for {amount}', { amount: money(r.sales) })} · {t('Sent')} {dateTime(r.sent_at)}
            </p>
            {r.seller_note && <p className="text-sm">{r.seller_note}</p>}
            {r.stopped && (
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="rounded bg-red-100 px-1.5 py-0.5 font-semibold text-red-900">{t('Selling is switched off')}</span>
                    <Button size="sm" variant="outline" onClick={() => router.put(`/admin/market/sellers/${r.seller_id}/selling`, {}, { preserveScroll: true })}>
                        {t('Switch selling on')}
                    </Button>
                </div>
            )}
            {check && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" onClick={() => router.post(`/admin/market/statements/${r.id}/verify`, { note }, { preserveScroll: true })}>
                        {t('Checked, it is paid')}
                    </Button>
                    <Input aria-label={t('Our note')} className="h-8 w-64" placeholder={t('Our note (needed to send it back)')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => router.post(`/admin/market/statements/${r.id}/reopen`, { note }, { preserveScroll: true })}>
                        {t('Send it back')}
                    </Button>
                </div>
            )}
        </li>
    );
}

export default function MarketStatements({ waiting, unanswered, done, replyDays }: { waiting: Row[]; unanswered: Row[]; done: Row[]; replyDays: number }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [month, setMonth] = useState('');
    const section = (title: string, items: Row[], empty: string, check?: boolean) => (
        <section className="space-y-2">
            <h2 className="font-medium">{title}</h2>
            {items.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{empty}</p> : <ul className="divide-y rounded-md border bg-card">{items.map((r) => <Card key={r.id} r={r} check={check} />)}</ul>}
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Commission statements')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Commission statements')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Sent on the first of each month. No answer in {n} days switches that seller off.', { n: num(replyDays) })}</p>
                </div>
                <Flash />
                <InputError message={errors.statement ?? errors.month} />
                <div className="flex flex-wrap items-end gap-2">
                    <label className="text-sm">
                        {t('Send the statements for a month now')}
                        <input type="month" value={month} onChange={(e) => setMonth(e.target.value)} className="ml-2 h-9 rounded-md border bg-background px-2" />
                    </label>
                    <Button size="sm" variant="outline" disabled={month === ''} onClick={() => router.post('/admin/market/statements', { month }, { preserveScroll: true })}>
                        {t('Send')}
                    </Button>
                </div>
                {section(t('Answered: to check'), waiting, t('Nothing to check.'), true)}
                {section(t('Not answered'), unanswered, t('Everyone has answered.'))}
                {section(t('Checked'), done, t('Nothing checked yet.'))}
            </div>
        </AppLayout>
    );
}
