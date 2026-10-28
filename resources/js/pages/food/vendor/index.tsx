import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Line {
    name: string;
    price: number;
    quantity: number;
    note: string | null;
    ingredients: { base: { name: string; price: number }; items: { name: string; price: number }[] } | null;
}

interface Order {
    id: number;
    number: string;
    status: string;
    mode: 'delivery' | 'eat_there';
    ship_to: { name?: string; phone?: string; area?: string; address?: string; how_to_find?: string };
    eat_time: string | null;
    note: string | null;
    lines: Line[];
    total: number;
    subtotal: number;
    delivery_charge: number;
    charge_open: boolean;
    advance_due: number;
    advance_paid: number;
    minutes: number | null;
    ready_at: string | null;
    placed_at: string;
    commission: number;
    complaint: string | null;
    has_complaint_photo: boolean;
    complaint_reply: string | null;
    cancel_reason: string | null;
    cooking: boolean;
    suggest_minutes: number;
}

interface Settings {
    name: string;
    description: string | null;
    phone: string | null;
    address: string | null;
    area: string;
    areas: string[];
    is_open: boolean;
    opens_at: string;
    closes_at: string;
    closed_days: number[];
    delivery_by: 'own' | 'platform';
    charge_mode: 'area' | 'distance' | 'manual';
    per_km: number;
    max_km: number | null;
    lat: number | null;
    lng: number | null;
    charge_inside: number;
    charge_outside: number;
    min_charge: number;
    eat_there: boolean;
    advance_over: number | null;
    advance_percent: number;
    status: string;
    commission_kind: 'percent' | 'taka';
    commission_value: number;
}

interface Item {
    id: number;
    name: string;
    description: string | null;
    price: number;
    category?: string | null;
    cook_minutes: number;
    ready_made: boolean;
    available: boolean;
    sold_out_today: boolean;
    has_photo: boolean;
}

interface Sums {
    orders: number;
    sales: number;
    commission: number;
    net: number;
}

