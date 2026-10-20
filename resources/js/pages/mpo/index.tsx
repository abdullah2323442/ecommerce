import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { noticeText } from '@/lib/preorder';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Listing {
    id: number;
    medicine_id: number;
    name: string;
    status: string;
    stock_status: string;
    arrive_days: number | null;
    quantity: number | null;
    bonus_buy: number | null;
    bonus_free: number | null;
    review_note: string | null;
    bids: { id: number; alias: string; percent: number; boxes: number }[];
    percent: number | null;
}

interface Props {
    companies: { id: number; name: string }[];
    chosen: { id: number; name: string; company: string | null }[];
    listings: Listing[];
    notices: { id: number; kind: string; body: string }[];
    failed: number;
    orders: { id: number; medicine: string; quantity: number; bonus: number; status: string; when: string; due_on: string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My products', href: '/mpo' }];

function Picker({ company, chosenIds }: { company: { id: number; name: string }; chosenIds: number[] }) {
    const { t } = useI18n();
    const [q, setQ] = useState('');
    const [rows, setRows] = useState<{ id: number; name: string; form: string | null }[]>([]);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) return;
        const timer = setTimeout(() => fetch(`/mpo/companies/${company.id}/medicines?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((d) => setRows(d.results)).catch(() => setRows([])), 200);
        return () => clearTimeout(timer);
    }, [open, q, company.id]);

    return (
        <div className="space-y-2 rounded-md border p-3">
            <div className="flex items-center justify-between gap-2">
                <p className="font-medium">{company.name}</p>
                <Button size="sm" variant="outline" onClick={() => setOpen(!open)}>
                    {open ? t('Close') : t('Choose products')}
                </Button>
            </div>
            {open && (
                <>
                    <Input aria-label={t('Search')} className="h-9" placeholder={t('Medicine name')} value={q} onChange={(e) => setQ(e.target.value)} />
                    <ul className="max-h-64 divide-y overflow-auto rounded-md border text-sm">
                        {rows.map((m) => {
                            const on = chosenIds.includes(m.id);

                            return (
                                <li key={m.id} className="flex items-center gap-3 px-3 py-1.5">
                                    <span className="flex-1">
                                        {m.name} <span className="text-muted-foreground">{m.form}</span>
                                    </span>
                                    <Button size="sm" variant={on ? 'ghost' : 'outline'} onClick={() => router.post('/mpo/products', on ? { remove: [m.id] } : { add: [m.id] }, { preserveScroll: true })}>
                                        {on ? t('Remove') : t('Add')}
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}
        </div>
    );
}

function Submit({ chosen }: { chosen: Props['chosen'] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ medicine_id: '', stock_status: 'in_stock', arrive_days: '3', quantity: '', bonus: false, bonus_buy: '10', bonus_free: '2' });

    return (
        <section className="space-y-3 rounded-lg border bg-card p-4">
            <h2 className="font-display text-lg font-extrabold">{t('Send a product')}</h2>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm sm:col-span-2">
                    {t('Product')}
                    <select value={f.medicine_id} onChange={(e) => setF({ ...f, medicine_id: e.target.value })} className="bg-background mt-1 block h-9 w-full rounded-md border px-2 text-sm">
                        <option value="">{t('Choose')}</option>
                        {chosen.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name} · {c.company}
                            </option>
                        ))}
                    </select>
                </label>
                <fieldset className="text-sm">
                    <legend>{t('Stock')}</legend>
                    <div className="mt-2 flex gap-4">
                        {[['in_stock', 'In stock'], ['pre_order', 'Pre-order']].map(([v, label]) => (
                            <label key={v} className="flex items-center gap-2">
                                <input type="radio" name="st" checked={f.stock_status === v} onChange={() => setF({ ...f, stock_status: v })} />
                                {t(label)}
                            </label>
                        ))}
                    </div>
                </fieldset>
                {f.stock_status === 'pre_order' ? (
                    <label className="text-sm">
                        {t('Arrives in')}
                        <select value={f.arrive_days} onChange={(e) => setF({ ...f, arrive_days: e.target.value })} className="bg-background mt-1 block h-9 w-full rounded-md border px-2 text-sm">
                            {[2, 3, 4, 5].map((d) => (
                                <option key={d} value={d}>
                                    {t('{n} days', { n: d })}
                                </option>
                            ))}
                        </select>
                    </label>
                ) : (
                    <label className="text-sm">
                        {t('How many boxes')}
                        <Input className="mt-1 h-9" type="number" min="0" value={f.quantity} onChange={(e) => setF({ ...f, quantity: e.target.value })} />
                    </label>
                )}
                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                    <input type="checkbox" checked={f.bonus} onChange={(e) => setF({ ...f, bonus: e.target.checked })} />
                    {t('Give a bonus')}
                </label>
                {f.bonus && (
                    <div className="flex items-center gap-2 text-sm sm:col-span-2">
                        <Input aria-label={t('Buy')} className="h-9 w-20" type="number" min="1" value={f.bonus_buy} onChange={(e) => setF({ ...f, bonus_buy: e.target.value })} />
                        <span>+</span>
                        <Input aria-label={t('Free')} className="h-9 w-20" type="number" min="1" value={f.bonus_free} onChange={(e) => setF({ ...f, bonus_free: e.target.value })} />
                        <span className="text-muted-foreground">{t('Take {buy}, get {free} free', { buy: f.bonus_buy, free: f.bonus_free })}</span>
                    </div>
                )}
            </div>
            <InputError message={errors.listing} />
            <Button
                disabled={!f.medicine_id}
                onClick={() =>
                    router.post(
                        '/mpo/listings',
                        {
                            medicine_id: f.medicine_id,
                            stock_status: f.stock_status,
                            arrive_days: f.stock_status === 'pre_order' ? f.arrive_days : null,
                            quantity: f.stock_status === 'in_stock' && f.quantity !== '' ? f.quantity : null,
                            bonus_buy: f.bonus ? f.bonus_buy : null,
                            bonus_free: f.bonus ? f.bonus_free : null,
                        },
                        { preserveScroll: true },
                    )
                }
            >
                {t('Send to us')}
            </Button>
        </section>
    );
}

function Bids({ l }: { l: Listing }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [raise, setRaise] = useState('');

    if (l.status !== 'approved') return null;

    return (
        <div className="w-full space-y-2 rounded-md bg-muted/40 p-2">
            {l.bids.length > 0 ? (
                <ul className="space-y-1">
                    {l.bids.map((b) => (
                        <li key={b.id} className="flex items-center gap-3">
                            <span className="flex-1">
                                {t('Anonymous')} {b.alias.replace('Anonymous ', '')}: {num(b.percent)}% · {num(b.boxes)} {t('boxes')}
                            </span>
                            <Button size="sm" onClick={() => confirm(t('Accept this offer? The other offers are declined.')) && router.post(`/mpo/bids/${b.id}/accept`, {}, { preserveScroll: true })}>
                                {t('Accept')}
                            </Button>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-muted-foreground">{t('No offers on this yet.')}</p>
            )}
            {l.percent !== null && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground">{t('Percentage now: {p}%', { p: num(l.percent) })}</span>
                    <Input aria-label={t('New percentage')} className="h-8 w-24" type="number" min="1" max="90" step="any" value={raise} onChange={(e) => setRaise(e.target.value)} />
                    <Button size="sm" variant="outline" disabled={raise === ''} onClick={() => router.post(`/mpo/listings/${l.id}/raise`, { percent: raise }, { preserveScroll: true })}>
                        {t('Raise the percentage')}
                    </Button>
                    <InputError message={errors.listing} />
                </div>
            )}
        </div>
    );
}

export default function MpoHome({ companies, chosen, listings, orders, notices, failed }: Props) {
    const { t, num, dateTime } = useI18n();
    const state = (s: string) => t(s === 'submitted' ? 'Waiting for us' : s === 'approved' ? 'On the market' : s === 'rejected' ? 'Not accepted' : 'Taken off');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('My products')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('My products')}</h1>
                    <p className="text-muted-foreground text-sm">{t('Choose the products you work with, then send them. We look at each one and set the price.')}</p>
                </header>

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Your companies')}</h2>
                    {companies.map((c) => (
                        <Picker key={c.id} company={c} chosenIds={chosen.map((x) => x.id)} />
                    ))}
                </section>

                {notices.map((n) => (
                    <div key={n.id} role="status" className="flex items-center gap-3 rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm">
                        <p className="flex-1 font-semibold">{noticeText(n, t)}</p>
                        <Button size="sm" variant="ghost" onClick={() => router.post(`/notices/${n.id}/read`, {}, { preserveScroll: true })}>
                            {t('Close')}
                        </Button>
                    </div>
                ))}
                {failed > 0 && <p className="text-sm text-danger">{t('Orders you did not deliver in time: {n}', { n: num(failed) })}</p>}

                <Submit chosen={chosen} />

                <section className="space-y-2">
                    <h2 className="font-medium">{t('What you sent')}</h2>
                    {listings.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-5 text-center text-sm">{t('Nothing sent yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border text-sm">
                            {listings.map((l) => (
                                <li key={l.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2">
                                    <span className="min-w-40 flex-1 font-medium">{l.name}</span>
                                    <span>{l.stock_status === 'pre_order' ? t('Pre-order · {n} days', { n: num(l.arrive_days ?? 0) }) : t('In stock')}</span>
                                    {l.bonus_buy && (
                                        <span>
                                            {num(l.bonus_buy)}+{num(l.bonus_free ?? 0)}
                                        </span>
                                    )}
                                    <span className={`font-semibold ${l.status === 'rejected' ? 'text-danger' : ''}`}>{state(l.status)}</span>
                                    {l.review_note && <span className="text-muted-foreground">{l.review_note}</span>}
                                    <Bids l={l} />
                                    {(l.status === 'submitted' || l.status === 'approved') && (
                                        <Button size="sm" variant="ghost" onClick={() => router.delete(`/mpo/listings/${l.id}`, { preserveScroll: true })}>
                                            {t('Take off')}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-2">
                    <div>
                        <h2 className="font-medium">{t('Orders')}</h2>
                        <p className="text-muted-foreground text-xs">{t('These orders came from our account. This record is your proof for your own target report.')}</p>
                    </div>
                    {orders.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-5 text-center text-sm">{t('No orders yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border text-sm">
                            {orders.map((o) => (
                                <li key={o.id} className="flex flex-wrap items-center gap-x-4 px-3 py-2">
                                    <span className="text-muted-foreground w-36 text-xs">{dateTime(o.when)}</span>
                                    <span className="min-w-40 flex-1 font-medium">{o.medicine}</span>
                                    <span>
                                        {num(o.quantity)} {t('boxes')}
                                        {o.bonus > 0 && ` + ${num(o.bonus)} ${t('free')}`}
                                    </span>
                                    <span className="font-semibold">{t(o.status === 'fulfilled' ? 'Taken' : o.status === 'cancelled' ? 'Cancelled' : 'Confirmed')}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
