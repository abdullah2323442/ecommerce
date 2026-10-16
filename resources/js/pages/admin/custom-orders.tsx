import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Item {
    label: string;
    garment?: string;
    quantity: number;
    unit: string;
    values: Record<string, number>;
    measured_on?: string;
}

interface Row {
    id: number;
    number: string;
    token: string;
    buyer: string;
    kind: 'wood' | 'clothing';
    status: string;
    material: string | null;
    fabric_by: string | null;
    items: Item[];
    details: Record<string, string | boolean> | null;
    note: string | null;
    urgent: boolean;
    has_photo: boolean;
    price: number | null;
    base_price: number | null;
    material_cost: number | null;
    material_qty: number | null;
    wages_total: number | null;
    profit: number | null;
    advance_percent: number | null;
    advance_due: number;
    advance_paid: number;
    days: number | null;
    due_on: string | null;
    complaint: string | null;
    craftsman: string | null;
    craftsman_id: number | null;
    wage_asked: string | null;
    wage_proposed: number | null;
    wage_note: string | null;
    waiting_note: string | null;
    delays: number;
    advance_given: number;
    done_percent: number;
    progress: number;
    entries: { id: number; who: string; who_id: number; percent: number; note: string | null; at: string }[];
    moves: { kind: string; from: string | null; to: string | null; at: string }[];
}

interface Maker {
    id: number;
    name: string;
    active_jobs: number;
}

