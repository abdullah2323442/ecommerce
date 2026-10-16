import Flash from '@/components/admin/flash';
import UnresolvedList, { type Unresolved } from '@/components/admin/unresolved-list';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';

interface Props {
    day: string;
    methods: { method: string; count: number; total: number; paid: number }[];
    expectedCash: number;
    sales: number;
    due: number;
    closing: { counted_cash: string; difference: string; note: string | null } | null;
    unresolved: Unresolved[];
    isToday: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'End of day', href: '/admin/day' }];

export default function Day({ day, methods, expectedCash, sales, due, closing, unresolved, isToday }: Props) {
    const { t, money, num } = useI18n();
    const form = useForm({ day, counted_cash: closing?.counted_cash ?? '', note: closing?.note ?? '' });
    const diff = form.data.counted_cash === '' ? null : Number(form.data.counted_cash) - expectedCash;
    const label = (m: string) => t(m === 'bkash' ? 'bKash' : m === 'nagad' ? 'Nagad' : m === 'rocket' ? 'Rocket' : m === 'bank' ? 'Bank' : m === 'card' ? 'Card' : m === 'due' ? 'On credit (due)' : 'Cash');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('End of day')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="font-display text-2xl font-extrabold">{t('End of day')}</h1>
                    <label className="flex items-center gap-2 text-sm">
                        {t('Date')}
                        <Input type="date" className="w-40" value={day} max={new Date().toISOString().slice(0, 10)} onChange={(e) => router.get('/admin/day', { day: e.target.value }, { preserveState: false })} />
                    </label>
                </div>

                {isToday && <UnresolvedList items={unresolved} hide={['cash']} />}

                <section className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-md border p-4">
                        <p className="text-sm text-muted-foreground">{t('Sales')}</p>
                        <p className="tabular font-display text-2xl font-extrabold">{money(sales)}</p>
                    </div>
                    <div className="rounded-md border p-4">
                        <p className="text-sm text-muted-foreground">{t('Cash in the till should be')}</p>
                        <p className="tabular font-display text-2xl font-extrabold">{money(expectedCash)}</p>
                    </div>
                    <div className="rounded-md border p-4">
                        <p className="text-sm text-muted-foreground">{t('Still to collect')}</p>
                        <p className="tabular font-display text-2xl font-extrabold">{money(due)}</p>
                    </div>
                </section>

                {methods.length === 0 ? (
                    <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No finished sales on this day.')}</p>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1 font-normal">{t('Payment')}</th>
                                <th className="py-1 text-right font-normal">{t('Sales')}</th>
                                <th className="py-1 text-right font-normal">{t('Total')}</th>
                                <th className="py-1 text-right font-normal">{t('Paid now')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {methods.map((m) => (
                                <tr key={m.method} className="border-t">
                                    <td className="py-2">{label(m.method)}</td>
                                    <td className="tabular py-2 text-right">{num(m.count)}</td>
                                    <td className="tabular py-2 text-right">{money(m.total)}</td>
                                    <td className="tabular py-2 text-right">{money(m.paid)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/day', { preserveScroll: true });
                    }}
                    className="space-y-3 rounded-md border p-4"
                >
                    <h2 className="font-medium">{t('Cash counted')}</h2>
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="w-40">
                            <label htmlFor="counted" className="text-xs text-muted-foreground">
                                {t('Cash counted')}
                            </label>
                            <Input id="counted" type="number" min="0" step="any" value={form.data.counted_cash} onChange={(e) => form.setData('counted_cash', e.target.value)} />
                        </div>
                        <div className="min-w-48 flex-1">
                            <label htmlFor="note" className="text-xs text-muted-foreground">
                                {t('Note')}
                            </label>
                            <Input id="note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                        </div>
                        <Button type="submit" loading={form.processing}>
                            {t('Save day')}
                        </Button>
                    </div>
                    <InputError message={form.errors.counted_cash} />
                    {diff !== null && (
                        <p className={`tabular text-sm font-semibold ${diff === 0 ? 'text-emerald-700' : 'text-amber-700'}`}>
                            {diff === 0 ? t('The cash matches.') : diff > 0 ? t('{amount} more than expected', { amount: money(diff) }) : t('{amount} short', { amount: money(-diff) })}
                        </p>
                    )}
                </form>
            </div>
        </AppLayout>
    );
}
