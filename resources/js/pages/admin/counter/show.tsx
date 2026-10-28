import ChangeCustomer from '@/components/admin/change-customer';
import ControlledDetails, { type ControlledItem } from '@/components/admin/controlled-details';
import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import OfferCard, { type OfferCardData } from '@/components/offers/offer-card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useSimpleCounter } from '@/hooks/use-simple-counter';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { postJson } from '@/lib/post-json';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { FileDown, Mail, MessageCircle, Printer, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Line {
    id: number;
    name: string;
    strength: string | null;
    company: string | null;
    unit: 'piece' | 'strip' | 'box';
    quantity: number;
    unit_price: number;
    unit_mrp: number;
    percent_off: number;
    offer?: boolean;
    special: boolean;
    held?: boolean;
    outside: boolean;
    total: number;
}

interface Invoice {
    id: number;
    kind: 'retail' | 'paikari' | 'wholesale';
    status: 'draft' | 'placed' | 'verified' | 'final' | 'cancelled';
    number: string | null;
    label: string | null;
    customer: string | null;
    phone: string | null;
    invoice_percent: string | null;
    end_discount_percent: string;
    totals: { subtotal: number; discount: number; delivery: number; total: number };
    below_cost: number;
    finalised_at: string | null;
    note: string | null;
    credit_applied: number;
    token_applied: number;
    corrects: string | null;
    can_correct: boolean;
    print_count: number;
    payment_method: string | null;
    paid_amount: number;
    lines_detail: Line[];
}

interface Result {
    id: number;
    name: string;
    strength: string | null;
    form: string | null;
    company: string | null;
    offer: OfferCardData | null;
    similar?: boolean;
    units: { unit: 'piece' | 'strip' | 'box'; mrp: number; price: number; percent: number }[];
}

interface Props {
    invoice: Invoice;
    working: string | null;
    otherDrafts: { id: number; label: string }[];
    limit: number;
    policy: { days: number; cash_percent: number };
    approvals: { id: number; kind: string; value: number; status: string; applied: boolean }[];
    isAdmin: boolean;
    popular: { id: number; name: string; strength: string | null }[];
    controlled: ControlledItem[];
    controlledDefaults: { buyer_name: string | null; buyer_phone: string | null } | null;
}

