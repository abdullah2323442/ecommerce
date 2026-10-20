import Flash from '@/components/admin/flash';
import LotCost from '@/components/admin/lot-cost';
import InputError from '@/components/input-error';
import { type MedicineSummary } from '@/components/medicines/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

interface Lot {
    id: number;
    source: string | null;
    invoice_number: string | null;
    date: string | null;
    units_received: number;
    units_bonus: number;
    units_remaining: number;
    unit_cost: number;
    purchase_percent: number;
    shelf: string | null;
    expiry_date: string | null;
    small_old: boolean;
}

interface Movement {
    type: string;
    units: string;
    unit_cost: string | null;
    unit_price: string | null;
    is_loss: boolean;
    reason: string | null;
    created_at: string;
    by: string | null;
}

interface Props {
    medicine: MedicineSummary;
    unitMrp: number;
    onHand: number;
    lots: Lot[];
    movements: Movement[];
    reasons: string[];
    reorderLevel: string | null;
    lotsLeft: number;
    manyLots: number;
    mrpMark: { at: string; was: number } | null;
}

export default function StockShow({ medicine, unitMrp, onHand, lots, movements, reasons, reorderLevel, lotsLeft, manyLots, mrpMark }: Props) {
    const { t, num, decimal, money, date } = useI18n();
    const correct = useForm({ on_hand: String(onHand), reason: reasons[0] ?? 'entry_mistake', note: '' });
    const productRate = useForm({ percent: '' });
    const companyRate = useForm({ percent: '' });
    const level = useForm({ level: reorderLevel ?? '' });
    const errors = correct.errors as Record<string, string>;
    const unit = medicine.packaging_type === 'box' ? t('boxes') : t('pieces');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Stock', href: '/admin/stock' },
        { title: medicine.brand_name, href: `/admin/stock/medicines/${medicine.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${medicine.brand_name} ${medicine.strength ?? ''}`} />

            <div className="mx-auto w-full max-w-5xl space-y-8 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">
                        {medicine.brand_name} <strong>{medicine.strength}</strong>
                    </h1>
                    <p className="text-sm text-muted-foreground">{[medicine.company?.name, medicine.form && t(medicine.form)].filter(Boolean).join(' · ')}</p>
                    <Link href={`/admin/stock/medicines/${medicine.id}/edit`} className="text-sm underline">
                        {t('Change pack size and printed prices')}
                    </Link>
                </div>

                <Flash />

                {mrpMark && (
                    <p className="rounded-md bg-warning/15 px-3 py-2 text-sm text-warning">
                        {t('The printed price changed on {d}. It was {p}. Old boxes may still carry the old price.', { d: date(mrpMark.at), p: money(mrpMark.was) })}
                    </p>
                )}
                {lotsLeft >= manyLots && (
                    <p className="rounded-md bg-warning/15 px-3 py-2 text-sm text-warning">{t('This medicine is spread over {n} lots. Sell the old small ones first.', { n: num(lotsLeft) })}</p>
                )}

                <dl className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-xl border bg-card p-4">
                        <dt className="text-sm text-muted-foreground">{t('On hand')}</dt>
                        <dd className="tabular mt-1 font-display text-3xl font-extrabold">
                            {num(onHand)} <span className="text-base font-normal text-muted-foreground">{unit}</span>
                        </dd>
                    </div>
                    <div className="rounded-xl border bg-card p-4">
                        <dt className="text-sm text-muted-foreground">{t('MRP per unit (now)')}</dt>
                        <dd className="tabular mt-1 font-display text-3xl font-extrabold">{money(unitMrp)}</dd>
                    </div>
                    <div className="rounded-xl border bg-card p-4">
                        <dt className="text-sm text-muted-foreground">{t('Lots with stock')}</dt>
                        <dd className="tabular mt-1 font-display text-3xl font-extrabold">{num(lots.filter((l) => l.units_remaining > 0).length)}</dd>
                    </div>
                </dl>

                <section aria-labelledby="lots-h" className="space-y-3">
                    <h2 id="lots-h" className="font-display text-xl font-bold">
                        {t('Lots')}
                    </h2>
                    <div className="overflow-x-auto rounded-md border bg-card">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">{t('Bought')}</th>
                                    <th className="px-4 py-2.5 font-medium">{t('Source')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('Left')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('Cost per unit')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('% below MRP')}</th>
                                    <th className="px-4 py-2.5 font-medium">{t('Shelf')}</th>
                                    <th className="px-4 py-2.5 font-medium" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {lots.map((l) => (
                                    <tr key={l.id} className={l.units_remaining > 0 ? '' : 'text-muted-foreground'}>
                                        <td className="px-4 py-2.5">
                                            {l.date ? date(l.date) : '—'}
                                            {l.invoice_number && <span className="block text-xs text-muted-foreground">{l.invoice_number}</span>}
                                        </td>
                                        <td className="px-4 py-2.5">{l.source}</td>
                                        <td className="tabular px-4 py-2.5 text-right">
                                            {num(l.units_remaining)} / {num(l.units_received)}
                                            {l.small_old && <span className="block text-xs text-warning">{t('Small and old')}</span>}
                                            {l.units_bonus > 0 && <span className="block text-xs text-muted-foreground">{t('bonus {n}', { n: num(l.units_bonus) })}</span>}
                                        </td>
                                        <td className="tabular px-4 py-2.5 text-right">{money(l.unit_cost)}</td>
                                        <td className="tabular px-4 py-2.5 text-right">{decimal(l.purchase_percent, 2)}%</td>
                                        <td className="px-4 py-2.5">{l.shelf ?? '—'}</td>
                                        <td className="px-4 py-2.5">
                                            <LotCost lotId={l.id} percent={l.purchase_percent} />
                                        </td>
                                    </tr>
                                ))}
                                {lots.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                                            {t('Nothing has been bought yet.')}
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section aria-labelledby="fix-h" className="space-y-3 rounded-xl border bg-card p-4">
                        <h2 id="fix-h" className="font-display text-xl font-bold">
                            {t('Correct the stock number')}
                        </h2>
                        <p className="text-sm text-muted-foreground">{t('Only the admin can do this. It fixes an entry error and is not a sale. The reason is kept.')}</p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                correct.post(`/admin/stock/medicines/${medicine.id}/correct`, { preserveScroll: true });
                            }}
                            className="space-y-3"
                        >
                            <div>
                                <label htmlFor="on-hand" className="text-sm font-medium">
                                    {t('Number on the shelf')}
                                </label>
                                <Input id="on-hand" type="number" min="0" step="0.01" value={correct.data.on_hand} onChange={(e) => correct.setData('on_hand', e.target.value)} className="mt-1" />
                                <InputError message={errors.on_hand} />
                            </div>
                            <div>
                                <label htmlFor="reason" className="text-sm font-medium">
                                    {t('Reason')}
                                </label>
                                <select id="reason" value={correct.data.reason} onChange={(e) => correct.setData('reason', e.target.value)} className="mt-1 h-10 w-full rounded-md border bg-background px-3 text-sm">
                                    {reasons.map((r) => (
                                        <option key={r} value={r}>
                                            {t(r)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.reason} />
                            </div>
                            <div>
                                <label htmlFor="cnote" className="text-sm font-medium">
                                    {t('Note')}
                                </label>
                                <Input id="cnote" value={correct.data.note} maxLength={300} onChange={(e) => correct.setData('note', e.target.value)} className="mt-1" />
                            </div>
                            <Button type="submit" loading={correct.processing}>
                                {t('Save correction')}
                            </Button>
                        </form>
                    </section>

                    <section aria-labelledby="rate-h" className="space-y-3 rounded-xl border bg-card p-4">
                        <h2 id="rate-h" className="font-display text-xl font-bold">
                            {t('Purchase rates')}
                        </h2>
                        <p className="text-sm text-muted-foreground">{t('Used for new purchases only. Stock already bought keeps its locked cost.')}</p>
                        {[
                            { id: 'prod', label: t('For this medicine'), form: productRate, url: `/admin/stock/rates/medicine/${medicine.id}` },
                            { id: 'comp', label: t('For every medicine of {company}', { company: medicine.company?.name ?? '' }), form: companyRate, url: `/admin/stock/rates/company/${medicine.company?.id}` },
                        ].map(({ id, label, form, url }) => (
                            <form
                                key={id}
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.put(url, { preserveScroll: true, onSuccess: () => form.reset() });
                                }}
                                className="flex flex-wrap items-end gap-2"
                            >
                                <div className="min-w-40 flex-1">
                                    <label htmlFor={`rate-${id}`} className="text-sm font-medium">
                                        {label}
                                    </label>
                                    <Input id={`rate-${id}`} type="number" min="0" max="99.999" step="0.001" placeholder={t('% below MRP, empty removes it')} value={form.data.percent} onChange={(e) => form.setData('percent', e.target.value)} className="mt-1" />
                                    <InputError message={(form.errors as Record<string, string>).percent} />
                                </div>
                                <Button type="submit" variant="secondary" loading={form.processing}>
                                    {t('Save')}
                                </Button>
                            </form>
                        ))}
                    </section>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        level.put(`/admin/stock/medicines/${medicine.id}/reorder-level`, { preserveScroll: true });
                    }}
                    className="flex flex-wrap items-end gap-2 rounded-xl border bg-card p-4"
                >
                    <div className="min-w-48 flex-1">
                        <label htmlFor="level" className="text-sm font-medium">
                            {t('Alert me when stock is at or under')}
                        </label>
                        <Input id="level" type="number" min="0" step="0.01" value={level.data.level} onChange={(e) => level.setData('level', e.target.value)} placeholder={t('Empty turns the alert off')} className="mt-1" />
                        <InputError message={(level.errors as Record<string, string>).level} />
                    </div>
                    <Button type="submit" variant="secondary" loading={level.processing}>
                        {t('Save')}
                    </Button>
                </form>

                <section aria-labelledby="mv-h" className="space-y-3">
                    <h2 id="mv-h" className="font-display text-xl font-bold">
                        {t('Recent movements')}
                    </h2>
                    <ul className="divide-y rounded-md border bg-card text-sm">
                        {movements.map((m, i) => (
                            <li key={i} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                                <span>
                                    <strong>{t(m.type)}</strong> {m.reason && <span className="text-muted-foreground">({t(m.reason)})</span>} {m.is_loss && <span className="font-semibold text-destructive">{t('Loss')}</span>}
                                    <span className="block text-xs text-muted-foreground">{[date(m.created_at), m.by].filter(Boolean).join(' · ')}</span>
                                </span>
                                <span className="tabular font-medium">{Number(m.units) > 0 ? '+' : ''}{num(Number(m.units))}</span>
                            </li>
                        ))}
                        {movements.length === 0 && <li className="px-4 py-6 text-center text-muted-foreground">{t('No movements yet.')}</li>}
                    </ul>
                </section>
            </div>
        </AppLayout>
    );
}
