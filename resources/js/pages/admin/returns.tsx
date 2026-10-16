import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Card {
    id: number;
    status: string;
    customer: string | null;
    phone: string | null;
    memo: string | null;
    retail: boolean;
    without_invoice: boolean;
    resolution: string;
    reason_code: string;
    reason: string;
    has_voice?: boolean;
    amount: number;
    credit_amount: number;
    cash_amount: number;
    expected_money: number | null;
    fault: string | null;
    lines: { name: string; strength: string | null; unit: string; quantity: number; was_offer: boolean }[];
}

interface Found {
    id: number;
    number: string;
    when: string | null;
    customer: string | null;
    retail: boolean;
    reprinted: boolean;
    lines: {
        id: number;
        name: string;
        strength: string | null;
        unit: string;
        quantity: number;
        unit_price: number;
        offer: boolean;
        returnable: boolean;
        previously_returned: boolean;
    }[];
}

interface Props {
    queue: Card[];
    recent: Card[];
    canApprove: boolean;
    policy: { days: number; cash_percent: number };
    analysis: { reasons: { reason: string; n: number; amount: number }[]; products: { name: string; n: number }[] } | null;
    staleCredit: { id: number; name: string; balance: number; days: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Returns', href: '/admin/returns' }];
const REASONS = ['wrong_item', 'damaged', 'near_expiry', 'ordered_by_mistake', 'staff_error', 'other'];
const reasonLabel = (r: string) =>
    ({
        wrong_item: 'Wrong item',
        damaged: 'Damaged',
        near_expiry: 'Near expiry',
        ordered_by_mistake: 'Ordered by mistake',
        staff_error: 'Our mistake',
        other: 'Other',
    })[r] ?? 'Other';

function Finder({ policy }: { policy: Props['policy'] }) {
    const { t, money, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [number, setNumber] = useState('');
    const [date, setDate] = useState('');
    const [medicine, setMedicine] = useState('');
    const [results, setResults] = useState<Found[]>([]);
    const [pick, setPick] = useState<Found | null>(null);
    const [sel, setSel] = useState<Record<number, { unit: string; quantity: string }>>({});
    const [reasonCode, setReasonCode] = useState('damaged');
    const [reason, setReason] = useState('');
    const [resolution, setResolution] = useState('credit');
    const [expected, setExpected] = useState('');

    const search = () => {
        const q = new URLSearchParams();
        if (number) q.set('number', number);
        if (date) q.set('date', date);
        if (medicine) q.set('medicine', medicine);
        fetch(`/admin/returns/find?${q}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => setResults(d.results))
            .catch(() => setResults([]));
    };

    const items = Object.entries(sel)
        .filter(([, v]) => Number(v.quantity) > 0)
        .map(([id, v]) => ({ line_id: Number(id), unit: v.unit, quantity: v.quantity }));

    const submit = () =>
        router.post(
            '/admin/returns',
            { invoice_id: pick!.id, items, reason_code: reasonCode, reason, resolution, expected_money: expected || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setPick(null);
                    setSel({});
                    setReason('');
                    setResults([]);
                },
            },
        );

    return (
        <section aria-labelledby="find-h" className="space-y-3 rounded-md border p-3">
            <h2 id="find-h" className="font-medium">
                {t('Find the sale')}
            </h2>
            <div className="flex flex-wrap items-end gap-2">
                <label className="text-muted-foreground text-xs">
                    {t('Memo number')}
                    <Input value={number} onChange={(e) => setNumber(e.target.value)} className="w-36" />
                </label>
                <label className="text-muted-foreground text-xs">
                    {t('Date')}
                    <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="w-40" />
                </label>
                <label className="text-muted-foreground text-xs">
                    {t('Medicine name')}
                    <Input value={medicine} onChange={(e) => setMedicine(e.target.value)} className="w-44" />
                </label>
                <Button variant="outline" onClick={search}>
                    {t('Search')}
                </Button>
            </div>
            <p className="text-muted-foreground text-xs">
                {t('Returns are for shops, within {n} days of receiving. Cash back is {p}%. Credit or exchange is the full price they paid.', {
                    n: policy.days,
                    p: policy.cash_percent,
                })}
            </p>

            {!pick && (
                <ul className="divide-y rounded-md border">
                    {results.map((r) => (
                        <li key={r.id}>
                            <button
                                type="button"
                                className="hover:bg-muted/50 flex w-full flex-wrap items-center gap-3 px-3 py-2 text-left"
                                onClick={() => setPick(r)}
                            >
                                <span className="font-medium">{r.number}</span>
                                <span className="text-muted-foreground text-sm">{r.customer ?? t('Retail')}</span>
                                <span className="text-muted-foreground text-sm">{r.when && dateTime(r.when)}</span>
                                {r.reprinted && <span className="rounded bg-amber-500/20 px-1.5 text-xs font-semibold">{t('Reprint')}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {pick && (
                <div className="space-y-3">
                    <p className="text-sm">
                        <strong>{pick.number}</strong> · {pick.customer ?? t('Retail')}{' '}
                        <button type="button" className="underline" onClick={() => setPick(null)}>
                            {t('Change')}
                        </button>
                    </p>
                    <ul className="divide-y rounded-md border">
                        {pick.lines.map((l) => (
                            <li key={l.id} className={`flex flex-wrap items-center gap-3 px-3 py-2 ${l.returnable ? '' : 'opacity-50'}`}>
                                <div className="min-w-44 flex-1">
                                    <p className="font-medium">
                                        {l.name} <span className="text-muted-foreground text-sm font-normal">{l.strength}</span>
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {l.quantity} {t(l.unit === 'box' ? 'Box' : l.unit === 'strip' ? 'Strip' : 'Piece')} · {money(l.unit_price)}
                                        {l.offer && ` · ${t('OFFER')}`}
                                        {l.previously_returned && ` · ${t('Previously returned')}`}
                                    </p>
                                </div>
                                {l.returnable ? (
                                    <>
                                        <select
                                            aria-label={t('Unit')}
                                            className="bg-background h-9 rounded-md border px-2 text-sm"
                                            value={sel[l.id]?.unit ?? l.unit}
                                            onChange={(e) =>
                                                setSel((s) => ({ ...s, [l.id]: { unit: e.target.value, quantity: s[l.id]?.quantity ?? '' } }))
                                            }
                                        >
                                            <option value="box">{t('Box')}</option>
                                            <option value="strip">{t('Strip')}</option>
                                            <option value="piece">{t('Piece')}</option>
                                        </select>
                                        <Input
                                            aria-label={t('Quantity')}
                                            className="h-9 w-24"
                                            type="number"
                                            min="0"
                                            step="any"
                                            placeholder={t('How much')}
                                            value={sel[l.id]?.quantity ?? ''}
                                            onChange={(e) =>
                                                setSel((s) => ({ ...s, [l.id]: { unit: s[l.id]?.unit ?? l.unit, quantity: e.target.value } }))
                                            }
                                        />
                                    </>
                                ) : (
                                    <span className="text-muted-foreground text-xs">{t('Not returnable')}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <label className="text-muted-foreground text-xs">
                            {t('Why')}
                            <select
                                value={reasonCode}
                                onChange={(e) => setReasonCode(e.target.value)}
                                className="bg-background text-foreground block h-9 w-full rounded-md border px-2 text-sm"
                            >
                                {REASONS.map((r) => (
                                    <option key={r} value={r}>
                                        {t(reasonLabel(r))}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-muted-foreground text-xs">
                            {t('They want')}
                            <select
                                value={resolution}
                                onChange={(e) => setResolution(e.target.value)}
                                className="bg-background text-foreground block h-9 w-full rounded-md border px-2 text-sm"
                            >
                                <option value="credit">{t('Credit on the next memo')}</option>
                                <option value="exchange">{t('Exchange for another medicine')}</option>
                                <option value="cash">{t('Cash back')}</option>
                            </select>
                        </label>
                        {pick.retail && (
                            <label className="text-muted-foreground text-xs">
                                {t('Will receive money (optional)')}
                                <Input type="number" min="0" step="any" value={expected} onChange={(e) => setExpected(e.target.value)} />
                            </label>
                        )}
                    </div>
                    <label className="text-muted-foreground block text-xs">
                        {t('Reason (required)')}
                        <Input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
                    </label>
                    <InputError message={errors.return ?? errors.reason} />
                    <Button disabled={items.length === 0 || reason.trim() === ''} onClick={submit}>
                        {t('Record the return')}
                    </Button>
                </div>
            )}
        </section>
    );
}

function Pending({ r, canApprove }: { r: Card; canApprove: boolean }) {
    const { t, money, num } = useI18n();
    const [fault, setFault] = useState('');

    return (
        <li className="space-y-2 px-4 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <div className="min-w-48 flex-1">
                    <p className="font-medium">
                        {r.customer ?? t('Retail')} {r.memo && <span className="text-muted-foreground text-sm font-normal">· {r.memo}</span>}
                        {r.without_invoice && <span className="ml-2 rounded bg-red-500/15 px-1.5 text-xs font-semibold">{t('No memo')}</span>}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {t(reasonLabel(r.reason_code))}: {r.reason}
                    </p>
                    {r.has_voice && <audio controls preload="none" src={`/admin/returns/${r.id}/voice`} className="mt-1 h-9 max-w-full" />}
                </div>
                <p className="tabular font-display text-xl font-extrabold">{money(r.amount)}</p>
            </div>
            <ul className="text-muted-foreground text-sm">
                {r.lines.map((l, i) => (
                    <li key={i}>
                        {num(l.quantity)} {t(l.unit === 'box' ? 'Box' : l.unit === 'strip' ? 'Strip' : 'Piece')} · {l.name} {l.strength}{' '}
                        {l.was_offer && `· ${t('OFFER')}`}
                    </li>
                ))}
            </ul>
            <p className="text-sm">
                {t(r.resolution === 'cash' ? 'Cash back' : r.resolution === 'exchange' ? 'Exchange for another medicine' : 'Credit on the next memo')}
                {r.resolution === 'cash' && ` · ${t('pays {amount}', { amount: money(r.amount / 2) })}`}
            </p>
            {canApprove ? (
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label={t('Whose mistake')}
                        value={fault}
                        onChange={(e) => setFault(e.target.value)}
                        className="bg-background h-9 rounded-md border px-2 text-sm"
                    >
                        <option value="">{t('No one’s mistake')}</option>
                        <option value="staff">{t('Our staff’s mistake')}</option>
                        <option value="shop">{t('The shop’s mistake')}</option>
                    </select>
                    <Button
                        size="sm"
                        onClick={() => router.post(`/admin/returns/${r.id}/approve`, { fault: fault || null }, { preserveScroll: true })}
                    >
                        {t('Approve')}
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => router.post(`/admin/returns/${r.id}/reject`, {}, { preserveScroll: true })}>
                        {t('Decline')}
                    </Button>
                </div>
            ) : (
                <p className="text-muted-foreground text-xs">{t('Waiting for the admin to check and approve.')}</p>
            )}
        </li>
    );
}

function WithoutMemo() {
    const { t } = useI18n();
    const [q, setQ] = useState('');
    const [found, setFound] = useState<{ id: number; brand_name: string; strength: string | null }[]>([]);
    const [pick, setPick] = useState<{ id: number; brand_name: string } | null>(null);
    const [f, setF] = useState({ unit: 'piece', quantity: '', price: '', reason: '', resolution: 'cash' });

    const lookup = (v: string) => {
        setQ(v);
        if (v.trim().length < 2) return setFound([]);
        fetch(`/medicines/suggest?q=${encodeURIComponent(v)}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => setFound(d.data.slice(0, 5)))
            .catch(() => setFound([]));
    };

    return (
        <details className="rounded-md border p-3">
            <summary className="cursor-pointer font-medium">{t('Return without a memo (admin)')}</summary>
            <div className="mt-3 space-y-2">
                {pick ? (
                    <p className="text-sm">
                        <strong>{pick.brand_name}</strong>{' '}
                        <button type="button" className="underline" onClick={() => setPick(null)}>
                            {t('Change')}
                        </button>
                    </p>
                ) : (
                    <>
                        <Input aria-label={t('Medicine name')} value={q} onChange={(e) => lookup(e.target.value)} placeholder={t('Medicine name')} />
                        {found.map((m) => (
                            <button
                                key={m.id}
                                type="button"
                                className="hover:bg-muted block w-full px-2 py-1 text-left text-sm"
                                onClick={() => setPick(m)}
                            >
                                {m.brand_name} {m.strength}
                            </button>
                        ))}
                    </>
                )}
                <div className="flex flex-wrap gap-2">
                    <select
                        value={f.unit}
                        onChange={(e) => setF({ ...f, unit: e.target.value })}
                        aria-label={t('Unit')}
                        className="bg-background h-9 rounded-md border px-2 text-sm"
                    >
                        <option value="piece">{t('Piece')}</option>
                        <option value="strip">{t('Strip')}</option>
                        <option value="box">{t('Box')}</option>
                    </select>
                    <Input
                        className="w-24"
                        type="number"
                        min="0"
                        step="any"
                        placeholder={t('How much')}
                        value={f.quantity}
                        onChange={(e) => setF({ ...f, quantity: e.target.value })}
                    />
                    <Input
                        className="w-28"
                        type="number"
                        min="0"
                        step="any"
                        placeholder={t('Price each')}
                        value={f.price}
                        onChange={(e) => setF({ ...f, price: e.target.value })}
                    />
                    <Input
                        className="min-w-48 flex-1"
                        placeholder={t('Reason (required)')}
                        value={f.reason}
                        onChange={(e) => setF({ ...f, reason: e.target.value })}
                    />
                </div>
                <Button
                    disabled={!pick || !f.quantity || !f.price || !f.reason}
                    onClick={() =>
                        router.post(
                            '/admin/returns/without-invoice',
                            { medicine_id: pick!.id, ...f, reason_code: 'other' },
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setPick(null);
                                    setQ('');
                                },
                            },
                        )
                    }
                >
                    {t('Record the return')}
                </Button>
            </div>
        </details>
    );
}

export default function Returns({ queue, recent, canApprove, policy, analysis, staleCredit }: Props) {
    const { t, money, num } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Returns')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <h1 className="font-display text-2xl font-extrabold">{t('Returns')}</h1>

                <Finder policy={policy} />

                <section aria-labelledby="q-h" className="space-y-2">
                    <h2 id="q-h" className="font-medium">
                        {t('Waiting to be approved')}
                    </h2>
                    {queue.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('No returns waiting.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {queue.map((r) => (
                                <Pending key={r.id} r={r} canApprove={canApprove} />
                            ))}
                        </ul>
                    )}
                </section>

                {canApprove && <WithoutMemo />}

                {staleCredit.length > 0 && (
                    <section className="space-y-1 rounded-md border border-amber-500/50 bg-amber-500/5 p-3 text-sm">
                        <h2 className="font-medium">{t('Credit not used for a long time')}</h2>
                        {staleCredit.map((c) => (
                            <p key={c.id}>
                                {t('{name} has {amount} credit, {n} days old.', { name: c.name, amount: money(c.balance), n: num(c.days) })}
                            </p>
                        ))}
                    </section>
                )}

                {analysis && (analysis.reasons.length > 0 || analysis.products.length > 0) && (
                    <section aria-labelledby="an-h" className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <h2 id="an-h" className="mb-1 font-medium">
                                {t('Why things come back (90 days)')}
                            </h2>
                            <ul className="divide-y rounded-md border text-sm">
                                {analysis.reasons.map((r) => (
                                    <li key={r.reason} className="flex justify-between px-3 py-1.5">
                                        <span>{t(reasonLabel(r.reason))}</span>
                                        <span className="tabular">
                                            {num(r.n)} · {money(r.amount)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        <div>
                            <h2 className="mb-1 font-medium">{t('Returned most')}</h2>
                            <ul className="divide-y rounded-md border text-sm">
                                {analysis.products.map((p) => (
                                    <li key={p.name} className="flex justify-between px-3 py-1.5">
                                        <span>{p.name}</span>
                                        <span className="tabular">{num(p.n)}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </section>
                )}

                {recent.length > 0 && (
                    <section aria-labelledby="rc-h" className="space-y-2">
                        <h2 id="rc-h" className="font-medium">
                            {t('Recently decided')}
                        </h2>
                        <ul className="divide-y rounded-md border text-sm">
                            {recent.map((r) => (
                                <li key={r.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                                    <span className="min-w-40 flex-1">
                                        {r.customer ?? t('Retail')} {r.memo && `· ${r.memo}`}
                                    </span>
                                    <span className={r.status === 'approved' ? 'font-semibold text-emerald-700' : 'text-muted-foreground'}>
                                        {t(r.status === 'approved' ? 'Approved' : 'Declined')}
                                    </span>
                                    <span className="tabular">{money(r.credit_amount || r.cash_amount || r.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
