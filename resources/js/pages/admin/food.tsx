import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Sums {
    orders: number;
    sales: number;
    commission: number;
    net: number;
}

interface Restaurant {
    id: number;
    name: string;
    area: string;
    owner: string;
    phone: string | null;
    status: string;
    open: boolean;
    commission_kind: 'percent' | 'taka';
    commission_value: number;
    sales: { today: Sums; month: Sums; all: Sums };
    active_orders: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Restaurants', href: '/admin/food' }];

function Row({ r }: { r: Restaurant }) {
    const { t, money, num } = useI18n();
    const [kind, setKind] = useState(r.commission_kind);
    const [value, setValue] = useState(String(r.commission_value));

    return (
        <li className="space-y-2 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {r.name} · {r.area} · {r.owner}
                    {r.status === 'suspended' && <span className="ms-2 rounded bg-danger/15 px-1.5 py-0.5 text-xs font-semibold text-danger">{t('Suspended')}</span>}
                    {r.status === 'active' && <span className={`ms-2 rounded px-1.5 py-0.5 text-xs font-semibold ${r.open ? 'bg-success/15 text-success' : 'bg-muted'}`}>{r.open ? t('Open now') : t('Closed now')}</span>}
                </p>
                <p className="tabular text-xs text-muted-foreground">{t('{n} orders in hand', { n: num(r.active_orders) })}</p>
            </div>
            <dl className="tabular grid grid-cols-2 gap-2 sm:grid-cols-4">
                {(
                    [
                        ['Sold this month', r.sales.month.sales],
                        ['Our commission this month', r.sales.month.commission],
                        ['Commission in all', r.sales.all.commission],
                        ['They receive this month', r.sales.month.net],
                    ] as const
                ).map(([label, v]) => (
                    <div key={label}>
                        <dt className="text-xs text-muted-foreground">{t(label)}</dt>
                        <dd className="font-semibold">{money(v)}</dd>
                    </div>
                ))}
            </dl>
            <div className="flex flex-wrap items-center gap-2">
                <select aria-label={t('Commission kind')} value={kind} onChange={(e) => setKind(e.target.value as 'percent' | 'taka')} className="h-8 rounded-md border bg-background px-2 text-xs">
                    <option value="percent">{t('Percent of the food')}</option>
                    <option value="taka">{t('Taka for each order')}</option>
                </select>
                <Input aria-label={t('Commission')} className="h-8 w-24" inputMode="decimal" value={value} onChange={(e) => setValue(e.target.value)} />
                <Button size="sm" variant="outline" onClick={() => router.put(`/admin/food/${r.id}/commission`, { kind, value }, { preserveScroll: true })}>
                    {t('Set the commission')}
                </Button>
                <Button size="sm" variant={r.status === 'active' ? 'destructive' : 'outline'} onClick={() => router.put(`/admin/food/${r.id}/suspend`, { on: r.status === 'active' ? 1 : 0 }, { preserveScroll: true })}>
                    {r.status === 'active' ? t('Suspend') : t('Bring back')}
                </Button>
            </div>
        </li>
    );
}

export default function Food({ restaurants, orders, month }: { restaurants: Restaurant[]; orders: { id: number; number: string; restaurant: string; status: string; mode: string; total: number; commission: number; at: string; complaint: string | null; has_complaint_photo: boolean }[]; month: string }) {
    const { t, money, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', owner_name: '', phone: '', password: '', area: '', areas: '', address: '', commission_kind: 'percent', commission_value: '10' });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Restaurants')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('Restaurants')}</h1>
                        <p className="text-sm text-muted-foreground">{t('They sell; we take a commission we set by hand for each one, on delivered orders only.')}</p>
                    </div>
                    <label className="text-sm">
                        {t('Month')}
                        <input type="month" value={month} onChange={(e) => router.get('/admin/food', { month: e.target.value }, { preserveState: true })} className="ms-2 h-9 rounded-md border bg-background px-2" />
                    </label>
                </div>
                <Flash />
                <InputError message={errors.food} />

                {restaurants.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No restaurants yet.')}</p> : <ul className="divide-y rounded-md border bg-card">{restaurants.map((r) => <Row key={`${r.id}-${r.commission_value}-${r.status}`} r={r} />)}</ul>}

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Open a restaurant')}</h2>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {(
                            [
                                ['name', 'Restaurant name'],
                                ['owner_name', 'Owner name'],
                                ['phone', 'Phone'],
                                ['area', 'Its area'],
                                ['areas', 'Other areas it delivers to, with commas'],
                                ['address', 'Address'],
                            ] as const
                        ).map(([k, l]) => (
                            <label key={k} className="text-xs">
                                {t(l)}
                                <Input className="mt-1 h-8" value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
                            </label>
                        ))}
                        <label className="text-xs">
                            {t('Password (10 or more)')}
                            <Input className="mt-1 h-8" type="password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
                        </label>
                        <label className="text-xs">
                            {t('Commission kind')}
                            <select value={f.commission_kind} onChange={(e) => setF({ ...f, commission_kind: e.target.value })} className="mt-1 block h-8 w-full rounded-md border bg-background px-2 text-sm">
                                <option value="percent">{t('Percent of the food')}</option>
                                <option value="taka">{t('Taka for each order')}</option>
                            </select>
                        </label>
                        <label className="text-xs">
                            {t('Commission')}
                            <Input className="mt-1 h-8" inputMode="decimal" value={f.commission_value} onChange={(e) => setF({ ...f, commission_value: e.target.value })} />
                        </label>
                    </div>
                    <Button size="sm" disabled={f.name.trim() === '' || f.owner_name.trim() === '' || f.area.trim() === '' || f.password.length < 10} onClick={() => router.post('/admin/food', { ...f, areas: f.areas.split(',').map((a) => a.trim()).filter(Boolean) }, { preserveScroll: true, onSuccess: () => setF({ ...f, name: '', owner_name: '', phone: '', password: '', area: '', areas: '', address: '' }) })}>
                        {t('Open')}
                    </Button>
                </section>

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Latest orders')}</h2>
                    <ul className="divide-y rounded-md border bg-card text-sm">
                        {orders.map((o) => (
                            <li key={o.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2">
                                <span className="min-w-32 font-medium">
                                    {o.number} · {o.restaurant}
                                </span>
                                <span className="text-xs text-muted-foreground">{dateTime(o.at)}</span>
                                <span>{o.status}</span>
                                <span className="tabular ms-auto">{money(o.total)}</span>
                                {o.commission > 0 && <span className="tabular text-xs text-muted-foreground">{t('Commission')} {money(o.commission)}</span>}
                                {o.complaint && (
                                    <span className="basis-full rounded bg-danger/10 p-1 text-xs text-danger">
                                        {o.complaint}{' '}
                                        {o.has_complaint_photo && (
                                            <a className="underline" href={`/admin/food/orders/${o.id}/photo`} target="_blank" rel="noreferrer">
                                                {t('Photo')}
                                            </a>
                                        )}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </AppLayout>
    );
}
