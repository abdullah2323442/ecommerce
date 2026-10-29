import CameraScan from '@/components/camera-scan';
import Flash from '@/components/admin/flash';
import MedicinePicker, { type PickedMedicine } from '@/components/admin/medicine-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ScanBarcode } from 'lucide-react';
import { useRef, useState } from 'react';

interface Line {
    line_id: number;
    medicine_id: number;
    brand_name: string;
    strength: string | null;
    company: string | null;
    system: number;
    sold_since: number;
    expected: number;
    counted: number | null;
    difference: number | null;
    difference_value: number | null;
}

interface Props {
    count: { id: number; scope: string; shelf: string | null; status: string; started_at: string; difference_value: number };
    lines: Line[];
    totalDifference: number;
}

export default function StockCountShow({ count, lines, totalDifference }: Props) {
    const { t, num, money } = useI18n();
    const pageErrors = usePage<{ errors: Record<string, string> }>().props.errors;
    const open = count.status === 'open' || count.status === 'paused';
    const scan = useForm({ code: '' });
    const manual = useForm({ medicine_id: '', counted: '' });
    const link = useForm({ code: '', medicine_id: '' });
    const [picked, setPicked] = useState<PickedMedicine | null>(null);
    const [unknown, setUnknown] = useState<string | null>(null);
    const scanBox = useRef<HTMLInputElement>(null);
    const errors = { scan: scan.errors as Record<string, string>, manual: manual.errors as Record<string, string>, link: link.errors as Record<string, string> };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Stock', href: '/admin/stock' },
        { title: 'Stock count', href: '/admin/stock/counts' },
        { title: `#${count.id}`, href: `/admin/stock/counts/${count.id}` },
    ];

    // A USB or Bluetooth scanner types the code and presses Enter, so the form submits by itself.
    const submitScan = (e: React.FormEvent) => {
        e.preventDefault();
        const code = scan.data.code;
        scan.post(`/admin/stock/counts/${count.id}/scan`, {
            preserveScroll: true,
            onSuccess: () => {
                scan.reset();
                setUnknown(null);
                scanBox.current?.focus();
            },
            onError: (errs) => {
                if (errs.code) setUnknown(code);
            },
        });
    };

    // A code read by the phone camera counts the same as one typed by a scanner.
    const scanFromCamera = (code: string) =>
        router.post(`/admin/stock/counts/${count.id}/scan`, { code }, {
            preserveScroll: true,
            onSuccess: () => {
                setUnknown(null);
                scanBox.current?.focus();
            },
            onError: (errs) => {
                if (errs.code) setUnknown(code);
            },
        });

    const approve = () => {
        if (window.confirm(t('Approve this count? Stock will be changed to the counted numbers.'))) router.post(`/admin/stock/counts/${count.id}/approve`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Count {n}', { n: num(count.id) })} />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Count {n}', { n: num(count.id) })}</h1>
                        <p className="text-sm text-muted-foreground">{t(count.status)}</p>
                    </div>
                    {open && (
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={() => router.post(`/admin/stock/counts/${count.id}/pause`, {}, { preserveScroll: true })}>
                                {count.status === 'paused' ? t('Resume') : t('Pause')}
                            </Button>
                            <Button variant="ghost" onClick={() => window.confirm(t('Cancel this count? Nothing will change.')) && router.post(`/admin/stock/counts/${count.id}/cancel`)}>
                                {t('Cancel count')}
                            </Button>
                            <Button onClick={approve}>{t('Approve and adjust')}</Button>
                        </div>
                    )}
                </div>

                <Flash />
                <InputError message={pageErrors.approve} />

                {open && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form onSubmit={submitScan} className="space-y-2 rounded-xl border bg-card p-4">
                            <label htmlFor="scan" className="flex items-center gap-2 text-sm font-medium">
                                <ScanBarcode aria-hidden className="size-4" />
                                {t('Scan a barcode')}
                            </label>
                            <Input id="scan" ref={scanBox} autoFocus autoComplete="off" value={scan.data.code} onChange={(e) => scan.setData('code', e.target.value)} placeholder={t('Point the scanner here')} />
                            <InputError message={errors.scan.code} />
                            <CameraScan onCode={scanFromCamera} />
                        </form>

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                manual.transform((d) => ({ medicine_id: picked?.id, counted: d.counted }));
                                manual.post(`/admin/stock/counts/${count.id}/record`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setPicked(null);
                                        manual.reset();
                                    },
                                });
                            }}
                            className="space-y-2 rounded-xl border bg-card p-4"
                        >
                            <p className="text-sm font-medium">{t('Or pick a medicine and type the count')}</p>
                            <MedicinePicker picked={picked} onPick={setPicked} />
                            <div className="flex items-start gap-2">
                                <Input type="number" min="0" step="0.01" aria-label={t('Counted')} value={manual.data.counted} onChange={(e) => manual.setData('counted', e.target.value)} className="w-32" />
                                <Button type="submit" variant="secondary" disabled={!picked} loading={manual.processing}>
                                    {t('Save count')}
                                </Button>
                            </div>
                            <InputError message={errors.manual.counted || errors.manual.medicine_id} />
                        </form>
                    </div>
                )}

                {unknown && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            link.transform(() => ({ code: unknown, medicine_id: picked?.id }));
                            link.post('/admin/stock/barcodes', {
                                preserveScroll: true,
                                onSuccess: () => {
                                    scan.setData('code', unknown);
                                    setUnknown(null);
                                },
                            });
                        }}
                        className="space-y-2 rounded-xl border border-warning/40 bg-warning/10 p-4"
                    >
                        <p className="font-medium">{t('Barcode {code} is new. Which medicine is it?', { code: unknown })}</p>
                        <p className="text-sm text-muted-foreground">{t('Pick the medicine above, then link it. It is remembered for good.')}</p>
                        <Button type="submit" disabled={!picked} loading={link.processing}>
                            {t('Link barcode')}
                        </Button>
                        <InputError message={errors.link.code} />
                    </form>
                )}

                <div className="overflow-x-auto rounded-md border bg-card">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">{t('Medicine')}</th>
                                <th className="px-4 py-2.5 text-right font-medium">{t('System')}</th>
                                <th className="px-4 py-2.5 text-right font-medium">{t('Sold since')}</th>
                                <th className="px-4 py-2.5 text-right font-medium">{t('Counted')}</th>
                                <th className="px-4 py-2.5 text-right font-medium">{t('Difference')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lines.map((l) => (
                                <tr key={l.line_id}>
                                    <td className="px-4 py-2.5">
                                        <span className="font-medium">
                                            {l.brand_name} <strong>{l.strength}</strong>
                                        </span>
                                        <span className="block text-xs text-muted-foreground">{l.company}</span>
                                    </td>
                                    <td className="tabular px-4 py-2.5 text-right">{num(l.system)}</td>
                                    <td className="tabular px-4 py-2.5 text-right text-muted-foreground">{l.sold_since > 0 ? num(l.sold_since) : '—'}</td>
                                    <td className="tabular px-4 py-2.5 text-right">{l.counted === null ? <span className="text-muted-foreground">{t('Not counted')}</span> : num(l.counted)}</td>
                                    <td className={`tabular px-4 py-2.5 text-right font-medium ${l.difference ? 'text-destructive' : ''}`}>
                                        {l.difference === null ? '—' : l.difference === 0 ? t('Matches') : `${l.difference > 0 ? '+' : ''}${num(l.difference)} (${money(l.difference_value ?? 0)})`}
                                    </td>
                                </tr>
                            ))}
                            {lines.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                        {t('Nothing in this scope has stock.')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                <p className="tabular text-right font-display text-xl font-bold">{t('Total difference {value}', { value: money(count.status === 'approved' ? count.difference_value : totalDifference) })}</p>
            </div>
        </AppLayout>
    );
}
