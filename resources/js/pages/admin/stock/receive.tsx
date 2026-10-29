import CameraScan from '@/components/camera-scan';
import CompanyAdder from '@/components/admin/company-adder';
import Flash from '@/components/admin/flash';
import MedicinePicker, { type PickedMedicine } from '@/components/admin/medicine-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Plus, ScanBarcode, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Source {
    id: number;
    name: string;
    type: string;
    default_percent: string | null;
}

interface Line {
    key: number;
    medicine: PickedMedicine | null;
    units: string;
    bonus: string;
    company_percent: string;
    mpo_percent: string;
    shelf: string;
    expiry_date: string;
    mrpUnit: number | null;
    resolved: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock', href: '/admin/stock' },
    { title: 'Enter purchase', href: '/admin/stock/receive' },
];

/** The same arithmetic the server uses (spec STK-3, STK-4), for the live preview. The server's numbers are the ones saved. */
function preview(line: Line) {
    const first = line.company_percent !== '' ? Number(line.company_percent) : line.resolved;
    const units = Number(line.units);
    if (!line.mrpUnit || first === null || !(units > 0)) return null;

    const remaining = (1 - first / 100) * (line.mpo_percent !== '' ? 1 - Number(line.mpo_percent) / 100 : 1);
    const received = units + Number(line.bonus || 0);
    const totalPaid = units * line.mrpUnit * remaining;
    const unitCost = totalPaid / received;

    return { unitCost, percent: (1 - unitCost / line.mrpUnit) * 100, totalPaid };
}

let nextKey = 1;
const blank = (): Line => ({ key: nextKey++, medicine: null, units: '', bonus: '', company_percent: '', mpo_percent: '', shelf: '', expiry_date: '', mrpUnit: null, resolved: null });

interface Draft {
    payload: {
        source_id?: string;
        invoice_number?: string;
        invoice_date?: string;
        note?: string;
        lines?: { medicine: PickedMedicine; units: string; bonus: string; company_percent: string; mpo_percent: string; shelf: string; expiry_date: string; mrpUnit: number | null; resolved: number | null }[];
    };
    updated_at: string;
}