function Search({ invoice, popular, simple }: { invoice: Invoice; popular: Props['popular']; simple: boolean }) {
    const { t, money, num } = useI18n();
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Result[]>([]);
    const [qty, setQty] = useState('1');
    const [special, setSpecial] = useState('');
    const [outside, setOutside] = useState(false);
    const [hot, setHot] = useState(0);
    // A scanned code nobody has linked yet: the next medicine picked is linked to it (spec 6.10).
    const [unknown, setUnknown] = useState<string | null>(null);
    const box = useRef<HTMLInputElement>(null);

    // "/" jumps to the search box from anywhere on the screen (keyboard shortcut, spec 4.3.5).
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const tag = (e.target as HTMLElement)?.tagName;
            if (e.key === '/' && tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA') {
                e.preventDefault();
                box.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => setHot(0), [results]);

    useEffect(() => {
        if (q.trim().length < 2) {
            setResults([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/admin/counter/${invoice.id}/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => setResults(d.results))
                .catch(() => setResults([]));
        }, 200);
        return () => clearTimeout(timer);
    }, [q, invoice.id]);

    const add = (m: Result, unit: string) =>
        unknown
            ? router.post(`/admin/counter/${invoice.id}/scan`, { code: unknown, medicine_id: m.id }, { preserveScroll: true, onSuccess: () => { setUnknown(null); setQ(''); setResults([]); box.current?.focus(); } })
            : router.post(
            `/admin/counter/${invoice.id}/lines`,
            { medicine_id: m.id, unit, quantity: qty, special_percent: special === '' ? null : special, outside },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setQ('');
                    setResults([]);
                    box.current?.focus();
                },
            },
        );

    // A barcode scanner types the digits and presses Enter: that adds one box, and scanning again adds another.
    const onKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown' && results.length) {
            e.preventDefault();
            setHot((h) => Math.min(results.length - 1, h + 1));
        } else if (e.key === 'ArrowUp' && results.length) {
            e.preventDefault();
            setHot((h) => Math.max(0, h - 1));
        } else if (e.key === 'Escape') {
            setQ('');
            setResults([]);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (/^\d{6,}$/.test(q.trim())) {
                router.post(`/admin/counter/${invoice.id}/scan`, { code: q.trim() }, { preserveScroll: true, onSuccess: () => setQ(''), onError: (errs) => { if (errs.unknown_barcode) { setUnknown(errs.unknown_barcode); setQ(''); } } });
            } else if (results[hot]) {
                add(results[hot], results[hot].units[0].unit);
            }
        }
    };

    return (
        <section aria-labelledby="add-h" className="space-y-3">
            <h2 id="add-h" className="font-medium">
                {t('Add medicine')}
            </h2>
            {unknown && (
                <div role="alert" className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">
                    <p>{t('Barcode {code} is new. Search the medicine below and pick it, and it is remembered.', { code: unknown })}</p>
                    <Button type="button" size="sm" variant="ghost" onClick={() => setUnknown(null)}>
                        {t('Cancel')}
                    </Button>
                </div>
            )}
            <div className="flex flex-wrap items-end gap-2">
                <div className="min-w-48 flex-1">
                    <label htmlFor="med-q" className="sr-only">
                        {t('Medicine name')}
                    </label>
                    <Input
                        id="med-q"
                        ref={box}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={onKeyDown}
                        placeholder={t('Medicine name, or scan a barcode')}
                        autoComplete="off"
                        autoFocus
                    />
                </div>
                <div className="w-24">
                    <label htmlFor="qty" className="text-muted-foreground text-xs">
                        {t('Quantity')}
                    </label>
                    <Input id="qty" type="number" min="0" step="any" inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value)} />
                </div>
                {!simple && (
                    <div className="w-24">
                        <label htmlFor="sp" className="text-muted-foreground text-xs">
                            {t('Special %')}
                        </label>
                        <Input
                            id="sp"
                            type="number"
                            min="0"
                            max="100"
                            step="any"
                            inputMode="decimal"
                            value={special}
                            onChange={(e) => setSpecial(e.target.value)}
                        />
                    </div>
                )}
                {!simple && (
                    <label className="flex items-center gap-2 pb-2 text-sm">
                        <input type="checkbox" checked={outside} onChange={(e) => setOutside(e.target.checked)} />
                        {t('Bring from outside')}
                    </label>
                )}
            </div>
            {results.length === 0 && q.trim().length < 2 && popular.length > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-muted-foreground text-xs">{t('Most sold')}</span>
                    {popular.map((m) => (
                        <Button
                            key={m.id}
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setQ(m.name);
                                box.current?.focus();
                            }}
                        >
                            {m.name} <span className="text-muted-foreground text-xs">{m.strength}</span>
                        </Button>
                    ))}
                </div>
            )}
            <p className="text-muted-foreground text-xs">{t('Shortcuts: / search · ↑ ↓ choose · Enter add · Esc clear')}</p>
            {results.some((m) => m.similar) && (
                <p role="alert" className="rounded-md border border-warning/50 bg-warning/10 px-3 py-1.5 text-sm font-semibold">
                    {t('Some of these names look alike. Check the strength before you add.')}
                </p>
            )}
            {results.length > 0 && (
                <ul className="divide-y rounded-md border">
                    {results.map((m, i) => (
                        <li key={m.id} className={`flex flex-wrap items-center gap-3 px-3 py-2 ${i === hot ? 'bg-muted' : ''}`}>
                            <div className="min-w-48 flex-1">
                                <p className="font-medium">
                                    {m.name} <span className="text-sm font-bold">{m.strength}</span>
                                </p>
                                <p className="text-muted-foreground text-sm">{m.company}</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {m.units.map((u) => (
                                    <Button key={u.unit} variant="outline" size="sm" onClick={() => add(m, u.unit)} className="h-auto flex-col py-1">
                                        <span className="text-xs">{t(u.unit === 'box' ? 'Box' : u.unit === 'strip' ? 'Strip' : 'Piece')}</span>
                                        <span className="tabular font-semibold">{money(u.price)}</span>
                                        {u.percent > 0 && <span className="text-muted-foreground text-[11px]">{num(u.percent)}%</span>}
                                    </Button>
                                ))}
                            </div>
                            {m.offer && (
                                <div className="w-full">
                                    <OfferCard offer={m.offer} postTo={`/admin/counter/${invoice.id}/offers/${m.offer.id}`} />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** The invoice's percent would sell this line at a loss: it kept its price and asks for a lower percent of its own (spec 6.2). */
function HeldForLoss({ invoiceId, lineId }: { invoiceId: number; lineId: number }) {
    const { t } = useI18n();
    const [pct, setPct] = useState('');

    return (
        <div role="note" className="mt-1 flex flex-wrap items-center gap-2 rounded-md bg-warning/15 px-2 py-1 text-xs text-warning">
            <span>{t('The invoice percent would sell this at a loss. It kept its price. Give it a lower percent of its own.')}</span>
            <Input
                aria-label={t('Percent for this line')}
                className="h-7 w-16"
                type="number"
                min="0"
                max="99.99"
                step="any"
                value={pct}
                onChange={(e) => setPct(e.target.value)}
            />
            <Button
                size="sm"
                className="h-7"
                disabled={pct === ''}
                onClick={() => router.put(`/admin/counter/${invoiceId}/lines/${lineId}/percent`, { percent: pct }, { preserveScroll: true })}
            >
                {t('Set')}
            </Button>
        </div>
    );
}

function LineRow({ invoice, line, others }: { invoice: Invoice; line: Line; others: { id: number; label: string }[] }) {
    const { t, money, num } = useI18n();
    const draft = invoice.status === 'draft';
    const [qty, setQty] = useState(String(line.quantity));

    useEffect(() => setQty(String(line.quantity)), [line.quantity]);

    const save = () => {
        if (qty !== String(line.quantity) && Number(qty) > 0)
            router.put(`/admin/counter/${invoice.id}/lines/${line.id}`, { quantity: qty }, { preserveScroll: true });
    };

    return (
        <tr className="border-t">
            <td className="py-2 pr-2">
                <p className="font-medium">
                    {line.name} <span className="text-muted-foreground text-sm font-normal">{line.strength}</span>
                </p>
                <p className="text-muted-foreground text-xs">
                    {t(line.unit === 'box' ? 'Box' : line.unit === 'strip' ? 'Strip' : 'Piece')}
                    {line.offer && ` · ${t('OFFER')}`}
                    {line.outside && ` · ${t('From outside')}`}
                    {line.special && ` · ${num(line.percent_off)}%`}
                </p>
                {line.held && draft && <HeldForLoss invoiceId={invoice.id} lineId={line.id} />}
            </td>
            <td className="w-24 py-2">
                {draft ? (
                    <Input
                        aria-label={t('Quantity')}
                        type="number"
                        min="0"
                        step="any"
                        value={qty}
                        onChange={(e) => setQty(e.target.value)}
                        onBlur={save}
                        onKeyDown={(e) => e.key === 'Enter' && save()}
                    />
                ) : (
                    <span className="tabular">{num(line.quantity)}</span>
                )}
            </td>
            <td className="tabular hidden py-2 text-right sm:table-cell">{money(line.unit_price)}</td>
            <td className="tabular py-2 text-right font-semibold">{money(line.total)}</td>
            {draft && (
                <td className="w-10 py-2 text-right">
                    {others.length > 0 && (
                        <select
                            aria-label={t('Move to another draft')}
                            value=""
                            className="mr-1 h-8 w-24 rounded-md border bg-background px-1 text-xs"
                            onChange={(e) => e.target.value && router.post(`/admin/counter/${invoice.id}/lines/${line.id}/move`, { to: e.target.value }, { preserveScroll: true })}
                        >
                            <option value="">{t('Move…')}</option>
                            {others.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.label}
                                </option>
                            ))}
                        </select>
                    )}
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={t('Remove')}
                        onClick={() => router.delete(`/admin/counter/${invoice.id}/lines/${line.id}`, { preserveScroll: true })}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </td>
            )}
        </tr>
    );
}

/** Ask for the memo's link, then open WhatsApp or the mail program with the words and the link already written. */
async function shareMemo(id: number, how: 'whatsapp' | 'email') {
    const r = await postJson<{ url: string; phone: string; email: string | null; subject: string; text: string }>(`/admin/counter/${id}/share`);
    if (!r) return;

    const target =
        how === 'whatsapp'
            ? `https://wa.me/${r.phone}?text=${encodeURIComponent(r.text)}`
            : `mailto:${r.email ?? ''}?subject=${encodeURIComponent(r.subject)}&body=${encodeURIComponent(r.text)}`;
    window.open(target, '_blank', 'noopener');
}

export default function CounterShow({ invoice, working, otherDrafts, limit, policy, approvals, isAdmin, popular, controlled, controlledDefaults }: Props) {
    const { t, money, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const draft = invoice.status === 'draft';
    const [simple, setSimple] = useSimpleCounter();
    const retail = invoice.kind === 'retail';
    const closing = draft || invoice.status === 'verified'; // a checked online order is finished here too
    // A finished memo has had the token and the credit taken off; the lines alone still add up to the full amount.
    const owed = invoice.status === 'final' ? invoice.totals.total - invoice.credit_applied - invoice.token_applied : invoice.totals.total;
    const [method, setMethod] = useState('cash');
    const [paid, setPaid] = useState('');
    const [pct, setPct] = useState(String((retail ? invoice.end_discount_percent : invoice.invoice_percent) ?? ''));
    const kind = retail ? 'end_discount' : 'invoice_percent';
    const over = !isAdmin && pct !== '' && Number(pct) > limit;
    const asked = approvals.find((a) => a.kind === kind);
    const waiting = asked?.status === 'pending';

    // Tell the server this draft is open here, and learn who else has it open. Others can still come in.
    const [other, setOther] = useState<string | null>(working);
    useEffect(() => {
        if (!draft) return;
        const beat = () => postJson<{ other: string | null }>(`/admin/counter/${invoice.id}/working`).then((r) => r && setOther(r.other));
        beat(); // at once, so someone opening it a moment later sees this person
        const timer = setInterval(beat, 30000);
        return () => clearInterval(timer);
    }, [draft, invoice.id]);

    // While a request is open the screen checks for the admin's answer by itself.
    useEffect(() => {
        if (!waiting) return;
        const timer = setInterval(() => router.reload({ only: ['invoice', 'approvals'] }), 5000);
        return () => clearInterval(timer);
    }, [waiting]);

    const crumbs: BreadcrumbItem[] = [
        { title: 'Counter', href: '/admin/counter' },
        { title: invoice.number ?? 'Draft', href: `/admin/counter/${invoice.id}` },
    ];

    const who = invoice.customer ?? (invoice.label ? t(invoice.label.replace(/ \d+$/, '')) + (invoice.label.match(/ \d+$/)?.[0] ?? '') : t('Draft'));

    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title={invoice.number ?? t('Draft')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4 print:max-w-none print:p-0">
                <Flash />
                {other && (
                    <p role="status" className="rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm font-semibold">
                        {t('{name} is working on this draft.', { name: other })}
                    </p>
                )}
                <InputError message={errors.invoice} />

                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{who}</h1>
                        {draft && !retail && !simple && <ChangeCustomer invoiceId={invoice.id} />}
                        <p className="text-muted-foreground text-sm">
                            {invoice.phone && `${invoice.phone} · `}
                            {t(retail ? 'Retail' : invoice.kind === 'paikari' ? 'Shop' : 'Wholesale')}
                            {invoice.number && ` · ${t('Memo')} ${invoice.number}`}
                            {invoice.print_count > 1 && ` · ${t('Reprint')}`}
                            {invoice.finalised_at && ` · ${dateTime(invoice.finalised_at)}`}
                        </p>
                    </div>
                    {draft && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            aria-pressed={simple}
                            className="print:hidden"
                            onClick={() => setSimple(!simple)}
                        >
                            {simple ? t('Show everything') : t('Simple screen')}
                        </Button>
                    )}
                    {!draft && (
                        <div className="flex flex-wrap gap-2 print:hidden">
                            <Button asChild variant="outline">
                                <a href={`/memo/${invoice.id}`} target="_blank" rel="noreferrer">
                                    <Printer className="size-4" />
                                    {t('Print memo')}
                                </a>
                            </Button>
                            {invoice.status === 'final' && (
                                <>
                                    <Button asChild variant="outline">
                                        <a href={`/memo/${invoice.id}`} target="_blank" rel="noreferrer" title={t('In the print window, choose Save as PDF.')}>
                                            <FileDown className="size-4" />
                                            {t('Save as PDF')}
                                        </a>
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => void shareMemo(invoice.id, 'whatsapp')}>
                                        <MessageCircle className="size-4" />
                                        {t('Send by WhatsApp')}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => void shareMemo(invoice.id, 'email')}>
                                        <Mail className="size-4" />
                                        {t('Send by email')}
                                    </Button>
                                </>
                            )}
                            {invoice.can_correct && invoice.status === 'final' && (
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        confirm(
                                            t(
                                                'Correct this memo? The old one is cancelled and kept in history. A new draft opens with the same items.',
                                            ),
                                        ) && router.post(`/admin/counter/${invoice.id}/correct`)
                                    }
                                >
                                    {t('Correct this memo')}
                                </Button>
                            )}
                        </div>
                    )}
                </header>

                {invoice.corrects && (
                    <p className="rounded-md border border-warning/50 bg-warning/10 p-2 text-sm">
                        {t('This memo corrects {n}.', { n: invoice.corrects })}
                    </p>
                )}
                {invoice.note && <p className="bg-muted rounded-md p-2 text-sm">{invoice.note}</p>}

                {draft && <Search invoice={invoice} popular={popular} simple={simple} />}

                <section aria-labelledby="lines-h">
                    <h2 id="lines-h" className="sr-only">
                        {t('Items')}
                    </h2>
                    {invoice.lines_detail.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('Nothing added yet.')}</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="py-1 font-normal">{t('Medicine')}</th>
                                    <th className="py-1 font-normal">{t('Quantity')}</th>
                                    <th className="hidden py-1 text-right font-normal sm:table-cell">{t('Rate')}</th>
                                    <th className="py-1 text-right font-normal">{t('Amount')}</th>
                                    {draft && <th />}
                                </tr>
                            </thead>
                            <tbody>
                                {invoice.lines_detail.map((l) => (
                                    <LineRow key={l.id} invoice={invoice} line={l} others={otherDrafts} />
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>

                <section className="ml-auto w-full max-w-sm space-y-2 text-sm">
                    <div className="flex justify-between">
                        <span>{t('Subtotal')}</span>
                        <span className="tabular">{money(invoice.totals.subtotal)}</span>
                    </div>
                    {draft && !simple ? (
                        <div className="flex items-end justify-between gap-2 print:hidden">
                            <label htmlFor="pct" className="pb-2">
                                {t(retail ? 'End discount %' : 'Invoice %')}
                                {retail && <span className="text-muted-foreground block text-xs">{t('Staff limit {n}%', { n: num(limit) })}</span>}
                            </label>
                            <div className="flex gap-2">
                                <Input
                                    id="pct"
                                    className="w-24"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="any"
                                    value={pct}
                                    onChange={(e) => setPct(e.target.value)}
                                />
                                {over ? (
                                    <Button
                                        disabled={waiting}
                                        onClick={() =>
                                            router.post('/admin/approvals', { invoice_id: invoice.id, kind, value: pct }, { preserveScroll: true })
                                        }
                                    >
                                        {waiting ? t('Waiting for the admin…') : t('Need admin approval')}
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.put(
                                                `/admin/counter/${invoice.id}/discount`,
                                                { percent: pct === '' ? null : pct },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('Apply')}
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : null}
                    {invoice.token_applied > 0 && (
                        <div className="flex justify-between">
                            <span>{t('Aqua Token')}</span>
                            <span className="tabular">−{money(invoice.token_applied)}</span>
                        </div>
                    )}
                    {invoice.credit_applied > 0 && (
                        <div className="flex justify-between">
                            <span>{t('Return product')}</span>
                            <span className="tabular">−{money(invoice.credit_applied)}</span>
                        </div>
                    )}
                    {asked && asked.status !== 'pending' && !isAdmin && (
                        <p className={`text-sm font-semibold ${asked.status === 'approved' ? 'text-success' : 'text-warning'}`}>
                            {asked.status === 'approved'
                                ? t('The admin said yes to {p}%.', { p: num(asked.value) })
                                : asked.status === 'rejected'
                                  ? t('The admin said no. Carry on at the normal rate.')
                                  : t('No answer in time. Carry on at the normal rate.')}
                        </p>
                    )}
                    {invoice.totals.discount > 0 && (
                        <div className="flex justify-between">
                            <span>{t('Discount')}</span>
                            <span className="tabular">−{money(invoice.totals.discount)}</span>
                        </div>
                    )}
                    {invoice.below_cost > 0 && (
                        <p role="alert" className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border p-2 text-sm font-medium">
                            {t('This sale is below cost by {amount}. It will be counted as a loss.', { amount: money(invoice.below_cost) })}
                        </p>
                    )}
                    <div className="font-display flex justify-between border-t pt-2 text-xl font-extrabold">
                        <span>{t('Total')}</span>
                        <span className="tabular">{money(owed)}</span>
                    </div>
                </section>

                {closing && (
                    <div className="flex flex-wrap items-end gap-2 print:hidden">
                        <div>
                            <label htmlFor="pay" className="text-muted-foreground text-xs">
                                {t('Payment')}
                            </label>
                            <select
                                id="pay"
                                value={method}
                                onChange={(e) => setMethod(e.target.value)}
                                className="bg-background block h-9 rounded-md border px-2 text-sm"
                            >
                                {['cash', 'bkash', 'nagad', 'rocket', 'bank', 'card', 'due'].map((m) => (
                                    <option key={m} value={m}>
                                        {t(
                                            m === 'bkash'
                                                ? 'bKash'
                                                : m === 'nagad'
                                                  ? 'Nagad'
                                                  : m === 'rocket'
                                                    ? 'Rocket'
                                                    : m === 'bank'
                                                      ? 'Bank'
                                                      : m === 'card'
                                                        ? 'Card'
                                                        : m === 'due'
                                                          ? 'On credit (due)'
                                                          : 'Cash',
                                        )}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {method !== 'due' && (
                            <div className="w-28">
                                <label htmlFor="paid" className="text-muted-foreground text-xs">
                                    {t('Paid now')}
                                </label>
                                <Input
                                    id="paid"
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder={String(invoice.totals.total)}
                                    value={paid}
                                    onChange={(e) => setPaid(e.target.value)}
                                />
                            </div>
                        )}
                    </div>
                )}

                {invoice.status === 'final' && (
                    <p className="text-muted-foreground border-t pt-3 text-xs">
                        {t(
                            'Medicine returns apply within {n} days of buying, with this memo. Cash back is {p}% because we offer exchange. Offer items return at the same rule on the offer price.',
                            { n: policy.days, p: policy.cash_percent },
                        )}
                    </p>
                )}
                {!closing && invoice.status !== 'cancelled' && (
                    <p className="text-sm">
                        <span
                            className={
                                invoice.paid_amount >= owed ? 'font-semibold text-success' : 'font-semibold text-warning'
                            }
                        >
                            {invoice.paid_amount >= owed
                                ? t('Paid')
                                : t('Due {amount}', { amount: money(owed - invoice.paid_amount) })}
                        </span>
                    </p>
                )}

                <ControlledDetails items={controlled} defaults={controlledDefaults} final={invoice.status === 'final'} />

                {closing && (
                    <div className="flex flex-wrap items-center gap-2 print:hidden">
                        <Button
                            disabled={invoice.lines_detail.length === 0}
                            onClick={() =>
                                router.post(`/admin/counter/${invoice.id}/finalise`, {
                                    payment_method: method,
                                    paid_amount: method === 'due' || paid === '' ? null : paid,
                                })
                            }
                        >
                            {t('Finish sale')}
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                confirm(t('Cancel this draft? Everything on it goes back to stock.')) &&
                                router.post(`/admin/counter/${invoice.id}/cancel`)
                            }
                        >
                            {t('Cancel draft')}
                        </Button>
                        {draft && !simple && otherDrafts.length > 0 && (
                            <select
                                aria-label={t('Merge another draft into this one')}
                                className="bg-background h-9 rounded-md border px-2 text-sm"
                                value=""
                                onChange={(e) => e.target.value && router.post(`/admin/counter/${invoice.id}/merge`, { source_id: e.target.value })}
                            >
                                <option value="">{t('Merge another draft…')}</option>
                                {otherDrafts.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