interface Material {
    id: number;
    kind: 'wood' | 'fabric';
    name: string;
    name_bn: string | null;
    rate: string;
    stock: string | null;
    is_active: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Made to order', href: '/admin/custom' }];

/** Wages first, then the craftsman: the job does not go to anyone until the wage is set (spec ch 16.7). */
function CraftBlock({ r, makers }: { r: Row; makers: Maker[] }) {
    const { t, money, num, dateTime } = useI18n();
    const [ask, setAsk] = useState(String(makers[0]?.id ?? ''));
    const [wage, setWage] = useState(r.wages_total !== null ? String(r.wages_total) : r.wage_proposed !== null ? String(r.wage_proposed) : '');
    const [pick, setPick] = useState(String(r.craftsman_id ?? makers[0]?.id ?? ''));
    const [days, setDays] = useState('3');
    const [give, setGive] = useState('');
    const post = (path: string, body: Record<string, string | number> = {}) => router.post(`/admin/custom/${r.id}/${path}`, body, { preserveScroll: true });
    const select = (value: string, set: (v: string) => void, label: string) => (
        <select aria-label={label} value={value} onChange={(e) => set(e.target.value)} className="h-8 rounded-md border bg-background px-2 text-xs">
            {makers.map((m) => (
                <option key={m.id} value={m.id}>
                    {m.name} ({t('{n} jobs', { n: num(m.active_jobs) })})
                </option>
            ))}
        </select>
    );

    return (
        <div className="space-y-2 rounded-md border p-2 text-xs">
            <div className="flex flex-wrap items-center gap-2">
                <strong>{t('Wage')}</strong>
                {r.wage_asked && <span>{t('{name} was asked', { name: r.wage_asked })}</span>}
                {r.wage_proposed !== null && (
                    <span>
                        {t('They say')} <strong className="tabular">{money(r.wage_proposed)}</strong> {r.wage_note}
                    </span>
                )}
                {r.status !== 'done' && (
                    <>
                        {makers.length > 0 && r.craftsman_id === null && (
                            <>
                                {select(ask, setAsk, t('Ask this craftsman'))}
                                <Button size="sm" variant="outline" onClick={() => post('wage-ask', { craftsman_id: ask })}>
                                    {t('Ask for a wage')}
                                </Button>
                            </>
                        )}
                        <Input aria-label={t('Wage')} className="h-8 w-24" inputMode="decimal" value={wage} onChange={(e) => setWage(e.target.value)} />
                        <Button size="sm" variant="outline" disabled={wage === '' || Number(wage) < 0} onClick={() => post('wages', { amount: wage })}>
                            {t('Set the wage')}
                        </Button>
                    </>
                )}
            </div>

            {['confirmed', 'making', 'trial'].includes(r.status) && (
                <div className="flex flex-wrap items-center gap-2">
                    <strong>{t('Craftsman')}</strong>
                    {r.craftsman && <span>{r.craftsman}</span>}
                    {makers.length > 0 && (
                        <>
                            {select(pick, setPick, t('Give the job to'))}
                            <Button size="sm" variant="outline" disabled={r.wages_total === null} onClick={() => post('assign', { craftsman_id: pick })}>
                                {r.craftsman ? t('Give it to someone else') : t('Give the job')}
                            </Button>
                        </>
                    )}
                    {r.wages_total === null && <span className="text-red-700">{t('Set the wage first.')}</span>}
                    {r.craftsman_id !== null && r.advance_paid > r.advance_given && (
                        <>
                            <Input aria-label={t('Advance to the craftsman')} className="h-8 w-24" inputMode="decimal" placeholder={t('Advance')} value={give} onChange={(e) => setGive(e.target.value)} />
                            <Button size="sm" variant="outline" disabled={Number(give) <= 0} onClick={() => post('give-advance', { amount: give })}>
                                {t('Give the advance')}
                            </Button>
                        </>
                    )}
                </div>
            )}

            {['making', 'trial'].includes(r.status) && (
                <div className="space-y-1">
                    <p className="tabular">
                        {t('{n}% done, {m}% remaining', { n: num(r.done_percent), m: num(100 - r.done_percent) })}
                        {r.delays > 0 && ` · ${t('Delays')}: ${num(r.delays)}`}
                    </p>
                    {r.waiting_note && (
                        <p className="flex flex-wrap items-center gap-2 rounded bg-amber-50 p-1 text-amber-900">
                            {t('Waiting for materials')}: {r.waiting_note}
                            <Button size="sm" variant="outline" onClick={() => post('materials-arrived')}>
                                {t('They arrived')}
                            </Button>
                        </p>
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        <Input aria-label={t('Days')} className="h-8 w-16" inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value)} />
                        <Button size="sm" variant="outline" onClick={() => post('extend', { days })}>
                            {t('Give a new time')}
                        </Button>
                    </div>
                </div>
            )}

            <a href={`/admin/chats/${r.id}`} className="underline">
                {t('Open the chat')}
            </a>
            {r.entries.length > 0 && <Entries r={r} />}
            {r.moves.length > 0 && (
                <ul className="text-muted-foreground">
                    {r.moves.map((m, i) => (
                        <li key={i}>
                            {dateTime(m.at)} · {m.kind === 'assign' ? t('Given to {name}', { name: m.to ?? '' }) : m.kind === 'shift' ? t('{from} gave it to {to}', { from: m.from ?? '', to: m.to ?? '' }) : t('{to} was taken on', { to: m.to ?? '' })}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** Who did how much, with the photo to check it. If the split is wrong we fix it here. */
function Entries({ r }: { r: Row }) {
    const { t, num, dateTime } = useI18n();
    const [edit, setEdit] = useState<Record<number, string>>({});

    return (
        <ul className="space-y-1">
            {r.entries.map((e) => (
                <li key={e.id} className="flex flex-wrap items-center gap-2">
                    <a href={`/admin/custom/progress/${e.id}/photo`} target="_blank" rel="noreferrer">
                        <img src={`/admin/custom/progress/${e.id}/photo`} alt="" className="h-10 w-10 rounded border object-cover" />
                    </a>
                    <span>{e.who}</span>
                    <Input aria-label={t('Percent')} className="h-7 w-16" inputMode="decimal" value={edit[e.id] ?? String(e.percent)} onChange={(ev) => setEdit({ ...edit, [e.id]: ev.target.value })} />
                    <span>%</span>
                    <Button size="sm" variant="ghost" disabled={edit[e.id] === undefined || Number(edit[e.id]) <= 0} onClick={() => router.put(`/admin/custom/progress/${e.id}`, { percent: edit[e.id] }, { preserveScroll: true })}>
                        {t('Fix')}
                    </Button>
                    <span className="text-muted-foreground">
                        {dateTime(e.at)} {e.note}
                    </span>
                    <span className="sr-only">{num(e.percent)}</span>
                </li>
            ))}
        </ul>
    );
}

function Job({ r, defaults, makers }: { r: Row; defaults: { advance: number; urgent: number }; makers: Maker[] }) {
    const { t, money, num, date } = useI18n();
    const [m, setM] = useState({ material_cost: r.material_cost !== null ? String(r.material_cost) : '', material_qty: r.material_qty !== null ? String(r.material_qty) : '', wages_total: r.wages_total !== null ? String(r.wages_total) : '', base_price: r.base_price !== null ? String(r.base_price) : '', advance_percent: String(r.advance_percent ?? defaults.advance), days_needed: r.days ? String(r.days) : '7', memo_note: '' });
    const [amount, setAmount] = useState('');
    const [reason, setReason] = useState('');
    const step = (name: string) => router.post(`/admin/custom/${r.id}/${name}`, {}, { preserveScroll: true });
    const pricing = r.status === 'requested' || r.status === 'priced';

    return (
        <li className="space-y-2 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {r.number} · {r.buyer} · {r.kind === 'wood' ? t('Wood') : t('Clothing')}
                    {r.urgent && <span className="ms-2 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-900">{t('Urgent')}</span>}
                </p>
                {r.price !== null && (
                    <p className="tabular font-semibold">
                        {money(r.price)}
                        {r.profit !== null && <span className="ms-2 text-xs font-normal text-muted-foreground">{t('Profit')}: {money(r.profit)}</span>}
                    </p>
                )}
            </div>
            <div className="rounded-md bg-muted/50 p-2 text-xs">
                {r.material && (
                    <p>
                        {r.kind === 'wood' ? t('Wood') : t('Fabric')}: <strong>{r.material}</strong>
                    </p>
                )}
                {r.fabric_by === 'buyer' && <p>{t('The buyer brings the fabric.')}</p>}
                {r.items.map((it, i) => (
                    <p key={i} className="tabular">
                        {it.label || t('Person {n}', { n: num(i + 1) })} {it.garment && `· ${it.garment} × ${num(it.quantity)}`} —{' '}
                        {Object.entries(it.values).map(([k, v]) => `${t(k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()))} ${num(v)}`).join(', ')} ({it.unit}
                        {it.measured_on && `, ${date(it.measured_on)}`})
                    </p>
                ))}
                {r.details && Object.keys(r.details).length > 0 && <p>{Object.entries(r.details).map(([k, v]) => (typeof v === 'boolean' ? k : `${k}: ${v}`)).join(' · ')}</p>}
                {r.note && <p>{r.note}</p>}
                {r.has_photo && (
                    <a href={`/admin/custom/${r.id}/photo`} target="_blank" rel="noreferrer" className="underline">
                        {t('Photo')}
                    </a>
                )}
            </div>

            {pricing && (
                <div className="flex flex-wrap items-end gap-2">
                    {(
                        [
                            ['material_cost', 'Material cost'],
                            ['material_qty', 'Material used'],
                            ['wages_total', 'Wages'],
                            ['base_price', 'Price to the buyer'],
                            ['advance_percent', 'Advance %'],
                            ['days_needed', 'Days'],
                        ] as const
                    ).map(([key, label]) => (
                        <label key={key} className="text-xs">
                            {t(label)}
                            <Input className="mt-1 h-8 w-28" inputMode="decimal" value={m[key]} onChange={(e) => setM({ ...m, [key]: e.target.value })} />
                        </label>
                    ))}
                    <label className="text-xs">
                        {t('Note on the memo')}
                        <Input className="mt-1 h-8 w-56" value={m.memo_note} onChange={(e) => setM({ ...m, memo_note: e.target.value })} />
                    </label>
                    <Button size="sm" disabled={Number(m.base_price) <= 0} onClick={() => router.put(`/admin/custom/${r.id}/price`, m, { preserveScroll: true })}>
                        {r.status === 'priced' ? t('Change the price') : t('Send the price')}
                    </Button>
                    {r.urgent && <span className="text-xs text-muted-foreground">{t('Urgent adds {n}%', { n: num(defaults.urgent) })}</span>}
                </div>
            )}

            {['requested', 'priced', 'confirmed', 'making', 'trial'].includes(r.status) && <CraftBlock r={r} makers={makers} />}

            {r.status === 'confirmed' && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="tabular text-xs">{t('Advance still to come: {amount}', { amount: money(r.advance_due) })}</span>
                    <Input aria-label={t('Advance received')} className="h-8 w-28" inputMode="decimal" placeholder={t('Amount')} value={amount} onChange={(e) => setAmount(e.target.value)} />
                    <Button size="sm" variant="outline" disabled={Number(amount) <= 0} onClick={() => router.post(`/admin/custom/${r.id}/advance`, { amount }, { preserveScroll: true, onSuccess: () => setAmount('') })}>
                        {t('Advance received')}
                    </Button>
                    <Button size="sm" onClick={() => step('start')}>
                        {t('Start the work')}
                    </Button>
                </div>
            )}
            {(r.status === 'making' || r.status === 'trial') && (
                <div className="flex flex-wrap items-center gap-2">
                    {r.due_on && <span className="text-xs">{t('Due on {date}.', { date: date(r.due_on) })}</span>}
                    {r.kind === 'clothing' && r.status === 'making' && (
                        <Button size="sm" variant="outline" onClick={() => step('trial')}>
                            {t('Call for a trial')}
                        </Button>
                    )}
                    {r.status === 'trial' && (
                        <Button size="sm" variant="outline" onClick={() => step('trial-done')}>
                            {t('Trial finished')}
                        </Button>
                    )}
                    <Button size="sm" onClick={() => step('done')}>
                        {t('It is ready')}
                    </Button>
                </div>
            )}
            {r.status === 'done' && (
                <Button size="sm" onClick={() => step('collect')}>
                    {t('Handed over')}
                </Button>
            )}
            {['requested', 'priced', 'confirmed', 'making', 'trial'].includes(r.status) && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('Reason')} className="h-8 w-56" placeholder={t('Why we cancel (the buyer is told)')} value={reason} onChange={(e) => setReason(e.target.value)} />
                    <Button size="sm" variant="ghost" disabled={reason.trim().length < 3} onClick={() => router.post(`/admin/custom/${r.id}/cancel`, { reason }, { preserveScroll: true })}>
                        {t('Cancel this job')}
                    </Button>
                </div>
            )}
        </li>
    );
}

function Complaint({ r }: { r: Row }) {
    const { t } = useI18n();
    const [note, setNote] = useState('');
    const [refund, setRefund] = useState('');
    const go = (outcome: string) => router.post(`/admin/custom/${r.id}/complaint`, { outcome, note, refund: outcome === 'refunded' ? refund : null }, { preserveScroll: true });

    return (
        <li className="space-y-2 px-4 py-3 text-sm">
            <p className="font-medium">
                {r.number} · {r.buyer}
            </p>
            <p>{r.complaint}</p>
            <div className="flex flex-wrap items-center gap-2">
                <Input aria-label={t('Our note')} className="h-8 w-64" placeholder={t('What we found (the buyer is told)')} value={note} onChange={(e) => setNote(e.target.value)} />
                <Input aria-label={t('Refund')} className="h-8 w-24" inputMode="decimal" placeholder={t('Refund')} value={refund} onChange={(e) => setRefund(e.target.value)} />
                <Button size="sm" disabled={note.trim().length < 3} onClick={() => go('fixed')}>
                    {t('We will fix it')}
                </Button>
                <Button size="sm" variant="outline" disabled={note.trim().length < 3 || Number(refund) <= 0} onClick={() => go('refunded')}>
                    {t('Refund')}
                </Button>
                <Button size="sm" variant="ghost" disabled={note.trim().length < 3} onClick={() => go('buyer')}>
                    {t("The buyer's measurements")}
                </Button>
            </div>
        </li>
    );
}

function MaterialRow({ m }: { m: Material }) {
    const { t } = useI18n();
    const [f, setF] = useState({ name: m.name, name_bn: m.name_bn ?? '', rate: String(m.rate), stock: m.stock ?? '', is_active: m.is_active });

    return (
        <li className="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
            <span className="w-16 text-xs text-muted-foreground">{m.kind === 'wood' ? t('Wood') : t('Fabric')}</span>
            <Input aria-label={t('Name')} className="h-8 w-40" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
            <Input aria-label={t('Name in Bangla')} className="h-8 w-40" value={f.name_bn} onChange={(e) => setF({ ...f, name_bn: e.target.value })} />
            <Input aria-label={t('Rate')} className="h-8 w-28" inputMode="decimal" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} />
            <Input aria-label={t('In stock')} placeholder={t('In stock')} className="h-8 w-24" inputMode="decimal" value={f.stock} onChange={(e) => setF({ ...f, stock: e.target.value })} />
            <label className="flex items-center gap-1 text-xs">
                <input type="checkbox" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} /> {t('Offered')}
            </label>
            <Button size="sm" variant="outline" onClick={() => router.put(`/admin/custom/materials/${m.id}`, { ...f, kind: m.kind }, { preserveScroll: true })}>
                {t('Save')}
            </Button>
        </li>
    );
}

export default function CustomOrders({ asking, priced, confirmed, making, done, complaints, materials, craftsmen, defaults }: { asking: Row[]; priced: Row[]; confirmed: Row[]; making: Row[]; done: Row[]; complaints: Row[]; materials: Material[]; craftsmen: Maker[]; defaults: { advance: number; urgent: number } }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [fresh, setFresh] = useState({ kind: 'wood', name: '', name_bn: '', rate: '' });
    const section = (title: string, items: Row[], empty: string) => (
        <section className="space-y-2">
            <h2 className="font-medium">{title}</h2>
            {items.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{empty}</p> : <ul className="divide-y rounded-md border bg-card">{items.map((r) => <Job key={r.id} r={r} defaults={defaults} makers={craftsmen} />)}</ul>}
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Made to order')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Made to order')}</h1>
                    <p className="text-sm text-muted-foreground">{t('We make the memo ourselves: material, wages, total. There is no commission and no return on this work.')}</p>
                </div>
                <Flash />
                <InputError message={errors.custom} />
                {complaints.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Work not done right')}</h2>
                        <ul className="divide-y rounded-md border border-red-300 bg-card">{complaints.map((r) => <Complaint key={r.id} r={r} />)}</ul>
                    </section>
                )}
                {section(t('Waiting for a price'), asking, t('Nothing waiting.'))}
                {section(t('With the buyer to confirm'), priced, t('Nothing with a buyer.'))}
                {section(t('Confirmed: advance and start'), confirmed, t('Nothing confirmed.'))}
                {section(t('Being made'), making, t('Nothing being made.'))}
                {section(t('Ready to hand over'), done, t('Nothing ready.'))}

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Woods and fabrics')}</h2>
                    <p className="text-xs text-muted-foreground">{t('Each has its own rate: for each cubic foot of wood, for each metre of fabric. The buyer sees the rate when they choose.')}</p>
                    <ul className="divide-y rounded-md border bg-card">
                        {materials.map((m) => (
                            <MaterialRow key={`${m.id}-${m.rate}-${m.stock}-${m.is_active}`} m={m} />
                        ))}
                    </ul>
                    <div className="flex flex-wrap items-center gap-2">
                        <select aria-label={t('Kind')} value={fresh.kind} onChange={(e) => setFresh({ ...fresh, kind: e.target.value })} className="h-8 rounded-md border bg-background px-2 text-sm">
                            <option value="wood">{t('Wood')}</option>
                            <option value="fabric">{t('Fabric')}</option>
                        </select>
                        <Input aria-label={t('Name')} className="h-8 w-40" placeholder={t('Name')} value={fresh.name} onChange={(e) => setFresh({ ...fresh, name: e.target.value })} />
                        <Input aria-label={t('Name in Bangla')} className="h-8 w-40" placeholder={t('Name in Bangla')} value={fresh.name_bn} onChange={(e) => setFresh({ ...fresh, name_bn: e.target.value })} />
                        <Input aria-label={t('Rate')} className="h-8 w-28" inputMode="decimal" placeholder={t('Rate')} value={fresh.rate} onChange={(e) => setFresh({ ...fresh, rate: e.target.value })} />
                        <Button size="sm" disabled={fresh.name.trim() === '' || Number(fresh.rate) <= 0} onClick={() => router.post('/admin/custom/materials', { ...fresh, is_active: true }, { preserveScroll: true, onSuccess: () => setFresh({ ...fresh, name: '', name_bn: '', rate: '' }) })}>
                            {t('Add')}
                        </Button>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