export default function StockReceive({ sources, today, draft }: { sources: Source[]; today: string; draft: Draft | null }) {
    const { t, num, decimal, money, dateTime } = useI18n();
    const saved = draft?.payload;
    const form = useForm({
        source_id: saved?.source_id ?? (sources[0]?.id ? String(sources[0].id) : ''),
        invoice_number: saved?.invoice_number ?? '',
        invoice_date: saved?.invoice_date ?? today,
        note: saved?.note ?? '',
        confirm_duplicate: false as boolean,
        paid_now: '',
        due_date: '',
    });
    // Paid in full is the usual way; the other is part or none now, and the rest owed to the company by a day (the credit ledger).
    const [payMode, setPayMode] = useState<'full' | 'credit'>('full');
    const [lines, setLines] = useState<Line[]>(() => (saved?.lines?.length ? saved.lines.map((l) => ({ ...blank(), ...l })) : [blank()]));
    const errors = form.errors as Record<string, string>;
    const [scanCode, setScanCode] = useState('');
    const [unknownCode, setUnknownCode] = useState<string | null>(null);
    const [linkPick, setLinkPick] = useState<PickedMedicine | null>(null);

    const patch = (key: number, change: Partial<Line>) => setLines((all) => all.map((l) => (l.key === key ? { ...l, ...change } : l)));

    const quote = async (key: number, medicine: PickedMedicine | null, sourceId = form.data.source_id) => {
        patch(key, { medicine, mrpUnit: null, resolved: null });
        if (!medicine || !sourceId) return;
        const res = await fetch(`/admin/stock/quote?source_id=${sourceId}&medicine_id=${medicine.id}`, { headers: { Accept: 'application/json' } });
        if (res.ok) {
            const q = (await res.json()) as { percent: number | null; mrp_unit: number };
            patch(key, { mrpUnit: q.mrp_unit, resolved: q.percent });
        }
    };

    /** A scanner types the code and presses Enter. A known code adds a line, or one more unit to the line already there. */
    const scan = async (e: { preventDefault: () => void }, fromCamera?: string) => {
        e.preventDefault();
        const code = (fromCamera ?? scanCode).trim();
        if (!code) return;
        const res = await fetch(`/admin/stock/barcodes/lookup?code=${encodeURIComponent(code)}`, { headers: { Accept: 'application/json' } });
        setScanCode('');

        if (!res.ok) {
            setUnknownCode(code);
            return;
        }

        setUnknownCode(null);
        const { medicine } = (await res.json()) as { medicine: PickedMedicine };
        const existing = lines.find((l) => l.medicine?.id === medicine.id);

        if (existing) {
            patch(existing.key, { units: String(Number(existing.units || 0) + 1) });
        } else {
            const line = { ...blank(), units: '1' };
            setLines((all) => [...all.filter((l) => l.medicine), line]);
            quote(line.key, medicine);
        }
    };

    /** The medicines ticked under one company become one line each; one already on the purchase is not added twice. */
    const addMany = (picked: PickedMedicine[]) => {
        const fresh = picked.filter((m) => !lines.some((l) => l.medicine?.id === m.id));
        const made = fresh.map((m) => ({ line: blank(), medicine: m }));
        setLines((all) => [...all.filter((l) => l.medicine), ...made.map((x) => x.line)]);
        made.forEach((x) => quote(x.line.key, x.medicine));
    };

    const linkUnknown = () => {
        if (!unknownCode || !linkPick) return;
        router.post('/admin/stock/barcodes', { code: unknownCode, medicine_id: linkPick.id }, { preserveScroll: true, onSuccess: () => { setUnknownCode(null); setLinkPick(null); } });
    };

    /** Keep what is typed so far, to finish later. Nothing is added to stock. */
    const saveDraft = () =>
        router.put(
            '/admin/stock/draft',
            {
                payload: {
                    source_id: form.data.source_id,
                    invoice_number: form.data.invoice_number,
                    invoice_date: form.data.invoice_date,
                    note: form.data.note,
                    lines: JSON.parse(JSON.stringify(lines.filter((l) => l.medicine).map(({ key: _key, ...rest }) => rest))),
                },
            },
            { preserveScroll: true },
        );

    const total = lines.reduce((sum, l) => sum + (preview(l)?.totalPaid ?? 0), 0);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            paid_now: payMode === 'credit' ? data.paid_now || '0' : null,
            due_date: payMode === 'credit' ? data.due_date || null : null,
            lines: lines
                .filter((l) => l.medicine)
                .map((l) => ({ medicine_id: l.medicine!.id, units: l.units, bonus: l.bonus || null, company_percent: l.company_percent || null, mpo_percent: l.mpo_percent || null, shelf: l.shelf || null, expiry_date: l.expiry_date || null })),
        }));
        form.post('/admin/stock/receive');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Enter purchase')} />

            <form onSubmit={submit} className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Enter purchase')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Each line becomes its own lot, with its cost locked in taka the moment you save.')}</p>
                </div>

                <Flash />

                {draft && (
                    <p className="flex flex-wrap items-center gap-3 rounded-md bg-muted px-3 py-2 text-sm">
                        {t('Your unfinished entry from {d} is back. Nothing has been added to stock yet.', { d: dateTime(draft.updated_at) })}
                        <Button type="button" size="sm" variant="ghost" onClick={() => router.delete('/admin/stock/draft', { onSuccess: () => router.visit('/admin/stock/receive') })}>
                            {t('Throw it away')}
                        </Button>
                    </p>
                )}

                {sources.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-muted-foreground">{t('Add a source first (a company, an MPO or a shop).')}</p>
                ) : (
                    <>
                        <div className="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-4">
                            <div>
                                <label htmlFor="source" className="text-sm font-medium">
                                    {t('Source')}
                                </label>
                                <select
                                    id="source"
                                    value={form.data.source_id}
                                    onChange={(e) => {
                                        form.setData('source_id', e.target.value);
                                        lines.forEach((l) => quote(l.key, l.medicine, e.target.value));
                                    }}
                                    className="mt-1 h-10 w-full rounded-md border bg-background px-3 text-sm"
                                >
                                    {sources.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                            {s.default_percent !== null ? ` (${num(Number(s.default_percent))}%)` : ''}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.source_id} />
                            </div>
                            <div>
                                <label htmlFor="invoice" className="text-sm font-medium">
                                    {t('Invoice number')}
                                </label>
                                <Input id="invoice" value={form.data.invoice_number} onChange={(e) => form.setData('invoice_number', e.target.value)} maxLength={60} className="mt-1" />
                                <InputError message={errors.invoice_number} />
                                {errors.invoice_number && (
                                    <label className="mt-2 flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={form.data.confirm_duplicate} onChange={(e) => form.setData('confirm_duplicate', e.target.checked)} />
                                        {t('I checked. Enter it anyway.')}
                                    </label>
                                )}
                            </div>
                            <div>
                                <label htmlFor="date" className="text-sm font-medium">
                                    {t('Invoice date')}
                                </label>
                                <Input id="date" type="date" max={today} value={form.data.invoice_date} onChange={(e) => form.setData('invoice_date', e.target.value)} className="mt-1" />
                                <InputError message={errors.invoice_date} />
                            </div>
                            <div>
                                <label htmlFor="note" className="text-sm font-medium">
                                    {t('Note')}
                                </label>
                                <Input id="note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={300} className="mt-1" />
                            </div>
                        </div>

                        <div className="rounded-xl border bg-card p-4">
                            {/* Not a <form>: this sits inside the purchase form, and a nested form made Enter save the whole purchase. */}
                            <div>
                                <label htmlFor="scan" className="flex items-center gap-2 text-sm font-medium">
                                    <ScanBarcode aria-hidden className="size-4" />
                                    {t('Scan a barcode')}
                                </label>
                                <Input
                                    id="scan"
                                    autoComplete="off"
                                    value={scanCode}
                                    onChange={(e) => setScanCode(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            void scan(e);
                                        }
                                    }}
                                    placeholder={t('Point the scanner here')}
                                    className="mt-1 max-w-sm"
                                />
                                <div className="mt-2">
                                    <CameraScan onCode={(code) => void scan({ preventDefault: () => {} }, code)} />
                                </div>
                            </div>
                            {unknownCode && (
                                <div role="alert" className="mt-3 space-y-2 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">
                                    <p className="font-medium">{t('Barcode {code} is new. Which medicine is it?', { code: unknownCode })}</p>
                                    <MedicinePicker picked={linkPick} onPick={setLinkPick} />
                                    <Button type="button" size="sm" disabled={!linkPick} onClick={linkUnknown}>
                                        {t('Link barcode')}
                                    </Button>
                                </div>
                            )}
                            <CompanyAdder onAdd={addMany} />
                        </div>

                        <InputError message={errors.lines} />

                        <ul className="space-y-3">
                            {lines.map((line, i) => {
                                const p = preview(line);

                                return (
                                    <li key={line.key} className="rounded-xl border bg-card p-4">
                                        <div className="grid gap-3 lg:grid-cols-12">
                                            <div className="lg:col-span-5">
                                                <p className="mb-1 text-sm font-medium">{t('Medicine')}</p>
                                                <MedicinePicker picked={line.medicine} onPick={(m) => quote(line.key, m)} />
                                                <InputError message={errors[`lines.${i}.medicine_id`]} />
                                            </div>
                                            <div className="lg:col-span-1">
                                                <label className="text-sm font-medium" htmlFor={`u-${line.key}`}>
                                                    {t('Units')}
                                                </label>
                                                <Input id={`u-${line.key}`} type="number" min="0" step="0.01" value={line.units} onChange={(e) => patch(line.key, { units: e.target.value })} className="mt-1" />
                                                <InputError message={errors[`lines.${i}.units`]} />
                                            </div>
                                            <div className="lg:col-span-1">
                                                <label className="text-sm font-medium" htmlFor={`b-${line.key}`}>
                                                    {t('Bonus')}
                                                </label>
                                                <Input id={`b-${line.key}`} type="number" min="0" step="0.01" value={line.bonus} onChange={(e) => patch(line.key, { bonus: e.target.value })} className="mt-1" />
                                            </div>
                                            <div className="lg:col-span-2">
                                                <label className="text-sm font-medium" htmlFor={`c-${line.key}`}>
                                                    {t('Company %')}
                                                </label>
                                                <Input
                                                    id={`c-${line.key}`}
                                                    type="number"
                                                    min="0"
                                                    max="99.999"
                                                    step="0.001"
                                                    value={line.company_percent}
                                                    placeholder={line.resolved !== null ? String(line.resolved) : ''}
                                                    onChange={(e) => patch(line.key, { company_percent: e.target.value })}
                                                    className="mt-1"
                                                />
                                                <InputError message={errors[`lines.${i}.company_percent`]} />
                                            </div>
                                            <div className="lg:col-span-2">
                                                <label className="text-sm font-medium" htmlFor={`m-${line.key}`}>
                                                    {t('MPO % on top')}
                                                </label>
                                                <Input id={`m-${line.key}`} type="number" min="0" max="99.999" step="0.001" value={line.mpo_percent} onChange={(e) => patch(line.key, { mpo_percent: e.target.value })} className="mt-1" />
                                            </div>
                                            <div className="flex items-end lg:col-span-1">
                                                {lines.length > 1 && (
                                                    <Button type="button" variant="ghost" size="icon" aria-label={t('Remove line')} onClick={() => setLines((all) => all.filter((l) => l.key !== line.key))}>
                                                        <Trash2 aria-hidden />
                                                    </Button>
                                                )}
                                            </div>
                                        </div>

                                        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
                                            <div className="lg:col-span-3">
                                                <label className="text-sm font-medium" htmlFor={`s-${line.key}`}>
                                                    {t('Shelf')}
                                                </label>
                                                <Input id={`s-${line.key}`} value={line.shelf} maxLength={40} onChange={(e) => patch(line.key, { shelf: e.target.value })} className="mt-1" />
                                            </div>
                                            <div className="lg:col-span-3">
                                                <label className="text-sm font-medium" htmlFor={`e-${line.key}`}>
                                                    {t('Expiry date (optional)')}
                                                </label>
                                                <Input id={`e-${line.key}`} type="date" value={line.expiry_date} onChange={(e) => patch(line.key, { expiry_date: e.target.value })} className="mt-1" />
                                                <InputError message={errors[`lines.${i}.expiry_date`]} />
                                            </div>
                                            <p aria-live="polite" className="tabular self-end rounded-md bg-muted/60 px-3 py-2 text-sm lg:col-span-6">
                                                {p
                                                    ? t('Cost per unit {cost}, bought at {percent}% below MRP, line total {total}.', { cost: money(p.unitCost), percent: decimal(p.percent, 2), total: money(p.totalPaid) })
                                                    : line.medicine && line.resolved === null
                                                      ? t('No purchase % yet. Type one here, or set it on the source, company or medicine.')
                                                      : t('Pick a medicine and a quantity to see the cost.')}
                                            </p>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>

                        <fieldset className="space-y-3 rounded-lg border p-4">
                            <legend className="px-1 text-sm font-medium">{t('How it is paid')}</legend>
                            <ToggleGroup type="single" variant="outline" value={payMode} onValueChange={(v) => v && setPayMode(v as 'full' | 'credit')} className="flex-wrap justify-start" aria-label={t('How it is paid')}>
                                <ToggleGroupItem value="full">{t('Paid in full')}</ToggleGroupItem>
                                <ToggleGroupItem value="credit">{t('Part or none now')}</ToggleGroupItem>
                            </ToggleGroup>
                            {payMode === 'credit' && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="text-sm font-medium">
                                        {t('Paid now')}
                                        <Input className="mt-1" type="number" inputMode="decimal" min="0" step="any" max={total} placeholder="0" value={form.data.paid_now} onChange={(e) => form.setData('paid_now', e.target.value)} aria-invalid={!!errors.paid_now} />
                                        <InputError message={errors.paid_now} />
                                    </label>
                                    <label className="text-sm font-medium">
                                        {t('The rest is due on')}
                                        <Input className="mt-1" type="date" min={today} value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} aria-invalid={!!errors.due_date} />
                                        <InputError message={errors.due_date} />
                                    </label>
                                    <p className="tabular text-sm text-muted-foreground sm:col-span-2">
                                        {t('We will owe the company {amount}. It is listed under Company dues.', { amount: money(Math.max(0, total - Number(form.data.paid_now || 0))) })}
                                    </p>
                                </div>
                            )}
                        </fieldset>

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Button type="button" variant="outline" onClick={() => setLines((all) => [...all, blank()])}>
                                <Plus aria-hidden />
                                {t('Add a line')}
                            </Button>
                            <div className="flex items-center gap-4">
                                <p className="tabular text-sm text-muted-foreground">{t('Invoice total {total}', { total: money(total) })}</p>
                                <Button type="button" variant="outline" onClick={saveDraft}>
                                    {t('Save as draft')}
                                </Button>
                                <Button type="submit" loading={form.processing}>
                                    {t('Save purchase')}
                                </Button>
                            </div>
                        </div>
                    </>
                )}
            </form>
        </AppLayout>
    );
}
