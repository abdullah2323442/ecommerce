import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import QuantityStepsEditor, { type Step } from '@/components/admin/quantity-steps-editor';
import { type MedicineSummary } from '@/components/medicines/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';

interface Props {
    medicine: MedicineSummary;
    fields: { strips_per_box: number | null; pieces_per_strip: number | null; mrp_box: string | null; mrp_strip: string | null; mrp_piece: string | null };
    units: string[];
    steps: Step[];
    active: boolean;
    barcodes: { id: number; code: string }[];
    history: { action: string; changes: Record<string, unknown> | null; at: string; by: string | null }[];
}

/** Every barcode this medicine's packs carry: one medicine can have several, and each can be taken off again (spec ch 06). */
function Barcodes({ medicineId, barcodes }: { medicineId: number; barcodes: { id: number; code: string }[] }) {
    const { t } = useI18n();
    const form = useForm({ code: '' });

    return (
        <section aria-labelledby="barcodes-h" className="space-y-2 rounded-xl border bg-card p-4">
            <h2 id="barcodes-h" className="font-display text-xl font-bold">
                {t('Barcodes')}
            </h2>
            <p className="text-sm text-muted-foreground">{t('A scan of any of these finds this medicine. A new code can also be linked at the counter the first time it is scanned.')}</p>
            {barcodes.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('No barcode linked yet.')}</p>
            ) : (
                <ul className="divide-y rounded-md border text-sm">
                    {barcodes.map((b) => (
                        <li key={b.id} className="flex items-center justify-between gap-3 px-3 py-2">
                            <span className="tabular font-mono">{b.code}</span>
                            <Button type="button" variant="ghost" size="sm" onClick={() => router.delete(`/admin/stock/medicines/${medicineId}/barcodes/${b.id}`, { preserveScroll: true })}>
                                {t('Remove')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/admin/stock/medicines/${medicineId}/barcodes`, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="flex flex-wrap items-start gap-2"
            >
                <div className="grid gap-1">
                    <label htmlFor="new-barcode" className="sr-only">
                        {t('New barcode')}
                    </label>
                    <Input id="new-barcode" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder={t('Type or scan a code')} autoComplete="off" maxLength={64} className="w-64" />
                    <InputError message={form.errors.code} />
                </div>
                <Button type="submit" variant="outline" disabled={form.processing || form.data.code.trim() === ''}>
                    {t('Add')}
                </Button>
            </form>
        </section>
    );
}

/** Pack size and printed prices. A pack-size change asks twice; a new MRP leaves a mark for a month (spec 6.1, 6.8). */
export default function MedicineEdit({ medicine, fields, history, units, steps, active, barcodes }: Props) {
    const { t, dateTime } = useI18n();
    const form = useForm({
        strips_per_box: fields.strips_per_box === null ? '' : String(fields.strips_per_box),
        pieces_per_strip: fields.pieces_per_strip === null ? '' : String(fields.pieces_per_strip),
        mrp_box: fields.mrp_box ?? '',
        mrp_strip: fields.mrp_strip ?? '',
        mrp_piece: fields.mrp_piece ?? '',
        confirm_units: false as boolean,
        confirm_price: false as boolean,
    });
    const errors = form.errors as Record<string, string>;
    const asksToConfirm = !!errors.strips_per_box;

    const crumbs: BreadcrumbItem[] = [
        { title: 'Stock', href: '/admin/stock' },
        { title: medicine.brand_name, href: `/admin/stock/medicines/${medicine.id}` },
        { title: 'Pack size and prices', href: `/admin/stock/medicines/${medicine.id}/edit` },
    ];

    const field = (key: 'strips_per_box' | 'pieces_per_strip' | 'mrp_box' | 'mrp_strip' | 'mrp_piece', label: string, step: string) => (
        <div>
            <label htmlFor={key} className="text-sm font-medium">
                {label}
            </label>
            <Input id={key} type="number" min="0" step={step} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} className="mt-1" />
            {key !== 'strips_per_box' && <InputError message={errors[key]} />}
        </div>
    );

    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title={t('Pack size and prices')} />
            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">
                        {medicine.brand_name} <strong>{medicine.strength}</strong>
                    </h1>
                    <p className="text-sm text-muted-foreground">{t('Pack size and printed prices')}</p>
                </div>
                <Flash />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/admin/stock/medicines/${medicine.id}`, { preserveScroll: true, onSuccess: () => (form.setData('confirm_units', false), form.setData('confirm_price', false)) });
                    }}
                    className="space-y-4 rounded-xl border bg-card p-4"
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('strips_per_box', t('Strips in a box'), '1')}
                        {field('pieces_per_strip', t('Pieces in a strip'), '1')}
                        {field('mrp_box', t('Printed price of a box'), '0.01')}
                        {field('mrp_strip', t('Printed price of a strip'), '0.01')}
                        {field('mrp_piece', t('Printed price of a piece'), '0.01')}
                    </div>

                    {asksToConfirm && (
                        <div role="alert" className="space-y-2 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">
                            <p className="font-medium">{errors.strips_per_box}</p>
                            <p>{t('Stock already counted in the old pack size, and old memos, will not be changed. Everything from now on uses the new size.')}</p>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={form.data.confirm_units} onChange={(e) => form.setData('confirm_units', e.target.checked)} />
                                {t('I understand. Change it.')}
                            </label>
                        </div>
                    )}

                    {errors.price_warning && (
                        <div role="alert" className="space-y-2 rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">
                            <p className="font-medium">{errors.price_warning}</p>
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={form.data.confirm_price} onChange={(e) => form.setData('confirm_price', e.target.checked)} />
                                {t('Yes, the price is right. Change it.')}
                            </label>
                        </div>
                    )}

                    <Button type="submit" loading={form.processing}>
                        {t('Save')}
                    </Button>
                </form>

                <QuantityStepsEditor medicineId={medicine.id} units={units} steps={steps} />

                <Barcodes medicineId={medicine.id} barcodes={barcodes} />

                <section aria-labelledby="active-h" className="space-y-2 rounded-xl border bg-card p-4">
                    <h2 id="active-h" className="font-display text-xl font-bold">
                        {active ? t('Switch this medicine off') : t('This medicine is switched off')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {active
                            ? t('For a medicine that is discontinued or renamed. It is not offered for new orders. Old memos, reports and its history keep it.')
                            : t('It is not offered for new orders now. Switch it on to sell it again.')}
                    </p>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            variant={active ? 'destructive' : 'default'}
                            onClick={() => {
                                if (active && !window.confirm(t('Switch off {name}? It will not be offered for new orders.', { name: medicine.brand_name }))) return;
                                router.put(`/admin/stock/medicines/${medicine.id}/active`, { active: !active }, { preserveScroll: true });
                            }}
                        >
                            {active ? t('Switch off') : t('Switch on')}
                        </Button>
                        <Link href="/admin/stock/inactive" className="text-sm underline underline-offset-4">
                            {t('See every switched-off medicine')}
                        </Link>
                    </div>
                </section>

                {history.length > 0 && (
                    <section aria-labelledby="hist-h" className="space-y-2">
                        <h2 id="hist-h" className="font-display text-xl font-bold">
                            {t('Who changed it')}
                        </h2>
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {history.map((h, i) => (
                                <li key={i} className="flex flex-wrap justify-between gap-2 px-4 py-2">
                                    <span>{h.action === 'medicine.units_changed' ? t('Pack size changed') : t('Printed price changed')}</span>
                                    <span className="text-muted-foreground">
                                        {h.by} · {dateTime(h.at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