interface Props {
    restaurant: Settings;
    openNow: boolean;
    orders: Order[];
    together: { area: string; numbers: string[] }[];
    recent: Order[];
    sales: { today: Sums; month: Sums; all: Sums };
    items: Item[];
    days: number[];
    roadByGoogle: boolean;
    mapOn: boolean;
}

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function OrderCard({ o }: { o: Order }) {
    const { t, money, num, dateTime } = useI18n();
    const [minutes, setMinutes] = useState(String(o.minutes ?? o.suggest_minutes));
    const [charge, setCharge] = useState('');
    const [advance, setAdvance] = useState('');
    const [reason, setReason] = useState('');
    const [reply, setReply] = useState('');
    const base = `/food/vendor/orders/${o.id}`;
    const post = (path: string, body: Record<string, string> = {}) => router.post(`${base}/${path}`, body, { preserveScroll: true });
    const needsAdvance = o.advance_due > o.advance_paid + 0.001;

    return (
        <li className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-display text-xl font-bold">
                    {o.number} · {o.mode === 'delivery' ? t('Home delivery') : t("I'll eat there")}
                    {o.eat_time && ` · ${o.eat_time}`}
                </p>
                <p className="text-xs text-[var(--pt-muted)]">{dateTime(o.placed_at)}</p>
            </div>

            <ul className="space-y-1">
                {o.lines.map((l, i) => (
                    <li key={i} className="text-lg font-semibold">
                        {num(l.quantity)} × {l.name}
                        {l.ingredients && (
                            <span className="block text-base font-bold">
                                {l.ingredients.items.length > 0 ? l.ingredients.items.map((x) => x.name).join(' · ') : t('Plain')}
                            </span>
                        )}
                        {l.note && <span className="block text-base font-bold underline">{l.note}</span>}
                    </li>
                ))}
            </ul>
            {o.note && <p className="rounded-md bg-warning/10 p-2 font-semibold">{o.note}</p>}

            <p className="text-sm">
                {o.mode === 'delivery' ? (
                    <>
                        {o.ship_to.name}, <a href={`tel:${o.ship_to.phone}`} className="underline">{o.ship_to.phone}</a>, {o.ship_to.address}, {o.ship_to.area} {o.ship_to.how_to_find && `(${o.ship_to.how_to_find})`}
                    </>
                ) : (
                    <>
                        {o.ship_to.name}, <a href={`tel:${o.ship_to.phone}`} className="underline">{o.ship_to.phone}</a>
                    </>
                )}
            </p>
            <p className="tabular text-sm">
                {t('Total')}: <strong>{money(o.total)}</strong>
                {o.advance_due > 0 && ` · ${t('Advance')}: ${money(o.advance_paid)} / ${money(o.advance_due)}`}
            </p>

            <div className="flex flex-wrap items-center gap-2">
                {o.status === 'pending' && (
                    <>
                        {needsAdvance && (
                            <>
                                <Input aria-label={t('Advance received')} className="h-9 w-28" inputMode="decimal" placeholder={t('Advance')} value={advance} onChange={(e) => setAdvance(e.target.value)} />
                                <Button variant="outline" disabled={Number(advance) <= 0} onClick={() => post('advance', { amount: advance })}>
                                    {t('Advance received')}
                                </Button>
                            </>
                        )}
                        <label className="text-sm">
                            {t('It will take (minutes)')}
                            <Input className="ms-2 inline-block h-9 w-20" inputMode="numeric" value={minutes} onChange={(e) => setMinutes(e.target.value)} />
                        </label>
                        {o.charge_open && <Input aria-label={t('Delivery charge')} className="h-9 w-28" inputMode="decimal" placeholder={t('Delivery charge')} value={charge} onChange={(e) => setCharge(e.target.value)} />}
                        <Button disabled={needsAdvance || Number(minutes) < 1 || (o.charge_open && charge === '')} onClick={() => post('confirm', { minutes, charge })}>
                            {t('Confirm')}
                        </Button>
                    </>
                )}
                {o.status === 'confirmed' && o.mode === 'delivery' && (
                    <>
                        <Button onClick={() => post('packing')}>{t('Packing')}</Button>
                        <Button variant="outline" onClick={() => post('out')}>
                            {t('Out for delivery')}
                        </Button>
                    </>
                )}
                {o.status === 'confirmed' && o.mode === 'eat_there' && <Button onClick={() => post('ready')}>{t('Ready')}</Button>}
                {o.status === 'packing' && <Button onClick={() => post('out')}>{t('Out for delivery')}</Button>}
                {(o.status === 'out' || o.status === 'ready') && <Button onClick={() => post('deliver')}>{o.mode === 'delivery' ? t('Delivered') : t('Served')}</Button>}
                <a href={`${base}/print/kitchen`} target="_blank" rel="noreferrer" className="text-sm underline">
                    {t('Kitchen ticket')}
                </a>
                <a href={`${base}/print/memo`} target="_blank" rel="noreferrer" className="text-sm underline">
                    {t('Memo')}
                </a>
            </div>

            {['pending', 'confirmed', 'packing'].includes(o.status) && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('Reason')} className="h-8 w-56" placeholder={t('Why we cancel (the buyer is told)')} value={reason} onChange={(e) => setReason(e.target.value)} />
                    <Button size="sm" variant="ghost" disabled={reason.trim().length < 3} onClick={() => post('cancel', { reason })}>
                        {t('Cancel this order')}
                    </Button>
                </div>
            )}

            {o.complaint && (
                <div className="space-y-2 rounded-md border border-danger/40 p-3 text-sm">
                    <p>
                        <strong>{t('Complaint')}:</strong> {o.complaint}
                    </p>
                    {o.has_complaint_photo && <img src={`${base}/photo`} alt="" className="max-h-48 rounded-md border" />}
                    {o.complaint_reply ? (
                        <p className="text-[var(--pt-muted)]">{o.complaint_reply}</p>
                    ) : (
                        <div className="flex gap-2">
                            <Input aria-label={t('Reply')} className="h-8 flex-1" value={reply} onChange={(e) => setReply(e.target.value)} />
                            <Button size="sm" disabled={reply.trim().length < 3} onClick={() => post('reply', { text: reply })}>
                                {t('Send')}
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </li>
    );
}

function ItemRow({ i }: { i: Item }) {
    const { t, money } = useI18n();
    const [f, setF] = useState({ name: i.name, price: String(i.price), cook_minutes: String(i.cook_minutes), description: i.description ?? '', category: i.category ?? '', ready_made: i.ready_made });
    const [photo, setPhoto] = useState<File | null>(null);

    return (
        <li className="space-y-2 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Input aria-label={t('Name')} className="h-8 w-44" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                <Input aria-label={t('Group on the menu')} placeholder={t('Group on the menu')} className="h-8 w-36" maxLength={40} value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })} />
                <Input aria-label={t('Price')} className="h-8 w-24" inputMode="decimal" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} />
                <Input aria-label={t('Minutes to cook')} className="h-8 w-20" inputMode="numeric" value={f.cook_minutes} onChange={(e) => setF({ ...f, cook_minutes: e.target.value })} />
                <label className="flex items-center gap-1 text-xs">
                    <input type="checkbox" checked={f.ready_made} onChange={(e) => setF({ ...f, ready_made: e.target.checked })} /> {t('Ready made')}
                </label>
                <input type="file" accept="image/*" aria-label={t('Photo')} className="max-w-40 text-xs" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                <Button size="sm" variant="outline" onClick={() => router.post(`/food/vendor/items/${i.id}`, { ...f, ready_made: f.ready_made ? 1 : 0, photo }, { forceFormData: true, preserveScroll: true })}>
                    {t('Save')}
                </Button>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <span className="tabular text-xs text-[var(--pt-muted)]">{money(i.price)}</span>
                <label className="flex items-center gap-1 text-xs">
                    <input type="checkbox" checked={i.available} onChange={(e) => router.put(`/food/vendor/items/${i.id}/available`, { on: e.target.checked ? 1 : 0 }, { preserveScroll: true })} /> {t('On the menu')}
                </label>
                <label className="flex items-center gap-1 text-xs">
                    <input type="checkbox" checked={i.sold_out_today} disabled={!i.available} onChange={(e) => router.put(`/food/vendor/items/${i.id}/sold-out`, { on: e.target.checked ? 1 : 0 }, { preserveScroll: true })} /> {t('Sold out today')}
                </label>
                <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Remove this dish?')) && router.delete(`/food/vendor/items/${i.id}`, { preserveScroll: true })}>
                    {t('Remove')}
                </Button>
            </div>
        </li>
    );
}

export default function VendorHome({ restaurant: r, openNow, orders, together, recent, sales, items, roadByGoogle, mapOn }: Props) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [s, setS] = useState({ ...r, areas: r.areas.join(', '), advance_over: r.advance_over !== null ? String(r.advance_over) : '', max_km: r.max_km !== null ? String(r.max_km) : '' });
    const [here, setHere] = useState<string | null>(null);
    const [finding, setFinding] = useState(false);
    const findAddress = async () => {
        setFinding(true);
        setHere(null);
        try {
            const res = await fetch(`/food/vendor/locate?address=${encodeURIComponent(`${s.address ?? ''}, ${r.area}`)}`, { headers: { Accept: 'application/json' } });
            const body = await res.json();
            if (!res.ok) setHere(body.message ?? t('We could not find that address. Try adding the area and the district.'));
            else setS((cur) => ({ ...cur, lat: body.lat, lng: body.lng }));
        } catch {
            setHere(t('Could not reach the map service. Please try again.'));
        } finally {
            setFinding(false);
        }
    };
    const useHere = () =>
        navigator.geolocation
            ? navigator.geolocation.getCurrentPosition(
                  (p) => setS((cur) => ({ ...cur, lat: Math.round(p.coords.latitude * 1e6) / 1e6, lng: Math.round(p.coords.longitude * 1e6) / 1e6 })),
                  () => setHere(t('We could not get the position of this device. Allow location for this site and try again.')),
              )
            : setHere(t('This device cannot share its position.'));
    const [fresh, setFresh] = useState({ name: '', price: '', cook_minutes: '15', description: '', category: '', ready_made: false });
    const [photo, setPhoto] = useState<File | null>(null);

    // New orders arrive while the page is open.
    useEffect(() => {
        const id = window.setInterval(() => document.visibilityState === 'visible' && router.reload({ only: ['orders', 'together', 'sales'] }), 20000);

        return () => window.clearInterval(id);
    }, []);

    const save = () =>
        router.post('/food/vendor/settings', { ...s, areas: s.areas.split(',').map((a) => a.trim()).filter(Boolean), eat_there: s.eat_there ? 1 : 0, advance_over: s.advance_over === '' ? null : s.advance_over, max_km: s.max_km === '' ? null : s.max_km }, { preserveScroll: true });
    const sum = (label: string, x: Sums) => (
        <div className="rounded-[1.25rem] border bg-[var(--pt-surface)] p-3">
            <p className="text-xs text-[var(--pt-muted)]">{label}</p>
            <p className="tabular font-display text-xl font-bold">{money(x.sales)}</p>
            <p className="tabular text-xs text-[var(--pt-muted)]">
                {t('{n} orders', { n: num(x.orders) })} · {t('You receive')} {money(x.net)}
            </p>
        </div>
    );

    return (
        <PublicLayout>
            <Head title={r.name} />
            <div className="mx-auto max-w-4xl space-y-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl font-extrabold">{r.name}</h1>
                        <p className="text-sm text-[var(--pt-muted)]">
                            {openNow ? t('Open now') : t('Closed now')} · {r.opens_at} – {r.closes_at}
                            {r.status === 'suspended' && ` · ${t('Suspended')}`}
                        </p>
                    </div>
                    <label className="flex items-center gap-3 rounded-full border px-4 py-2 text-sm font-semibold">
                        <input type="checkbox" checked={r.is_open} onChange={(e) => router.put('/food/vendor/open', { open: e.target.checked ? 1 : 0 }, { preserveScroll: true })} />
                        {r.is_open ? t('I am open') : t('I am closed')}
                    </label>
                </div>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <InputError message={errors.vendor} />

                <section className="grid gap-3 sm:grid-cols-2">
                    {sum(t('Today'), sales.today)}
                    {sum(t('This month'), sales.month)}
                    <p className="text-xs text-[var(--pt-muted)] sm:col-span-2">
                        {t('Our commission on you: {value}. Counted only on orders that are delivered.', { value: r.commission_kind === 'percent' ? `${num(r.commission_value)}%` : t('{amount} for each order', { amount: money(r.commission_value) }) })}
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="font-display text-xl font-bold">{t('Orders now')}</h2>
                    {together.map((g) => (
                        <p key={g.area} role="note" className="rounded-md bg-info/10 p-2 text-sm text-info">
                            {t('Going to the same area ({area}), one rider can take them together: {numbers}', { area: g.area, numbers: g.numbers.join(', ') })}
                        </p>
                    ))}
                    {orders.length === 0 ? <p className="rounded-[1.5rem] border border-dashed p-8 text-center text-[var(--pt-muted)]">{t('No orders now.')}</p> : <ul className="space-y-3">{orders.map((o) => <OrderCard key={o.id} o={o} />)}</ul>}
                </section>

                <section className="space-y-2">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 className="font-display text-xl font-bold">{t('Menu')}</h2>
                        <Link href="/food/vendor/custom" className="text-sm font-semibold underline underline-offset-4">
                            {t('Custom food: bases and ingredients')}
                        </Link>
                    </div>
                    <ul className="divide-y rounded-[1.5rem] border bg-[var(--pt-surface)]">{items.map((i) => <ItemRow key={`${i.id}-${i.name}-${i.price}`} i={i} />)}</ul>
                    <div className="flex flex-wrap items-center gap-2 rounded-[1.5rem] border p-3 text-sm">
                        <Input aria-label={t('Name')} className="h-8 w-44" placeholder={t('New dish')} value={fresh.name} onChange={(e) => setFresh({ ...fresh, name: e.target.value })} />
                        <Input aria-label={t('Group on the menu')} className="h-8 w-36" placeholder={t('Group on the menu')} maxLength={40} value={fresh.category} onChange={(e) => setFresh({ ...fresh, category: e.target.value })} />
                        <Input aria-label={t('Price')} className="h-8 w-24" inputMode="decimal" placeholder={t('Price')} value={fresh.price} onChange={(e) => setFresh({ ...fresh, price: e.target.value })} />
                        <Input aria-label={t('Minutes to cook')} className="h-8 w-20" inputMode="numeric" value={fresh.cook_minutes} onChange={(e) => setFresh({ ...fresh, cook_minutes: e.target.value })} />
                        <input type="file" accept="image/*" aria-label={t('Photo')} className="max-w-40 text-xs" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                        <Button size="sm" disabled={fresh.name.trim().length < 2 || Number(fresh.price) <= 0} onClick={() => router.post('/food/vendor/items', { ...fresh, ready_made: fresh.ready_made ? 1 : 0, photo }, { forceFormData: true, preserveScroll: true, onSuccess: () => (setFresh({ name: '', price: '', cook_minutes: '15', description: '', category: '', ready_made: false }), setPhoto(null)) })}>
                            {t('Add')}
                        </Button>
                    </div>
                </section>

                <section className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                    <h2 className="font-display text-xl font-bold">{t('Your restaurant')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-sm">
                            {t('Opens at')}
                            <Input className="mt-1" type="time" value={s.opens_at} onChange={(e) => setS({ ...s, opens_at: e.target.value })} />
                        </label>
                        <label className="text-sm">
                            {t('Closes at')}
                            <Input className="mt-1" type="time" value={s.closes_at} onChange={(e) => setS({ ...s, closes_at: e.target.value })} />
                        </label>
                    </div>
                    <fieldset className="flex flex-wrap gap-3 text-sm">
                        <legend className="mb-1 font-medium">{t('Closed on')}</legend>
                        {DAYS.map((d, n) => (
                            <label key={d} className="flex items-center gap-1">
                                <input type="checkbox" checked={s.closed_days.includes(n)} onChange={(e) => setS({ ...s, closed_days: e.target.checked ? [...s.closed_days, n] : s.closed_days.filter((x) => x !== n) })} /> {t(d)}
                            </label>
                        ))}
                    </fieldset>
                    <label className="block text-sm">
                        {t('Areas you deliver to, with commas')}
                        <Input className="mt-1" value={s.areas} onChange={(e) => setS({ ...s, areas: e.target.value })} />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-4">
                        <label className="text-sm sm:col-span-2">
                            {t('Delivery charge')}
                            <select value={s.charge_mode} onChange={(e) => setS({ ...s, charge_mode: e.target.value as 'area' | 'distance' | 'manual' })} className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm">
                                <option value="area">{t('By area: one price inside, another outside')}</option>
                                <option value="distance">{t('By distance: so much for each kilometre')}</option>
                                <option value="manual">{t('I write it on each order')}</option>
                            </select>
                        </label>
                        <label className="text-sm">
                            {t('Inside your area')}
                            <Input className="mt-1" inputMode="decimal" value={s.charge_inside} onChange={(e) => setS({ ...s, charge_inside: Number(e.target.value) })} />
                        </label>
                        <label className="text-sm">
                            {t('Outside your area')}
                            <Input className="mt-1" inputMode="decimal" value={s.charge_outside} onChange={(e) => setS({ ...s, charge_outside: Number(e.target.value) })} />
                        </label>
                        <label className="text-sm">
                            {t('Least charge')}
                            <Input className="mt-1" inputMode="decimal" value={s.min_charge} onChange={(e) => setS({ ...s, min_charge: Number(e.target.value) })} />
                        </label>
                        <label className="text-sm">
                            {t('Who delivers')}
                            <select value={s.delivery_by} onChange={(e) => setS({ ...s, delivery_by: e.target.value as 'own' | 'platform' })} className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm">
                                <option value="own">{t('My own people')}</option>
                                <option value="platform">{t("The platform's courier")}</option>
                            </select>
                        </label>
                        <label className="text-sm">
                            {t('Advance for orders over')}
                            <Input className="mt-1" inputMode="decimal" placeholder={t('None')} value={s.advance_over} onChange={(e) => setS({ ...s, advance_over: e.target.value })} />
                        </label>
                        <label className="text-sm">
                            {t('Advance %')}
                            <Input className="mt-1" inputMode="numeric" value={s.advance_percent} onChange={(e) => setS({ ...s, advance_percent: Number(e.target.value) })} />
                        </label>
                    </div>
                    <div className="grid gap-3 rounded-xl border p-3 sm:grid-cols-3">
                        <label className="text-sm sm:col-span-3">
                            {t('Address of the restaurant')}
                            <Input className="mt-1" maxLength={250} value={s.address ?? ''} onChange={(e) => setS({ ...s, address: e.target.value })} />
                        </label>
                        <label className="text-sm">
                            {t('Charge for each kilometre')}
                            <Input className="mt-1" inputMode="decimal" value={s.per_km} onChange={(e) => setS({ ...s, per_km: Number(e.target.value) })} />
                        </label>
                        <label className="text-sm">
                            {t('Longest distance you deliver, in km')}
                            <Input className="mt-1" inputMode="decimal" placeholder={t('No limit')} value={s.max_km} onChange={(e) => setS({ ...s, max_km: e.target.value })} />
                        </label>
                        <div className="text-sm">
                            <p>{t('Where the restaurant is')}</p>
                            <p className="tabular mt-1 text-xs text-[var(--pt-muted)]">{s.lat !== null && s.lng !== null ? `${s.lat}, ${s.lng}` : t('Not set yet')}</p>
                            <div className="mt-1 flex flex-wrap gap-2">
                                <Button type="button" size="sm" variant="outline" onClick={useHere}>
                                    {t('Use the position of this device')}
                                </Button>
                                {mapOn && (
                                    <Button type="button" size="sm" variant="outline" disabled={finding || !(s.address ?? '').trim()} aria-busy={finding} onClick={findAddress}>
                                        {finding ? t('Finding…') : t('Find it from the address above')}
                                    </Button>
                                )}
                            </div>
                        </div>
                        <p className="text-xs text-[var(--pt-muted)] sm:col-span-3">
                            {roadByGoogle ? t('The distance is the real road distance from Google Maps. A buyer further than your longest distance cannot order.') : t('The distance is worked out from the straight line and a road allowance, so it is an estimate. A buyer further than your longest distance cannot order. Stand in the restaurant when you set its position.')}
                        </p>
                        {here && (
                            <p role="alert" className="text-sm text-destructive sm:col-span-3">
                                {here}
                            </p>
                        )}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={s.eat_there} onChange={(e) => setS({ ...s, eat_there: e.target.checked })} /> {t("Offer \"I'll eat there\"")}
                    </label>
                    <InputError message={errors.vendor} />
                    <Button onClick={save}>{t('Save')}</Button>
                </section>

                {recent.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Finished')}</h2>
                        <ul className="space-y-3">{recent.map((o) => <OrderCard key={o.id} o={o} />)}</ul>
                    </section>
                )}
                <p className="text-center text-xs">
                    <Link href="/food" className="underline">
                        {t('See how buyers see your menu')}
                    </Link>
                </p>
            </div>
        </PublicLayout>
    );
}
