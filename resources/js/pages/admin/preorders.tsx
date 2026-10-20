import Flash from '@/components/admin/flash';
import MedicinePicker, { type PickedMedicine } from '@/components/admin/medicine-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { preorderState } from '@/lib/preorder';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Line {
    id: number;
    name: string;
    unit: string;
    quantity: number;
    received: number | null;
    share: number | null;
}

interface Row {
    id: number;
    mpo?: boolean;
    status: string;
    late: boolean;
    due_on: string | null;
    days: number;
    extended: number;
    customer: string | null;
    phone: string | null;
    created_at: string | null;
    advance: number;
    advance_state: string;
    advance_refunded: number;
    total: number;
    invoice_id: number | null;
    cancelled_by: string | null;
    reply_note: string | null;
    cancel_note: string | null;
    lines: Line[];
}

interface Props {
    waiting: Row[];
    accepted: Row[];
    closed: Row[];
    buying: { company: string; medicines: { medicine_id: number; name: string; entries: { id: number; who: string; quantity: number; unit: string }[] }[] }[];
    on: { companies: { id: number; name: string; preorder_days: number }[]; medicines: { id: number; name: string; preorder_days: number }[] };
    days: number[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Pre-orders', href: '/admin/preorders' }];
const unitName = (u: string) => (u === 'box' ? 'Box' : u === 'strip' ? 'Strip' : 'Piece');

/** Goods came for this medicine: write how many boxes and the first pre-orders get theirs (spec ch 21). */
function ShareOut({ id }: { id: number }) {
    const { t } = useI18n();
    const [boxes, setBoxes] = useState('');

    return (
        <div className="my-1 flex flex-wrap items-center gap-2">
            <Input aria-label={t('Boxes that came')} className="h-8 w-28" type="number" min="1" step="any" placeholder={t('Boxes that came')} value={boxes} onChange={(e) => setBoxes(e.target.value)} />
            <Button size="sm" variant="outline" disabled={boxes === '' || Number(boxes) <= 0} onClick={() => router.post('/admin/preorders/share', { medicine_id: id, boxes }, { preserveScroll: true, onSuccess: () => setBoxes('') })}>
                {t('Share out')}
            </Button>
        </div>
    );
}

function Card({ p, days }: { p: Row; days: number[] }) {
    const { t, num, money, date, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [pick, setPick] = useState(String(p.days));
    const [note, setNote] = useState('');
    const shareKey = p.lines.map((l) => l.share ?? '').join(',');
    const [got, setGot] = useState<Record<number, string>>(() => Object.fromEntries(p.lines.map((l) => [l.id, String(l.share ?? l.quantity)])));
    // When the goods have been shared out, the amounts to receive follow it.
    useEffect(() => setGot(Object.fromEntries(p.lines.map((l) => [l.id, String(l.share ?? l.quantity)]))), [shareKey]); // eslint-disable-line react-hooks/exhaustive-deps
    const [edits, setEdits] = useState<Record<number, string>>({});
    const [paid, setPaid] = useState('');
    const post = (path: string, data: Record<string, string | number | Record<string, number>> = {}) => router.post(`/admin/preorders/${p.id}/${path}`, data, { preserveScroll: true });
    const open = p.status === 'requested' || p.status === 'accepted';

    return (
        <li className={`space-y-3 px-4 py-4 ${p.late ? 'bg-danger/5' : ''}`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <p className="font-semibold">
                        {p.customer} <span className="text-muted-foreground text-sm font-normal">{p.phone}</span>
                    </p>
                    <p className="text-muted-foreground text-xs">{p.created_at && dateTime(p.created_at)}</p>
                </div>
                <p className={`text-sm font-semibold ${p.late ? 'text-danger' : ''}`}>
                    {t(preorderState(p.status, p.late))}
                    {p.due_on && p.status === 'accepted' && ` · ${date(p.due_on)}`}
                    {p.extended > 0 && ` · ${t('time changed {n} times', { n: num(p.extended) })}`}
                </p>
            </div>

            <ul className="divide-y rounded-md border text-sm">
                {p.lines.map((l) => (
                    <li key={l.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2">
                        <span className="min-w-40 flex-1 font-medium">{l.name}</span>
                        {open ? (
                            <>
                                <Input
                                    aria-label={t('Ordered')}
                                    className="h-8 w-20"
                                    type="number"
                                    min="0"
                                    step="any"
                                    defaultValue={l.quantity}
                                    onChange={(e) => setEdits({ ...edits, [l.id]: e.target.value })}
                                    onBlur={() => edits[l.id] && Number(edits[l.id]) > 0 && Number(edits[l.id]) !== l.quantity && router.put(`/admin/preorders/lines/${l.id}`, { quantity: edits[l.id] }, { preserveScroll: true })}
                                />
                                <span className="text-muted-foreground">{t(unitName(l.unit))}</span>
                            </>
                        ) : (
                            <span>
                                {l.received !== null ? `${num(l.received)} / ` : ''}
                                {num(l.quantity)} {t(unitName(l.unit))}
                            </span>
                        )}
                        {p.status === 'accepted' && (
                            <label className="flex items-center gap-1 text-xs">
                                {t('Arrived')}
                                <Input aria-label={t('Arrived')} className="h-8 w-20" type="number" min="0" step="any" value={got[l.id] ?? ''} onChange={(e) => setGot({ ...got, [l.id]: e.target.value })} />
                            </label>
                        )}
                    </li>
                ))}
            </ul>

            <p className="text-muted-foreground text-sm">
                {t('Expected total')}: {money(p.total)}
                {p.advance > 0 && ` · ${t('Advance')} ${money(p.advance)} (${t(p.advance_state === 'promised' ? 'To be paid' : p.advance_state === 'held' ? 'Received' : p.advance_state === 'applied' ? 'Used on your order' : p.advance_state === 'refund_due' ? 'To be returned' : p.advance_state === 'refunded' ? 'Returned' : 'Kept')}${p.advance_state === 'refunded' && p.advance_refunded > 0 && p.advance_refunded < p.advance ? `: ${money(p.advance_refunded)}` : ''})`}
            </p>
            <InputError message={errors.preorder} />

            {p.status === 'requested' && (
                <div className="flex flex-wrap items-center gap-2">
                    <select aria-label={t('Days')} value={pick} onChange={(e) => setPick(e.target.value)} className="bg-background h-9 rounded-md border px-2 text-sm">
                        {days.map((d) => (
                            <option key={d} value={d}>
                                {t('{n} days', { n: num(d) })}
                            </option>
                        ))}
                    </select>
                    <Button size="sm" onClick={() => post('accept', { days: Number(pick) })}>
                        {t('Accept')}
                    </Button>
                    <Input aria-label={t('Reason')} className="h-9 w-60" placeholder={t('Reason, the buyer will read it')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={200} />
                    <Button size="sm" variant="outline" onClick={() => post('decline', { note })}>
                        {t('Decline')}
                    </Button>
                </div>
            )}

            {p.status === 'accepted' && p.mpo && (
                <div className="flex flex-wrap items-end gap-2 rounded-md border border-dashed p-2 text-sm">
                    <span className="flex-1">{t('From an MPO. When the goods reach you, put them into stock at the discount you paid.')}</span>
                    <label>
                        {t('Discount you paid (%)')}
                        <Input className="mt-1 h-8 w-24" type="number" min="0" max="99" step="any" value={paid} onChange={(e) => setPaid(e.target.value)} />
                    </label>
                    <Button size="sm" variant="outline" disabled={paid === ''} onClick={() => post('take-from-mpo', { percent: paid })}>
                        {t('Goods reached me')}
                    </Button>
                </div>
            )}
            {p.status === 'accepted' && (
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button size="sm" onClick={() => post('receive', { received: Object.fromEntries(Object.entries(got).map(([k, v]) => [k, v === '' ? 0 : Number(v)])) })}>
                            {t('Receive')}
                        </Button>
                        <select aria-label={t('New time')} value={pick} onChange={(e) => setPick(e.target.value)} className="bg-background h-9 rounded-md border px-2 text-sm">
                            {days.map((d) => (
                                <option key={d} value={d}>
                                    {t('{n} days', { n: num(d) })}
                                </option>
                            ))}
                        </select>
                        <Button size="sm" variant="outline" onClick={() => post('extend', { days: Number(pick) })}>
                            {t('Give a new time')}
                        </Button>
                        {p.advance > 0 && p.advance_state === 'promised' && (
                            <Button size="sm" variant="outline" onClick={() => post('advance', { state: 'held' })}>
                                {t('Advance received')}
                            </Button>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input aria-label={t('Reason')} className="h-9 w-72" placeholder={t('Why it did not come, the buyer will read it')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={200} />
                        <Button size="sm" variant="ghost" onClick={() => confirm(t('Cancel this pre-order? The buyer gets a sorry note.')) && post('cancel', { note })}>
                            {t('Did not come, cancel')}
                        </Button>
                    </div>
                </div>
            )}

            {(p.status === 'cancelled' || p.status === 'declined') && (p.advance_state === 'refund_due' || (p.advance_state === 'held' && p.cancelled_by === 'buyer')) && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span>{p.advance_state === 'refund_due' ? t('The advance has to go back.') : t('The buyer cancelled. You decide about the advance.')}</span>
                    <Button size="sm" variant="outline" onClick={() => post('advance', { state: 'refunded' })}>
                        {t('Returned')}
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => post('advance', { state: 'half' })}>
                        {t('Return half (goods not taken)')}
                    </Button>
                    {p.advance_state === 'held' && (
                        <Button size="sm" variant="ghost" onClick={() => post('advance', { state: 'kept' })}>
                            {t('Keep it')}
                        </Button>
                    )}
                </div>
            )}
            {p.status === 'fulfilled' && p.invoice_id && (
                <a className="text-sm font-semibold underline underline-offset-4" href={`/admin/orders/${p.invoice_id}`}>
                    {t('Open the order')}
                </a>
            )}
            {(p.cancel_note || p.reply_note) && <p className="text-muted-foreground text-sm">{p.cancel_note ?? p.reply_note}</p>}
        </li>
    );
}

function Availability({ on }: { on: Props['on'] }) {
    const { t, num } = useI18n();
    const [days, setDays] = useState('3');
    const [q, setQ] = useState('');
    const [companies, setCompanies] = useState<{ id: number; name: string }[]>([]);
    const set = (type: string, id: number, d: number | null) => router.post('/admin/preorders/availability', { type, id, days: d }, { preserveScroll: true });

    useEffect(() => {
        if (q.trim().length < 2) return setCompanies([]);
        const timer = setTimeout(() => fetch(`/admin/preorders/companies?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((d) => setCompanies(d.results)).catch(() => setCompanies([])), 200);
        return () => clearTimeout(timer);
    }, [q]);

    return (
        <section className="space-y-3 rounded-lg border bg-card p-4">
            <div>
                <h2 className="font-display text-lg font-extrabold">{t('Where pre-order is on')}</h2>
                <p className="text-muted-foreground text-sm">{t('Turn it on for a whole company or for one medicine, with the time you promise. A medicine setting beats its company.')}</p>
            </div>
            <div className="flex flex-wrap items-end gap-3">
                <label className="text-sm">
                    {t('Promise')}
                    <select value={days} onChange={(e) => setDays(e.target.value)} className="bg-background mt-1 block h-9 rounded-md border px-2 text-sm">
                        {[2, 3, 4, 5].map((d) => (
                            <option key={d} value={d}>
                                {t('{n} days', { n: num(d) })}
                            </option>
                        ))}
                    </select>
                </label>
                <div className="min-w-56">
                    <label className="text-sm" htmlFor="pre-co">
                        {t('A company')}
                    </label>
                    <Input id="pre-co" className="mt-1 h-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Company name')} />
                    {companies.length > 0 && (
                        <ul className="mt-1 divide-y rounded-md border text-sm">
                            {companies.map((c) => (
                                <li key={c.id}>
                                    <button type="button" className="w-full px-3 py-1.5 text-left hover:bg-muted" onClick={() => { set('company', c.id, Number(days)); setQ(''); setCompanies([]); }}>
                                        {c.name}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <div className="min-w-64">
                    <p className="text-sm">{t('Or one medicine')}</p>
                    <MedicinePicker picked={null} onPick={(m: PickedMedicine | null) => m && set('medicine', m.id, Number(days))} />
                </div>
            </div>
            {(on.companies.length > 0 || on.medicines.length > 0) && (
                <ul className="flex flex-wrap gap-2 text-sm">
                    {on.companies.map((c) => (
                        <li key={`c${c.id}`} className="flex items-center gap-2 rounded-full border px-3 py-1">
                            {c.name} · {t('{n} days', { n: num(c.preorder_days) })}
                            <button type="button" aria-label={t('Turn off')} className="text-muted-foreground hover:text-foreground" onClick={() => set('company', c.id, null)}>
                                ×
                            </button>
                        </li>
                    ))}
                    {on.medicines.map((m) => (
                        <li key={`m${m.id}`} className="flex items-center gap-2 rounded-full border px-3 py-1">
                            {m.name} · {t('{n} days', { n: num(m.preorder_days) })}
                            <button type="button" aria-label={t('Turn off')} className="text-muted-foreground hover:text-foreground" onClick={() => set('medicine', m.id, null)}>
                                ×
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function Preorders({ waiting, accepted, closed, buying, on, days }: Props) {
    const { t, num } = useI18n();
    const late = accepted.filter((a) => a.late).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Pre-orders')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Pre-orders')}</h1>
                    <p className="text-muted-foreground text-sm">{t('The buyer is not linked to stock. Receive writes in how much came, and that becomes the real order.')}</p>
                </header>

                {late > 0 && (
                    <p role="alert" className="rounded-md border border-danger/50 bg-danger/10 px-3 py-2 text-sm font-semibold">
                        {t('{n} pre-orders are past their time.', { n: num(late) })}
                    </p>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">
                        {t('Waiting for your answer')} ({num(waiting.length)})
                    </h2>
                    {waiting.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-5 text-center text-sm">{t('Nothing waiting.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {waiting.map((p) => (
                                <Card key={p.id} p={p} days={days} />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-2">
                    <h2 className="font-medium">
                        {t('Accepted, waiting for the goods')} ({num(accepted.length)})
                    </h2>
                    {accepted.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-5 text-center text-sm">{t('Nothing waiting.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {accepted.map((p) => (
                                <Card key={p.id} p={p} days={days} />
                            ))}
                        </ul>
                    )}
                </section>

                {buying.length > 0 && (
                    <section className="space-y-2">
                        <div>
                            <h2 className="font-medium">{t('Buying list')}</h2>
                            <p className="text-muted-foreground text-sm">{t('What is pre-ordered, by company. In the order it was asked for, so the first gets it first.')}</p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {buying.map((c) => (
                                <div key={c.company} className="space-y-2 rounded-md border p-3 text-sm">
                                    <p className="font-semibold">{c.company}</p>
                                    {c.medicines.map((m) => (
                                        <div key={m.name}>
                                            <p className="font-medium">{m.name}</p>
                                            <ShareOut id={m.medicine_id} />
                                            <ol className="text-muted-foreground list-decimal pl-5">
                                                {m.entries.map((e) => (
                                                    <li key={e.id}>
                                                        {e.who} · {num(e.quantity)} {t(unitName(e.unit))}
                                                    </li>
                                                ))}
                                            </ol>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </section>
                )}

                <Availability on={on} />

                {closed.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Recently closed')}</h2>
                        <ul className="divide-y rounded-md border">
                            {closed.map((p) => (
                                <Card key={p.id} p={p} days={days} />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
