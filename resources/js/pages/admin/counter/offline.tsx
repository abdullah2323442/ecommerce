import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useOfflineReady } from '@/hooks/use-offline-ready';
import { useI18n } from '@/lib/i18n';
import {
    maxDiscount,
    nextTempNumber,
    priceFor,
    sales as store,
    totals,
    unitMrp,
    unitsOf,
    type Copy,
    type CopyMedicine,
    type Sale,
    type SaleLine,
    type Unit,
} from '@/lib/offline-store';
import { refreshCopy, savedCopy, sendWaiting } from '@/lib/offline-sync';
import { Head } from '@inertiajs/react';
import { Printer, Trash2, Wifi, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const unitName = (u: Unit) => (u === 'box' ? 'Box' : u === 'strip' ? 'Strip' : 'Piece');
const methodName = (m: string) => (m === 'bkash' ? 'bKash' : m === 'nagad' ? 'Nagad' : m === 'rocket' ? 'Rocket' : m === 'bank' ? 'Bank' : m === 'card' ? 'Card' : m === 'due' ? 'On credit (due)' : 'Cash');

/** The temporary memo that is printed offline. The shop's own number replaces the temporary one when the sale is sent. */
function Receipt({ sale, copy }: { sale: Sale; copy: Copy | null }) {
    const { t, money, num, dateTime } = useI18n();
    const sum = totals(sale.lines, sale.discount);

    return (
        <div className="receipt hidden print:block print:text-[11px]">
            <p className="text-center font-bold">{copy?.shop.name}</p>
            {copy?.shop.address && <p className="text-center">{copy.shop.address}</p>}
            {copy?.shop.phone && <p className="text-center">{copy.shop.phone}</p>}
            <p className="mt-2 border-t border-dashed pt-1 font-semibold">
                {t('Temporary memo')} {sale.number ?? sale.ref}
            </p>
            <p>{dateTime(sale.at)}</p>
            <p className="text-[10px]">{t('Made without internet. The shop gives the real memo number once it is sent.')}</p>
            <table className="mt-2 w-full">
                <tbody>
                    {sale.lines.map((l, i) => (
                        <tr key={i} className="align-top">
                            <td className="pr-1">
                                {l.name}
                                <br />
                                <span className="opacity-70">
                                    {num(l.qty)} {t(unitName(l.unit))} × {money(l.price)}
                                </span>
                            </td>
                            <td className="text-right whitespace-nowrap">{money(l.price * l.qty)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {sum.off > 0 && (
                <p className="mt-1 flex justify-between border-t border-dashed pt-1">
                    <span>{t('Discount')}</span>
                    <span>−{money(sum.off)}</span>
                </p>
            )}
            <p className="mt-1 flex justify-between border-t border-dashed pt-1 text-sm font-bold">
                <span>{t('Total')}</span>
                <span>{money(sum.total)}</span>
            </p>
            <p>{t(methodName(sale.method))}</p>
        </div>
    );
}

export default function OfflineCounter() {
    const { t, money, num, dateTime } = useI18n();
    const { takenAt } = useOfflineReady();
    const [copy, setCopy] = useState<Copy | null>(null);
    const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine);
    const [list, setList] = useState<Sale[]>([]);
    const [q, setQ] = useState('');
    const [lines, setLines] = useState<SaleLine[]>([]);
    const [discount, setDiscount] = useState('');
    const [method, setMethod] = useState('cash');
    const [error, setError] = useState<string | null>(null);
    const [note, setNote] = useState<string | null>(null);
    const [printing, setPrinting] = useState<string | null>(null);
    const sending = useRef(false);
    // The words for the notice are read when it is shown, so sending does not depend on them: new helper functions each render must never restart it.
    const words = useRef({ t, num });
    words.current = { t, num };

    const reload = useCallback(async () => setList(await store.all()), []);

    useEffect(() => {
        savedCopy().then((c) => c && setCopy(c));
        reload();
    }, [takenAt, reload]);

    const byId = useMemo(() => new Map((copy?.medicines ?? []).map((m) => [m.id, m])), [copy]);

    // Send what is waiting, whenever the line is there.
    const send = useCallback(async () => {
        if (sending.current || !navigator.onLine) return;
        sending.current = true;
        try {
            const out = await sendWaiting();
            await reload();
            if (out.sent > 0 || out.refused > 0) {
                const { t, num } = words.current;
                setNote(t('{n} sales sent.', { n: num(out.sent) }) + (out.refused > 0 ? ' ' + t('{n} need a look.', { n: num(out.refused) }) : ''));
                const fresh = await refreshCopy();
                if (fresh) setCopy(fresh);
            }
        } finally {
            sending.current = false;
        }
    }, [reload]);

    useEffect(() => {
        const up = () => {
            setOnline(true);
            send();
        };
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);
        send();
        const timer = setInterval(send, 30000);

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
            clearInterval(timer);
        };
    }, [send]);

    // Print once the receipt is on the page.
    useEffect(() => {
        if (!printing) return;
        const timer = setTimeout(() => {
            window.print();
            setPrinting(null);
        }, 150);

        return () => clearTimeout(timer);
    }, [printing]);

    const found = useMemo(() => {
        const term = q.trim().toLowerCase();
        if (term.length < 2 || !copy) return [];

        return copy.medicines.filter((m) => m.n.toLowerCase().startsWith(term) || `${m.n} ${m.s ?? ''}`.toLowerCase().includes(term)).slice(0, 25);
    }, [q, copy]);

    const add = (m: CopyMedicine, unit: Unit, qty = 1) => {
        setError(null);
        setLines((all) => {
            const at = all.findIndex((l) => l.id === m.id && l.unit === unit);
            if (at >= 0) return all.map((l, i) => (i === at ? { ...l, qty: l.qty + qty } : l));

            return [...all, { id: m.id, name: `${m.n}${m.s ? ' ' + m.s : ''}`, unit, qty, price: 0 }];
        });
    };

    // A scanner types the code and presses Enter.
    const scan = (e: React.FormEvent) => {
        e.preventDefault();
        const id = copy?.codes[q.trim()];
        const m = id ? byId.get(id) : undefined;
        if (!m) return;
        if (m.ctl) return setError(t('This is a controlled medicine. Sell it on the normal counter, when there is internet.'));
        add(m, unitsOf(m).includes('strip') ? 'strip' : 'piece');
        setQ('');
    };

    // Prices follow the quantity, the same sums as the shop's own counter.
    const priced = lines.map((l) => {
        const m = byId.get(l.id);

        return { ...l, price: m ? priceFor(m, l.unit, l.qty) : l.price };
    });
    const allowed = maxDiscount(copy?.limit ?? null, lines.map((l) => byId.get(l.id)));
    const wanted = Math.max(0, Number(discount) || 0);
    const sum = totals(priced, Math.min(wanted, allowed));

    const finish = async () => {
        if (priced.length === 0) return;
        if (wanted > allowed + 1e-9) return setError(t('This discount is too high. Lower it.'));
        if (priced.some((l) => !(l.price > 0) || !(l.qty > 0))) return setError(t('Check the quantities.'));

        const ref = await nextTempNumber();
        const sale: Sale = { ref, at: new Date().toISOString(), lines: priced, discount: wanted, method, paid: null, total: sum.total, status: 'pending' };
        await store.put(sale);
        setLines([]);
        setDiscount('');
        setMethod('cash');
        setQ('');
        setError(null);
        setNote(null);
        await reload();
        setPrinting(ref);
        send();
    };

    const why = (s: Sale) =>
        s.code === 'controlled'
            ? t('{name} is a controlled medicine. Its buyer must be written down, so it is not sold offline. Enter this sale on the counter.', { name: s.name ?? '' })
            : s.code === 'unit'
              ? t('{name} cannot be sold in that unit.', { name: s.name ?? '' })
              : s.code === 'unknown'
                ? t('A medicine on this sale is not in the list any more.')
                : t('Not taken. Enter this sale on the counter.');

    const waiting = list.filter((s) => s.status === 'pending').length;
    const printingSale = list.find((s) => s.ref === printing) ?? null;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title={t('Counter without internet')} />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4 print:hidden">
                <header className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('Counter without internet')}</h1>
                        <p className="text-sm text-muted-foreground">
                            {takenAt || copy ? t('Prices copied {when}.', { when: dateTime((copy?.taken_at ?? takenAt)!) }) : t('Open this page once with internet, so the prices can be copied to this device.')}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 font-semibold ${online ? 'bg-success/15 text-success' : 'bg-warning/15 text-warning'}`}>
                            {online ? <Wifi aria-hidden className="size-4" /> : <WifiOff aria-hidden className="size-4" />}
                            {online ? t('Online') : t('Offline. Will send later.')}
                        </span>
                        <span className="rounded-full bg-muted px-3 py-1 font-semibold">{t('{n} waiting to be sent', { n: num(waiting) })}</span>
                        <a href="/admin/counter" className="underline underline-offset-4">
                            {t('Back to the counter')}
                        </a>
                    </div>
                </header>

                {note && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{note}</p>}
                {error && <p role="alert" className="rounded-md border border-danger/40 bg-danger/10 px-3 py-2 text-sm text-danger">{error}</p>}

                {!copy ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">{t('There is no copy of the prices on this device yet. Connect to the internet and open this page again.')}</p>
                ) : (
                    <>
                        <section aria-labelledby="off-find" className="space-y-2">
                            <h2 id="off-find" className="font-medium">
                                {t('Find a medicine')}
                            </h2>
                            <form onSubmit={scan}>
                                <label htmlFor="off-q" className="sr-only">
                                    {t('Medicine name or barcode')}
                                </label>
                                <Input id="off-q" autoFocus autoComplete="off" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Medicine name or barcode')} className="h-12 text-base" />
                            </form>
                            {found.length > 0 && (
                                <ul className="divide-y rounded-md border bg-card">
                                    {found.map((m) => (
                                        <li key={m.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                                            <div className="min-w-40 flex-1">
                                                <p className="font-medium">
                                                    {m.n} <span className="text-sm font-normal text-muted-foreground">{m.s}</span>
                                                </p>
                                                <p className="text-xs text-muted-foreground">{[m.c, m.f && t(m.f)].filter(Boolean).join(' · ')}</p>
                                            </div>
                                            {m.ctl ? (
                                                <p className="text-xs font-semibold text-warning">{t('Controlled: sell it on the normal counter')}</p>
                                            ) : (
                                                <div className="flex flex-wrap gap-2">
                                                    {unitsOf(m).map((u) => (
                                                        <Button key={u} type="button" variant="outline" className="h-auto flex-col py-1" onClick={() => { add(m, u); setQ(''); }}>
                                                            <span className="text-xs">{t(unitName(u))}</span>
                                                            <span className="tabular font-semibold">{money(unitMrp(m, u) ?? 0)}</span>
                                                        </Button>
                                                    ))}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section aria-labelledby="off-sale" className="space-y-3">
                            <h2 id="off-sale" className="font-medium">
                                {t('This sale')}
                            </h2>
                            {priced.length === 0 ? (
                                <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('Nothing added yet.')}</p>
                            ) : (
                                <>
                                    <table className="w-full text-sm">
                                        <tbody>
                                            {priced.map((l, i) => (
                                                <tr key={`${l.id}-${l.unit}`} className="border-t">
                                                    <td className="py-2 pr-2">
                                                        <p className="font-medium">{l.name}</p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {t(unitName(l.unit))} · {money(l.price)}
                                                        </p>
                                                    </td>
                                                    <td className="w-24 py-2">
                                                        <Input aria-label={t('Quantity')} type="number" min="1" step="1" value={lines[i].qty} onChange={(e) => setLines((all) => all.map((x, n) => (n === i ? { ...x, qty: Math.max(0, Number(e.target.value)) } : x)))} />
                                                    </td>
                                                    <td className="tabular py-2 text-right font-semibold">{money(l.price * l.qty)}</td>
                                                    <td className="w-10 py-2 text-right">
                                                        <Button type="button" variant="ghost" size="icon" aria-label={t('Remove')} onClick={() => setLines((all) => all.filter((_, n) => n !== i))}>
                                                            <Trash2 className="size-4" />
                                                        </Button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>

                                    <div className="flex flex-wrap items-end gap-3">
                                        <div className="w-32">
                                            <label htmlFor="off-disc" className="text-xs text-muted-foreground">
                                                {t('End discount %')}
                                            </label>
                                            <Input id="off-disc" type="number" min="0" step="any" value={discount} onChange={(e) => setDiscount(e.target.value)} />
                                        </div>
                                        <div>
                                            <label htmlFor="off-method" className="text-xs text-muted-foreground">
                                                {t('Payment')}
                                            </label>
                                            <select id="off-method" value={method} onChange={(e) => setMethod(e.target.value)} className="block h-10 rounded-md border bg-background px-2 text-sm">
                                                {copy.methods.map((m) => (
                                                    <option key={m} value={m}>
                                                        {t(methodName(m))}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="ml-auto text-right">
                                            {sum.off > 0 && <p className="tabular text-sm text-muted-foreground">−{money(sum.off)}</p>}
                                            <p className="tabular font-display text-2xl font-extrabold">{money(sum.total)}</p>
                                        </div>
                                    </div>
                                    <Button className="h-12 w-full text-base" onClick={finish}>
                                        <Printer className="size-4" />
                                        {t('Finish and print')}
                                    </Button>
                                </>
                            )}
                        </section>
                    </>
                )}

                {list.length > 0 && (
                    <section aria-labelledby="off-list" className="space-y-2">
                        <div className="flex items-center justify-between">
                            <h2 id="off-list" className="font-medium">
                                {t('Sales made without internet')}
                            </h2>
                            {list.some((s) => s.status === 'synced') && (
                                <Button variant="ghost" size="sm" onClick={async () => { for (const s of list.filter((x) => x.status === 'synced')) await store.remove(s.ref); reload(); }}>
                                    {t('Clear the sent ones')}
                                </Button>
                            )}
                        </div>
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {list.map((s) => (
                                <li key={s.ref} className="flex flex-wrap items-center gap-3 px-3 py-2">
                                    <div className="min-w-40 flex-1">
                                        <p className="font-medium">{s.number ? `${t('Memo')} ${s.number}` : s.ref}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {dateTime(s.at)} · {t('{n} items', { n: num(s.lines.length) })}
                                        </p>
                                        {s.status === 'refused' && <p className="text-xs font-semibold text-danger">{why(s)}</p>}
                                        {s.flags && (s.flags.out_of_stock.length > 0 || s.flags.loss.length > 0 || s.flags.discount_reduced) && <p className="text-xs text-warning">{t('Sent. The owner has been told to look at it.')}</p>}
                                    </div>
                                    <span className="tabular font-semibold">{money(s.total)}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${s.status === 'pending' ? 'bg-warning/15 text-warning' : s.status === 'synced' ? 'bg-success/15 text-success' : 'bg-danger/15 text-danger'}`}>
                                        {s.status === 'pending' ? t('Waiting') : s.status === 'synced' ? t('Sent') : t('Needs a look')}
                                    </span>
                                    <Button type="button" variant="ghost" size="icon" aria-label={t('Print')} onClick={() => setPrinting(s.ref)}>
                                        <Printer className="size-4" />
                                    </Button>
                                    {s.status === 'refused' && (
                                        <Button type="button" variant="ghost" size="sm" onClick={async () => { await store.remove(s.ref); reload(); }}>
                                            {t('Remove')}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            {printingSale && <Receipt sale={printingSale} copy={copy} />}
        </div>
    );
}
