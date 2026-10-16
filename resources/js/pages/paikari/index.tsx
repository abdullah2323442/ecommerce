import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import MpoOffers, { type MpoOffer } from '@/components/mpo-offers';
import OrderExtras, { emptyExtras, type Extras } from '@/components/order-extras';
import OfferCard, { type OfferCardData } from '@/components/offers/offer-card';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { noticeText } from '@/lib/preorder';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface CartLine {
    id: number;
    offer?: boolean;
    name: string;
    strength: string | null;
    company: string | null;
    unit: 'piece' | 'strip' | 'box';
    quantity: number;
    unit_price: number;
    total: number;
}

interface Props {
    cart: { id: number; total: number; subtotal: number; lines: CartLine[] } | null;
    freeOver: number;
    offers: OfferCardData[];
    notice: 'holiday' | 'night' | null;
    monthTotal: number | null;
    tierTask: { tier: string; target: number; gap: number } | null;
    notices: { id: number; kind: string; body: string }[];
    usual: { id: number; name: string; strength: string | null; unit: string; quantity: number }[];
    companies: { id: number; name: string }[];
    shortList: { id: number; name: string; strength: string | null; company: string | null; unit: string; quantity: number }[];
    orders: { id: number; status: string; number: string | null; total: number; placed_at: string | null }[];
    reminder: { on: boolean; at: string | null };
}

interface Result {
    id: number;
    name: string;
    strength: string | null;
    company: string | null;
    offer: OfferCardData | null;
    preorder_days?: number | null;
    mpo_offers?: MpoOffer[];
    alternatives?: { id: number; name: string; strength: string | null; company: string | null; unit: 'piece' | 'strip' | 'box'; price: number; percent: number; offer: boolean }[];
    units: { unit: 'piece' | 'strip' | 'box'; mrp: number; price: number; percent: number }[];
}

export const usePortal = () => usePage().props.portal as { base: string; bulk: boolean; moq: number; payments: string[] };

export const unitLabel = (u: string) => (u === 'box' ? 'Box' : u === 'strip' ? 'Strip' : 'Piece');

