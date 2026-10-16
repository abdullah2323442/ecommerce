import Flash from '@/components/admin/flash';
import Pagination from '@/components/admin/pagination';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    number: string | null;
    kind: string;
    channel: string;
    status: string;
    customer: string | null;
    phone: string | null;
    total: number;
    paid: number;
    due: number;
    when: string | null;
    link: string;
}

interface Props {
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: { kind: string | null; channel: string | null; status: string | null; due: boolean; q: string; from: string | null; to: string | null };
    owed: number;
    canSettle: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'All orders', href: '/admin/invoices' }];

function Settle({ row }: { row: Row }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);
    const [amount, setAmount] = useState(String(row.due));
    const [method, setMethod] = useState('cash');

    if (!open) {
        return (
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                {t('Take payment')}
            </Button>
        );
    }

    return (
        <span className="flex flex-wrap items-center gap-1">
            <Input aria-label={t('Amount')} className="h-8 w-24" type="number" min="0" step="any" value={amount} onChange={(e) => setAmount(e.target.value)} />
            <select aria-label={t('Paid by')} value={method} onChange={(e) => setMethod(e.target.value)} className="bg-background h-8 rounded-md border px-1 text-sm">
                {['cash', 'bkash', 'nagad', 'rocket', 'bank', 'card'].map((m) => (
                    <option key={m} value={m}>
                        {t(m === 'bkash' ? 'bKash' : m === 'nagad' ? 'Nagad' : m === 'rocket' ? 'Rocket' : m === 'bank' ? 'Bank' : m === 'card' ? 'Card' : 'Cash')}
                    </option>
                ))}
            </select>
            <Button size="sm" onClick={() => router.post(`/admin/invoices/${row.id}/payment`, { amount, method }, { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                {t('Save')}
            </Button>
            <InputError message={errors.payment ?? errors.amount} />
        </span>
    );
}

export default function Invoices({ rows, filters, owed, canSettle }: Props) {
    const { t, money, dateTime } = useI18n();
    const [f, setF] = useState({ ...filters, due: filters.due ? '1' : '' });
    const go = (next: Record<string, string | null | undefined>) =>
        router.get('/admin/invoices', Object.fromEntries(Object.entries({ ...f, ...next }).filter(([, v]) => v)), { preserveState: true });
    const sel = 'bg-background h-9 rounded-md border px-2 text-sm';
    const kindName = (k: string) => (k === 'retail' ? 'Retail' : k === 'paikari' ? 'Shop' : 'Bulk');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('All orders')} />
            <div className="mx-auto w-full max-w-6xl space-y-4 p-4">
                <Flash />
                <header className="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('All orders')}</h1>
                        <p className="text-muted-foreground text-sm">{t('Every memo and order, counter and online. Find one by number, name or phone.')}</p>
                    </div>
                    {canSettle && owed > 0 && (
                        <button type="button" onClick={() => go({ due: '1' })} className="rounded-md border px-3 py-1.5 text-sm font-semibold">
                            {t('Owed to us')}: {money(owed)}
                        </button>
                    )}
                </header>

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({});
                    }}
                >
                    <Input aria-label={t('Search')} className="h-9 w-56" placeholder={t('Memo number, name or phone')} value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} />
                    <select aria-label={t('Kind')} className={sel} value={f.kind ?? ''} onChange={(e) => go({ kind: e.target.value })}>
                        <option value="">{t('All kinds')}</option>
                        <option value="retail">{t('Retail')}</option>
                        <option value="paikari">{t('Shop')}</option>
                        <option value="wholesale">{t('Bulk')}</option>
                    </select>
                    <select aria-label={t('Where')} className={sel} value={f.channel ?? ''} onChange={(e) => go({ channel: e.target.value })}>
                        <option value="">{t('Counter and online')}</option>
                        <option value="counter">{t('Counter')}</option>
                        <option value="online">{t('Online')}</option>
                    </select>
                    <select aria-label={t('Status')} className={sel} value={f.status ?? ''} onChange={(e) => go({ status: e.target.value })}>
                        <option value="">{t('Any state')}</option>
                        <option value="placed">{t('Being checked')}</option>
                        <option value="verified">{t('Ready')}</option>
                        <option value="final">{t('Done')}</option>
                        <option value="cancelled">{t('Cancelled')}</option>
                    </select>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={f.due === '1'} onChange={(e) => go({ due: e.target.checked ? '1' : '' })} />
                        {t('Still owed')}
                    </label>
                    <Input aria-label={t('From')} className="h-9 w-36" type="date" value={f.from ?? ''} onChange={(e) => setF({ ...f, from: e.target.value })} onBlur={() => go({})} />
                    <Input aria-label={t('To')} className="h-9 w-36" type="date" value={f.to ?? ''} onChange={(e) => setF({ ...f, to: e.target.value })} onBlur={() => go({})} />
                    <Button type="submit" variant="outline" className="h-9">
                        {t('Search')}
                    </Button>
                </form>

                {rows.data.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center text-sm">{t('Nothing found.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border text-sm">
                        {rows.data.map((r) => (
                            <li key={r.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2">
                                <Link href={r.link} className="w-28 font-mono text-xs underline underline-offset-2">
                                    {r.number ?? `#${r.id}`}
                                </Link>
                                <span className="text-muted-foreground w-36 text-xs">{r.when && dateTime(r.when)}</span>
                                <span className="min-w-36 flex-1">
                                    <span className="font-medium">{r.customer ?? '—'}</span> <span className="text-muted-foreground">{r.phone}</span>
                                </span>
                                <span className="text-muted-foreground w-28 text-xs">
                                    {t(kindName(r.kind))} · {t(r.channel === 'online' ? 'Online' : 'Counter')}
                                </span>
                                <span className="w-24 text-xs font-semibold">{t(r.status === 'placed' ? 'Being checked' : r.status === 'verified' ? 'Ready' : r.status === 'final' ? 'Done' : 'Cancelled')}</span>
                                <span className="tabular w-24 text-right">{money(r.total)}</span>
                                {r.due > 0 ? (
                                    <span className="flex items-center gap-2">
                                        <span className="tabular text-xs font-semibold text-red-600">
                                            {t('Owes')} {money(r.due)}
                                        </span>
                                        {canSettle && <Settle row={r} />}
                                    </span>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination links={rows.links} />
            </div>
        </AppLayout>
    );
}
