import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    rate: number;
    memos: number;
    sales: number;
    vat: number;
    returned: number;
    returned_vat: number;
    net_sales: number;
    net_vat: number;
    without_vat: number;
}

interface Props {
    from: string;
    to: string;
    kind: string | null;
    kinds: string[];
    enabled: boolean;
    rows: Row[];
    totals: Record<string, number>;
    memos: { number: string; date: string; kind: string; customer: string | null; sale: number; vat: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'VAT report', href: '/admin/reports/vat' }];

/** The sales of a period and the VAT inside them, by rate, for the accountant (spec ch 25). */
export default function Vat({ from, to, kind, kinds, enabled, rows, totals, memos }: Props) {
    const { t, money, num, date } = useI18n();
    const [f, setF] = useState({ from, to, kind: kind ?? '' });
    const query = () => ({ from: f.from, to: f.to, kind: f.kind || undefined });
    const label = (k: string) => t(k === 'retail' ? 'Retail' : k === 'paikari' ? 'Shops' : 'Trade');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('VAT report')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <div className="print:hidden">
                    <h1 className="text-2xl font-bold">{t('VAT report')}</h1>
                    <p className="text-sm text-muted-foreground">{t('The sales of a period and the VAT inside them, for the accountant. Only finished memos count; returns are taken back out.')}</p>
                </div>
                <h1 className="hidden text-xl font-bold print:block">
                    {t('VAT report')} · {date(from)} – {date(to)}
                </h1>

                {!enabled && <p role="status" className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm print:hidden">{t('VAT is switched off in Settings, so memos have recorded no VAT yet.')}</p>}

                <form
                    className="flex flex-wrap items-end gap-3 print:hidden"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/reports/vat', query(), { preserveState: true });
                    }}
                >
                    <label className="text-sm font-medium">
                        {t('From')}
                        <Input className="mt-1" type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                    </label>
                    <label className="text-sm font-medium">
                        {t('To')}
                        <Input className="mt-1" type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                    </label>
                    <label className="text-sm font-medium">
                        {t('Kind of sale')}
                        <select value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })} className="mt-1 block h-9 rounded-md border bg-background px-2 text-sm">
                            <option value="">{t('All')}</option>
                            {kinds.map((k) => (
                                <option key={k} value={k}>
                                    {label(k)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button type="submit">{t('Show')}</Button>
                    <a href={`/admin/reports/vat/export?${new URLSearchParams(query() as Record<string, string>).toString()}`} className="inline-flex h-9 items-center rounded-md border px-3 text-sm font-medium">
                        {t('Download for the accountant')}
                    </a>
                    <Button type="button" variant="outline" onClick={() => window.print()}>
                        {t('Print')}
                    </Button>
                </form>

                <section className="overflow-x-auto">
                    <table className="tabular w-full text-sm">
                        <thead>
                            <tr className="border-b text-start text-xs text-muted-foreground">
                                <th className="py-2 text-start">{t('VAT rate')}</th>
                                <th className="text-end">{t('Memos')}</th>
                                <th className="text-end">{t('Sales (VAT included)')}</th>
                                <th className="text-end">{t('VAT')}</th>
                                <th className="text-end">{t('Returned')}</th>
                                <th className="text-end">{t('Net sales')}</th>
                                <th className="text-end">{t('Net VAT')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="py-6 text-center text-muted-foreground">
                                        {t('No finished memo in this period.')}
                                    </td>
                                </tr>
                            )}
                            {rows.map((r) => (
                                <tr key={r.rate} className="border-b">
                                    <td className="py-2">{r.rate > 0 ? `${num(r.rate)}%` : t('No VAT')}</td>
                                    <td className="text-end">{num(r.memos)}</td>
                                    <td className="text-end">{money(r.sales)}</td>
                                    <td className="text-end">{money(r.vat)}</td>
                                    <td className="text-end">{money(r.returned)}</td>
                                    <td className="text-end">{money(r.net_sales)}</td>
                                    <td className="text-end font-semibold">{money(r.net_vat)}</td>
                                </tr>
                            ))}
                        </tbody>
                        {rows.length > 0 && (
                            <tfoot>
                                <tr className="font-bold">
                                    <td className="py-2">{t('Total')}</td>
                                    <td className="text-end">{num(totals.memos)}</td>
                                    <td className="text-end">{money(totals.sales)}</td>
                                    <td className="text-end">{money(totals.vat)}</td>
                                    <td className="text-end">{money(totals.returned)}</td>
                                    <td className="text-end">{money(totals.net_sales)}</td>
                                    <td className="text-end">{money(totals.net_vat)}</td>
                                </tr>
                            </tfoot>
                        )}
                    </table>
                    {rows.length > 0 && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {t('Net sales without VAT')}: {money(totals.without_vat)}
                        </p>
                    )}
                </section>

                {memos.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Every memo')}</h2>
                        <div className="overflow-x-auto">
                            <table className="tabular w-full text-sm">
                                <tbody>
                                    {memos.map((m) => (
                                        <tr key={m.number} className="border-b">
                                            <td className="py-1.5">{m.number}</td>
                                            <td>{date(m.date)}</td>
                                            <td>{label(m.kind)}</td>
                                            <td>{m.customer}</td>
                                            <td className="text-end">{money(m.sale)}</td>
                                            <td className="text-end">{money(m.vat)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