export function Search({ url, saveable = true, companies = [] }: { url?: string; saveable?: boolean; companies?: { id: number; name: string }[] }) {
    const { t, money, num } = useI18n();
    const { base } = usePortal();
    const target = url ?? `${base}/cart`;
    const [q, setQ] = useState('');
    const [qty, setQty] = useState('1');
    const [results, setResults] = useState<Result[]>([]);
    const [company, setCompany] = useState('');
    // The buyer chooses how to read prices: in taka, or as a percent off the printed price (spec ch 04). Remembered on this device.
    const [view, setView] = useState<'taka' | 'percent'>(() => {
        try {
            return window.localStorage.getItem('price-view') === 'percent' ? 'percent' : 'taka';
        } catch {
            return 'taka';
        }
    });
    const pickView = (v: 'taka' | 'percent') => {
        setView(v);
        try {
            window.localStorage.setItem('price-view', v);
        } catch {
            // Private window: the choice just lasts for this visit.
        }
    };
    const [page, setPage] = useState(1);
    const [more, setMore] = useState(false);

    // Choosing a company lists all of its products; typing a name goes back to the name search.
    useEffect(() => {
        if (company === '') return;
        fetch(`${base}/search?company=${encodeURIComponent(company)}&page=${page}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => {
                setResults((all) => (page === 1 ? d.results : [...all, ...d.results]));
                setMore(Boolean(d.more));
            })
            .catch(() => setResults([]));
    }, [company, page]);

    useEffect(() => {
        if (q.trim().length < 2) {
            if (company === '') setResults([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`${base}/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => setResults(d.results))
                .catch(() => setResults([]));
        }, 200);
        return () => clearTimeout(timer);
    }, [q]);

    const add = (m: Result, unit: string) =>
        router.post(target, { medicine_id: m.id, unit, quantity: qty }, { preserveScroll: true, onSuccess: () => { if (company === '') { setQ(''); setResults([]); } } });

    const save = (m: Result, unit: string) => router.post(`${base}/short-list`, { medicine_id: m.id, unit, quantity: qty }, { preserveScroll: true });

    return (
        <section aria-labelledby="find-h" className="space-y-3">
            <h2 id="find-h" className="font-display text-xl font-bold">
                {t('Find a medicine')}
            </h2>
            <div className="flex gap-2">
                <div className="flex-1">
                    <label htmlFor="q" className="sr-only">
                        {t('Medicine name')}
                    </label>
                    <Input id="q" value={q} onChange={(e) => { setQ(e.target.value); setCompany(''); setMore(false); }} placeholder={t('Medicine name')} autoComplete="off" className="h-12 text-base" />
                </div>
                <div className="w-24">
                    <label htmlFor="qty" className="sr-only">
                        {t('Quantity')}
                    </label>
                    <Input id="qty" type="number" min="0" step="any" inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value)} className="h-12 text-base" />
                </div>
            </div>
            {companies.length > 0 && (
                <div>
                    <label htmlFor="by-company" className="sr-only">
                        {t('Choose a company')}
                    </label>
                    <select id="by-company" value={company} onChange={(e) => { setCompany(e.target.value); setPage(1); setMore(false); setQ(''); if (e.target.value === '') setResults([]); }} className="h-11 w-full rounded-md border bg-[var(--pt-surface)] px-3 text-sm">
                        <option value="">{t('Or choose a company to see all its products')}</option>
                        {companies.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {results.length > 0 && (
                <div role="group" aria-label={t('Show prices as')} className="flex items-center gap-2 text-sm">
                    <span className="text-[var(--pt-muted)]">{t('Show prices as')}</span>
                    {(['taka', 'percent'] as const).map((v) => (
                        <button key={v} type="button" aria-pressed={view === v} onClick={() => pickView(v)} className={`rounded-full border px-3 py-0.5 ${view === v ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)] font-semibold' : ''}`}>
                            {v === 'taka' ? t('Taka') : t('Percent off')}
                        </button>
                    ))}
                </div>
            )}
            {results.length > 0 && (
                <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                    {results.map((m) => (
                        <li key={m.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div className="min-w-48 flex-1">
                                <p className="font-semibold">
                                    {m.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{m.strength}</span>
                                </p>
                                <p className="text-sm text-[var(--pt-muted)]">{m.company}</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {m.units.map((u) => (
                                    <Button key={u.unit} variant="outline" onClick={() => add(m, u.unit)} onContextMenu={(e) => { e.preventDefault(); save(m, u.unit); }} className="h-auto flex-col py-1.5">
                                        <span className="text-xs">{t(unitLabel(u.unit))}</span>
                                        {view === 'percent' && u.percent > 0 ? (
                                            <>
                                                <span className="tabular font-semibold">{t('{n}% off', { n: num(u.percent) })}</span>
                                                <span className="text-[11px] text-[var(--pt-muted)]">{money(u.price)}</span>
                                            </>
                                        ) : (
                                            <>
                                                <span className="tabular font-semibold">{money(u.price)}</span>
                                                {u.percent > 0 && <span className="text-[11px] text-[var(--pt-muted)]">{num(u.percent)}%</span>}
                                            </>
                                        )}
                                    </Button>
                                ))}
                                {saveable && m.preorder_days ? (
                                    <Button variant="outline" size="sm" onClick={() => router.post('/preorders', { medicine_id: m.id, unit: m.units[0].unit, quantity: qty })}>
                                        {t('Pre-order')} · {t('{n} days', { n: num(m.preorder_days) })}
                                    </Button>
                                ) : null}
                                {saveable && (
                                    <Button variant="ghost" size="sm" onClick={() => save(m, m.units[0].unit)}>
                                        {t('Save for later')}
                                    </Button>
                                )}
                            </div>
                            {(m.alternatives ?? []).length > 0 && (
                                <div className="w-full rounded-xl bg-[var(--pt-sage)] px-3 py-2 text-sm">
                                    <p className="text-xs font-semibold text-[var(--pt-muted)]">{t('The same medicine, other brands')}</p>
                                    <ul className="mt-1 space-y-1">
                                        {(m.alternatives ?? []).map((a) => (
                                            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2">
                                                <span>
                                                    {a.name} <span className="text-[var(--pt-muted)]">{a.strength}</span>
                                                    {a.company && <span className="text-[var(--pt-muted)]"> · {a.company}</span>}
                                                    {a.offer && <span className="ms-1 rounded bg-amber-200 px-1.5 text-xs font-semibold">{t('OFFER')}</span>}
                                                </span>
                                                <span className="flex items-center gap-2">
                                                    <span className="tabular">
                                                        {money(a.price)} · {t('{n}% off', { n: num(a.percent) })}
                                                    </span>
                                                    <Button size="sm" variant="outline" onClick={() => add({ id: a.id } as Result, a.unit)}>
                                                        {t('Add')}
                                                    </Button>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {saveable && m.mpo_offers && m.mpo_offers.length > 0 && (
                                <div className="w-full">
                                    <MpoOffers offers={m.mpo_offers} signedIn />
                                </div>
                            )}
                            {saveable && m.offer && (
                                <div className="w-full">
                                    <OfferCard offer={m.offer} postTo={`${base}/offers/${m.offer.id}/add`} />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {company !== '' && more && (
                <Button variant="outline" onClick={() => setPage((p) => p + 1)}>
                    {t('Show more')}
                </Button>
            )}
        </section>
    );
}

function Offers({ items }: { items: OfferCardData[] }) {
    const { t } = useI18n();
    const { base } = usePortal();
    if (items.length === 0) return null;

    return (
        <section aria-labelledby="offers-h" className="space-y-2">
            <h2 id="offers-h" className="font-display text-xl font-bold">
                {t('Offers')}
            </h2>
            <ul className="space-y-2">
                {items.map((o) => (
                    <li key={o.id}>
                        <OfferCard offer={o} postTo={`${base}/offers/${o.id}/add`} showName />
                    </li>
                ))}
            </ul>
        </section>
    );
}

function ShortList({ items }: { items: Props['shortList'] }) {
    const { t, num } = useI18n();
    const { base } = usePortal();
    if (items.length === 0) return null;

    return (
        <section aria-labelledby="short-h" className="space-y-2">
            <h2 id="short-h" className="font-display text-xl font-bold">
                {t('Short list')}
            </h2>
            <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                {items.map((s) => (
                    <li key={s.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                        <div className="min-w-40 flex-1">
                            <p className="font-medium">
                                {s.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{s.strength}</span>
                            </p>
                            <p className="text-xs text-[var(--pt-muted)]">
                                {num(s.quantity)} {t(unitLabel(s.unit))}
                            </p>
                        </div>
                        <Button size="sm" onClick={() => router.post(`${base}/short-list/${s.id}/order`, {}, { preserveScroll: true })}>
                            {t('Order now')}
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => router.delete(`${base}/short-list/${s.id}`, { preserveScroll: true })}>
                            {t('Remove')}
                        </Button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function PrescriptionForm() {
    const { t } = useI18n();
    const { base } = usePortal();
    const form = useForm<{ file: File | null; note: string }>({ file: null, note: '' });

    return (
        <section aria-labelledby="rx-h" className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <h2 id="rx-h" className="font-display text-xl font-bold">
                {t('Send a prescription')}
            </h2>
            <p className="text-sm text-[var(--pt-muted)]">{t('Only our pharmacy staff can see it.')}</p>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`${base}/prescriptions`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="flex flex-wrap items-end gap-2"
            >
                <div>
                    <label htmlFor="rx-file" className="sr-only">
                        {t('Prescription photo or PDF')}
                    </label>
                    <input id="rx-file" type="file" accept="image/jpeg,image/png,application/pdf" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} className="text-sm" />
                </div>
                <Input aria-label={t('Note')} className="w-56" placeholder={t('Note')} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={300} />
                <Button type="submit" disabled={!form.data.file} loading={form.processing}>
                    {t('Send')}
                </Button>
            </form>
            <InputError message={form.errors.file} />
        </section>
    );
}

function Usual({ items }: { items: Props['usual'] }) {
    const { t, num } = useI18n();
    const { base } = usePortal();
    if (items.length === 0) return null;

    return (
        <section aria-labelledby="usual-h" className="space-y-2">
            <h2 id="usual-h" className="font-display text-xl font-bold">
                {t('You often order')}
            </h2>
            <ul className="flex flex-wrap gap-2">
                {items.map((u) => (
                    <li key={`${u.id}-${u.unit}`}>
                        <Button variant="outline" className="h-auto py-2" onClick={() => router.post(`${base}/cart`, { medicine_id: u.id, unit: u.unit, quantity: u.quantity }, { preserveScroll: true })}>
                            {u.name} <span className="text-xs text-[var(--pt-muted)]">{u.strength}</span> · {num(u.quantity)} {t(unitLabel(u.unit))}
                        </Button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function Cart({ cart, freeOver }: { cart: NonNullable<Props['cart']>; freeOver: number }) {
    const { t, money, num } = useI18n();
    const { base } = usePortal();
    const errors = usePage().props.errors as Record<string, string>;
    const { bulk, moq, payments } = usePortal();
    const [mode, setMode] = useState('collect');
    const [note, setNote] = useState('');
    const [terms, setTerms] = useState(payments[0] ?? '');
    const [extras, setExtras] = useState<Extras>(emptyExtras);

    return (
        <section aria-labelledby="cart-h" className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <h2 id="cart-h" className="font-display text-xl font-bold">
                {t('Your order')}
            </h2>
            <ul className="divide-y">
                {cart.lines.map((l) => (
                    <li key={l.id} className="flex items-center gap-3 py-2">
                        <div className="min-w-0 flex-1">
                            <p className="truncate font-medium">
                                {l.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{l.strength}</span>
                            </p>
                            <p className="text-xs text-[var(--pt-muted)]">
                                {l.offer && <strong className="mr-1 text-[var(--pt-deep)]">{t('OFFER')}</strong>}
                                {t(unitLabel(l.unit))} · {money(l.unit_price)}
                            </p>
                        </div>
                        <Input
                            aria-label={t('Quantity')}
                            className="w-20"
                            type="number"
                            min="0"
                            step="any"
                            defaultValue={l.quantity}
                            onBlur={(e) => Number(e.target.value) > 0 && Number(e.target.value) !== l.quantity && router.put(`${base}/cart/${l.id}`, { quantity: e.target.value }, { preserveScroll: true })}
                        />
                        <p className="tabular w-24 text-right font-semibold">{money(l.total)}</p>
                        <Button variant="ghost" size="icon" aria-label={t('Remove')} onClick={() => router.delete(`${base}/cart/${l.id}`, { preserveScroll: true })}>
                            <Trash2 className="size-4" />
                        </Button>
                    </li>
                ))}
            </ul>
            <p className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                <span>{t('Total')}</span>
                <span className="tabular">{money(cart.total)}</span>
            </p>
            <p className="text-xs text-[var(--pt-muted)]">{t('This is your price. We check each item and tell you what we can supply.')}</p>

            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor="mode" className="text-sm font-medium">
                        {t('How will you get it?')}
                    </label>
                    <select id="mode" value={mode} onChange={(e) => setMode(e.target.value)} className="mt-1 block h-10 w-full rounded-md border bg-background px-2 text-sm">
                        <option value="collect">{t('I will collect it')}</option>
                        <option value="send">{t('Send it by courier')}</option>
                        <option value="delivery">{t('Deliver it to me')}</option>
                    </select>
                </div>
                <div>
                    <label htmlFor="note" className="text-sm font-medium">
                        {t('Note')}
                    </label>
                    <Input id="note" className="mt-1" value={note} onChange={(e) => setNote(e.target.value)} maxLength={300} />
                </div>
            </div>
            {freeOver > 0 && mode === 'delivery' && (
                <div className="space-y-1">
                    <div className="h-2 overflow-hidden rounded-full bg-[var(--pt-sage)]">
                        <div className="h-full rounded-full bg-[var(--pt-amber)]" style={{ width: `${Math.min(100, (cart.subtotal / freeOver) * 100)}%` }} />
                    </div>
                    <p className="text-sm text-[var(--pt-muted)]">
                        {cart.subtotal >= freeOver ? t('Delivery is free on this order.') : t('Add {amount} more for free delivery.', { amount: money(freeOver - cart.subtotal) })}
                    </p>
                </div>
            )}
            {bulk && payments.length > 0 && (
                <fieldset className="space-y-1">
                    <legend className="text-sm font-medium">{t('How will you pay?')}</legend>
                    <div className="flex flex-wrap gap-4 text-sm">
                        {payments.map((p) => (
                            <label key={p} className="flex items-center gap-2">
                                <input type="radio" name="terms" checked={terms === p} onChange={() => setTerms(p)} />
                                {t(p === 'cod' ? 'Pay when it arrives' : 'Pay in advance')}
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}
            {bulk && moq > 0 && <p className="text-xs text-[var(--pt-muted)]">{t('Each item needs at least {n} boxes.', { n: num(moq) })}</p>}
            <OrderExtras value={extras} onChange={setExtras} errors={errors} />
            <InputError message={errors.order} />
            <InputError message={errors.payment_terms} />
            <Button className="h-12 w-full text-base" onClick={() => router.post(`${base}/order`, { delivery_mode: mode, note, payment_terms: bulk ? terms : undefined, ...extras }, { forceFormData: true })}>
                {t('Send order')} · {num(cart.lines.length)} {t('items')}
            </Button>
        </section>
    );
}

/** The "time to order" reminder: on by default, off with one tap (spec ch 7). */
function Reminder({ reminder }: { reminder: Props['reminder'] }) {
    const { t, isBn } = useI18n();
    const { base } = usePortal();
    const at = reminder.at ? new Date(2000, 0, 1, Number(reminder.at.slice(0, 2)), Number(reminder.at.slice(3, 5))).toLocaleTimeString(isBn ? 'bn-BD' : 'en-GB', { hour: 'numeric', minute: '2-digit' }) : null;

    return (
        <section aria-labelledby="remind-h" className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
            <div>
                <h2 id="remind-h" className="font-semibold">
                    {t('Order reminder')}
                </h2>
                <p className="text-[var(--pt-muted)]">
                    {!reminder.on ? t('Reminders are off.') : at ? t('We remind you around {time}, when you usually order.', { time: at }) : t('Once we know when you usually order, we will remind you at that time.')}
                </p>
            </div>
            <Button size="sm" variant="outline" onClick={() => router.put(`${base}/reminders`, { on: !reminder.on }, { preserveScroll: true })}>
                {reminder.on ? t('Turn off') : t('Turn on')}
            </Button>
        </section>
    );
}

function BulkUpload() {
    const { t, num } = useI18n();
    const { base } = usePortal();
    const errors = usePage().props.errors as Record<string, string>;
    const result = usePage().props.bulkResult as { added: number; problems: { line: number; text: string; reason: string }[] } | null;
    const [file, setFile] = useState<File | null>(null);
    const sample = `data:text/csv;charset=utf-8,${encodeURIComponent('name,quantity,unit\nNapa 500mg,10,box\n')}`;

    return (
        <section aria-labelledby="bulk-h" className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <h2 id="bulk-h" className="font-display text-xl font-bold">
                {t('Order from a list')}
            </h2>
            <p className="text-sm text-[var(--pt-muted)]">
                {t('Upload a CSV file with one product on each line: name, quantity, unit (piece, strip or box).')} <a href={sample} download="order-list.csv" className="underline underline-offset-4">{t('Download an example')}</a>
            </p>
            <div className="flex flex-wrap items-center gap-2">
                <input type="file" accept=".csv,.txt" aria-label={t('List file')} onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-sm" />
                <Button size="sm" disabled={!file} onClick={() => router.post(`${base}/bulk`, { list: file }, { forceFormData: true, preserveScroll: true })}>
                    {t('Add to my order')}
                </Button>
            </div>
            <InputError message={errors.bulk ?? errors.list} />
            {result && (
                <div role="status" className="space-y-1 text-sm">
                    <p className="font-semibold">{t('{n} lines added to your order.', { n: num(result.added) })}</p>
                    {result.problems.length > 0 && (
                        <>
                            <p>{t('These lines were not added:')}</p>
                            <ul className="list-disc ps-5 text-[var(--pt-muted)]">
                                {result.problems.map((p) => (
                                    <li key={p.line}>
                                        {t('Line {n}', { n: num(p.line) })}: {p.text} — {p.reason}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
            )}
        </section>
    );
}

export default function PaikariHome({ cart, orders, freeOver, shortList, notice, monthTotal, usual, tierTask, notices, offers, reminder, companies }: Props) {
    const { t, money, dateTime } = useI18n();
    const { base, bulk } = usePortal();
    const status = usePage().props.flash as { status?: string } | undefined;
    const errors = usePage().props.errors as Record<string, string>;
    const state = (s: string) => t(s === 'placed' ? 'Being checked' : s === 'verified' ? 'Ready' : s === 'final' ? 'Done' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('Order')} />
            <div className="mx-auto max-w-3xl space-y-8">
                {status?.status && <p className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-800">{status.status}</p>}
                <InputError message={!cart ? errors.order : undefined} />
                <div className="flex items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Order')}</h1>
                    <div className="flex gap-4">
                        {bulk && (
                            <Link href={`${base}/quotes`} className="text-sm font-semibold underline underline-offset-4">
                                {t('Ask for a price')}
                            </Link>
                        )}
                        {bulk && (
                            <Link href={`${base}/account`} className="text-sm font-semibold underline underline-offset-4">
                                {t('Your account')}
                            </Link>
                        )}
                        <Link href="/inbox" className="text-sm font-semibold underline underline-offset-4">
                            {t('Messages')}
                        </Link>
                        <Link href="/preorders" className="text-sm font-semibold underline underline-offset-4">
                            {t('Pre-orders')}
                        </Link>
                        <Link href={`${base}/returns`} className="text-sm font-semibold underline underline-offset-4">
                            {t('Returns')}
                        </Link>
                    </div>
                </div>
                {notice && (
                    <p role="note" className="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">
                        {notice === 'holiday' ? t('We are closed today. You can still order. We will check it on the next working day.') : t('It is night. You can still order. We will check it in the morning.')}
                    </p>
                )}
                {notices.map((n) => (
                    <div key={n.id} role="status" className="flex items-center gap-3 rounded-2xl border border-[var(--pt-amber)]/50 bg-[var(--pt-amber)]/10 px-4 py-3">
                        <p className="flex-1 font-semibold">{noticeText(n, t)}</p>
                        <Button size="sm" variant="ghost" onClick={() => router.post(`${base}/notices/${n.id}/read`, {}, { preserveScroll: true })}>
                            {t('Close')}
                        </Button>
                    </div>
                ))}
                {tierTask && (
                    <div className="rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <p className="font-semibold">{tierTask.gap > 0 ? t('Buy {amount} more this month and you move up to tier {tier}.', { amount: money(tierTask.gap), tier: tierTask.tier }) : t('You have reached the target for tier {tier}. We will check and upgrade you.', { tier: tierTask.tier })}</p>
                    </div>
                )}
                {monthTotal !== null && (
                    <div className="rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <p className="text-sm text-[var(--pt-muted)]">{t('Your orders this month')}</p>
                        <p className="tabular font-display text-2xl font-extrabold">{money(monthTotal)}</p>
                    </div>
                )}
                <Offers items={offers} />
                <Search companies={companies} />
                {cart && cart.lines.length > 0 && <Cart cart={cart} freeOver={freeOver} />}
                {bulk && <BulkUpload />}
                <ShortList items={shortList} />
                <Usual items={usual} />
                {!bulk && <PrescriptionForm />}
                <Reminder reminder={reminder} />

                <section aria-labelledby="orders-h" className="space-y-2">
                    <h2 id="orders-h" className="font-display text-xl font-bold">
                        {t('Your orders')}
                    </h2>
                    {orders.length === 0 ? (
                        <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-[var(--pt-muted)]">{t('No orders yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                            {orders.map((o) => (
                                <li key={o.id}>
                                    <Link href={`${base}/orders/${o.id}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                        <span className="min-w-32 flex-1 font-medium">{o.number ?? `#${o.id}`}</span>
                                        <span className="text-sm text-[var(--pt-muted)]">{o.placed_at && dateTime(o.placed_at)}</span>
                                        <span className="text-sm font-semibold">{state(o.status)}</span>
                                        <span className="tabular w-24 text-right">{money(o.total)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </PublicLayout>
    );
}
