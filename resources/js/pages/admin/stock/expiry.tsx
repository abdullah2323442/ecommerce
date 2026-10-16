import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Check {
    medicine_id: number;
    brand_name: string;
    strength: string | null;
    company: string | null;
    on_hand: number;
    value: number;
    last_move: string | null;
    months_of_stock: number | null;
    reason: 'stagnant' | 'too_much';
}

interface Near {
    lot_id: number;
    medicine_id: number;
    brand_name: string;
    strength: string | null;
    company: string | null;
    units: number;
    expiry_date: string;
    days_left: number;
    unit_cost: number;
    safe_price: number;
    safe_percent_off: number | null;
}

interface Batch {
    lot_id: number;
    medicine_id: number;
    brand_name: string;
    strength: string | null;
    units: number;
    value: number;
    expiry_date: string | null;
    status: 'pending' | 'returned' | 'not_taken';
}

interface Company {
    company: string;
    units: number;
    value: number;
    lost: number;
    batches: Batch[];
}

interface Props {
    needsChecking: Check[];
    moneyAtRisk: number;
    nearExpiry: Near[];
    expired: Company[];
    settings: { stagnant_months: number; months_of_stock_limit: number; near_expiry_months: number };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock', href: '/admin/stock' },
    { title: 'Expiry', href: '/admin/stock/expiry' },
];

type Tab = 'check' | 'near' | 'expired';

function CheckRow({ row }: { row: Check }) {
    const { t, num, money, date } = useI18n();
    const [open, setOpen] = useState(false);
    const form = useForm({ medicine_id: row.medicine_id, expiry_date: '' });

    return (
        <li className="px-4 py-3">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                <div className="min-w-48 flex-1">
                    <Link href={`/admin/stock/medicines/${row.medicine_id}`} className="font-medium hover:underline">
                        {row.brand_name} <strong>{row.strength}</strong>
                    </Link>
                    <p className="text-sm text-muted-foreground">
                        {row.company} · {row.reason === 'stagnant' ? t('Nothing bought or sold since {date}', { date: row.last_move ? date(row.last_move) : '—' }) : t('{n} months of stock at the current pace', { n: num(row.months_of_stock ?? 0) })}
                    </p>
                </div>
                <p className="tabular text-right">
                    <span className="block font-medium">{money(row.value)}</span>
                    <span className="text-xs text-muted-foreground">{num(row.on_hand)}</span>
                </p>
                <Button variant="outline" size="sm" onClick={() => setOpen((o) => !o)}>
                    {t('Checked')}
                </Button>
            </div>
            {open && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/stock/expiry/checked', { preserveScroll: true });
                    }}
                    className="mt-3 flex flex-wrap items-end gap-2"
                >
                    <div>
                        <label htmlFor={`d-${row.medicine_id}`} className="text-sm font-medium">
                            {t('Expiry date (optional)')}
                        </label>
                        <Input id={`d-${row.medicine_id}`} type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} className="mt-1" />
                        <InputError message={(form.errors as Record<string, string>).expiry_date} />
                    </div>
                    <Button type="submit" loading={form.processing}>
                        {t('Still good, keep it')}
                    </Button>
                </form>
            )}
        </li>
    );
}

export default function StockExpiry({ needsChecking, moneyAtRisk, nearExpiry, expired, settings }: Props) {
    const { t, num, money, date } = useI18n();
    const [tab, setTab] = useState<Tab>('check');
    const form = useForm(settings);

    const tabs: { id: Tab; label: string; count: number }[] = [
        { id: 'check', label: t('Needs checking'), count: needsChecking.length },
        { id: 'near', label: t('Near expiry'), count: nearExpiry.length },
        { id: 'expired', label: t('Expired'), count: expired.reduce((n, c) => n + c.batches.length, 0) },
    ];

    const expire = (lotId: number, name: string) => {
        if (window.confirm(t('Move {name} to the expired section? It leaves the stock.', { name }))) router.post(`/admin/stock/lots/${lotId}/expire`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Expiry')} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Expiry')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Goods that move every month need no expiry entry. Only goods that sit still are a risk.')}</p>
                </div>

                <Flash />

                {moneyAtRisk > 0 && (
                    <p className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 font-medium">{t('Goods worth {money} need checking.', { money: money(moneyAtRisk) })}</p>
                )}

                <div role="tablist" aria-label={t('Expiry')} className="flex flex-wrap gap-2">
                    {tabs.map((x) => (
                        <button
                            key={x.id}
                            role="tab"
                            aria-selected={tab === x.id}
                            onClick={() => setTab(x.id)}
                            className={`min-h-10 rounded-full px-4 text-sm font-semibold ${tab === x.id ? 'bg-foreground text-background' : 'border hover:bg-muted'}`}
                        >
                            {x.label} <span className="tabular opacity-70">{num(x.count)}</span>
                        </button>
                    ))}
                </div>

                {tab === 'check' &&
                    (needsChecking.length === 0 ? (
                        <p className="rounded-md border border-dashed p-10 text-center text-muted-foreground">{t('Nothing needs checking right now.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card">
                            {needsChecking.map((r) => (
                                <CheckRow key={r.medicine_id} row={r} />
                            ))}
                        </ul>
                    ))}

                {tab === 'near' &&
                    (nearExpiry.length === 0 ? (
                        <p className="rounded-md border border-dashed p-10 text-center text-muted-foreground">{t('No lot with a date is close to expiry.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card">
                            {nearExpiry.map((n) => (
                                <li key={n.lot_id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                                    <div className="min-w-48 flex-1">
                                        <p className="font-medium">
                                            {n.brand_name} <strong>{n.strength}</strong>
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {n.company} · {n.days_left < 0 ? t('Expired on {date}', { date: date(n.expiry_date) }) : t('Expires {date} ({n} days)', { date: date(n.expiry_date), n: num(n.days_left) })}
                                        </p>
                                    </div>
                                    <p className="tabular text-right text-sm">
                                        <span className="block font-medium">{t('Lowest safe price {price}', { price: money(n.safe_price) })}</span>
                                        {n.safe_percent_off !== null && <span className="text-muted-foreground">{t('{n}% off MRP', { n: num(n.safe_percent_off) })}</span>}
                                    </p>
                                    <Button variant="outline" size="sm" onClick={() => expire(n.lot_id, n.brand_name)}>
                                        {t('Expired')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    ))}

                {tab === 'expired' &&
                    (expired.length === 0 ? (
                        <p className="rounded-md border border-dashed p-10 text-center text-muted-foreground">{t('Nothing has expired.')}</p>
                    ) : (
                        <div className="space-y-4">
                            {expired.map((c) => (
                                <section key={c.company} className="rounded-xl border bg-card">
                                    <header className="flex flex-wrap items-baseline justify-between gap-2 border-b px-4 py-3">
                                        <h2 className="font-display text-xl font-bold">{c.company}</h2>
                                        <p className="tabular text-sm text-muted-foreground">
                                            {t('{units} units, {value}', { units: num(c.units), value: money(c.value) })}
                                            {c.lost > 0 && <span className="ml-2 font-semibold text-destructive">{t('Lost {value}', { value: money(c.lost) })}</span>}
                                        </p>
                                    </header>
                                    <ul className="divide-y">
                                        {c.batches.map((b) => (
                                            <li key={b.lot_id} className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-2.5 text-sm">
                                                <span className="min-w-48 flex-1 font-medium">
                                                    {b.brand_name} <strong>{b.strength}</strong>
                                                </span>
                                                <span className="tabular">{money(b.value)}</span>
                                                <select
                                                    aria-label={t('Return status')}
                                                    value={b.status}
                                                    onChange={(e) => router.put(`/admin/stock/lots/${b.lot_id}/return-status`, { status: e.target.value }, { preserveScroll: true })}
                                                    className="h-9 rounded-md border bg-background px-2 text-sm"
                                                >
                                                    <option value="pending">{t('Pending')}</option>
                                                    <option value="returned">{t('Returned to company representative')}</option>
                                                    <option value="not_taken">{t('Not taken back')}</option>
                                                </select>
                                            </li>
                                        ))}
                                    </ul>
                                </section>
                            ))}
                        </div>
                    ))}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/stock/expiry/settings', { preserveScroll: true });
                    }}
                    className="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-4"
                >
                    {(
                        [
                            ['stagnant_months', t('Stagnant after (months)')],
                            ['months_of_stock_limit', t('Too much stock above (months)')],
                            ['near_expiry_months', t('Near expiry within (months)')],
                        ] as const
                    ).map(([key, label]) => (
                        <div key={key}>
                            <label htmlFor={key} className="text-sm font-medium">
                                {label}
                            </label>
                            <Input id={key} type="number" min="1" value={form.data[key]} onChange={(e) => form.setData(key, Number(e.target.value))} className="mt-1" />
                            <InputError message={(form.errors as Record<string, string>)[key]} />
                        </div>
                    ))}
                    <div className="flex items-end">
                        <Button type="submit" variant="secondary" loading={form.processing}>
                            {t('Save settings')}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
