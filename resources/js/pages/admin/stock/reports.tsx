import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';

interface Expense {
    id: number;
    spent_on: string;
    category: string;
    amount: number;
    note: string | null;
    recurring: boolean;
    recurring_until: string | null;
}

interface Props {
    month: string;
    gross: { revenue: number; cost: number; profit: number; loss_sales_value: number; sales_lines: number };
    net: { gross: number; expenses: number; net: number; by_category: Record<string, number> };
    expenses: Expense[];
    trend: { month: string; gross: number; expenses: number; net: number }[];
    lowStock: { medicine_id: number; brand_name: string; strength: string | null; company: string | null; on_hand: number; reorder_level: number }[];
    finishedToday: { medicine_id: number; brand_name: string; strength: string | null; company: string | null }[];
    categories: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock', href: '/admin/stock' },
    { title: 'Reports', href: '/admin/stock/reports' },
];

export default function StockReports({ month, gross, net, expenses, trend, lowStock, finishedToday, categories }: Props) {
    const { t, num, money, date } = useI18n();
    const today = new Date().toISOString().slice(0, 10);
    const form = useForm({ spent_on: today, category: categories[0] ?? 'other', amount: '', note: '', recurring: false as boolean, recurring_until: '' });
    const errors = form.errors as Record<string, string>;
    const monthLabel = (ym: string) => date(`${ym}-01`, { month: 'short', year: 'numeric' });

    const cards = [
        { label: t('Gross profit'), value: net.gross },
        { label: t('Expenses'), value: -net.expenses },
        { label: t('Net profit'), value: net.net },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Reports')} />

            <div className="mx-auto w-full max-w-5xl space-y-8 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Reports')}</h1>
                        <p className="text-sm text-muted-foreground">{t('Profit uses the cost of the lot each sale came from. Only the admin sees this page.')}</p>
                    </div>
                    <div>
                        <label htmlFor="month" className="text-sm font-medium">
                            {t('Month')}
                        </label>
                        <Input id="month" type="month" value={month} onChange={(e) => router.get('/admin/stock/reports', { month: e.target.value }, { preserveState: true, preserveScroll: true })} className="mt-1" />
                    </div>
                </div>

                <Flash />

                <dl className="grid gap-3 sm:grid-cols-3">
                    {cards.map((c) => (
                        <div key={c.label} className="rounded-xl border bg-card p-4">
                            <dt className="text-sm text-muted-foreground">{c.label}</dt>
                            <dd className={`tabular mt-1 font-display text-3xl font-extrabold ${c.value < 0 ? 'text-destructive' : ''}`}>{money(c.value)}</dd>
                        </div>
                    ))}
                </dl>
                {gross.loss_sales_value > 0 && <p className="rounded-xl border border-destructive/40 bg-destructive/5 p-3 text-sm font-medium text-destructive">{t('Sales below cost this month cost {value}.', { value: money(gross.loss_sales_value) })}</p>}

                <section aria-labelledby="trend-h" className="space-y-3">
                    <h2 id="trend-h" className="font-display text-xl font-bold">
                        {t('Last 12 months')}
                    </h2>
                    <div className="overflow-x-auto rounded-md border bg-card">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50 text-left text-muted-foreground">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">{t('Month')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('Gross profit')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('Expenses')}</th>
                                    <th className="px-4 py-2.5 text-right font-medium">{t('Net profit')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {trend.map((r) => (
                                    <tr key={r.month} className={r.month === month ? 'bg-muted/40 font-medium' : ''}>
                                        <td className="px-4 py-2">{monthLabel(r.month)}</td>
                                        <td className="tabular px-4 py-2 text-right">{money(r.gross)}</td>
                                        <td className="tabular px-4 py-2 text-right">{money(r.expenses)}</td>
                                        <td className={`tabular px-4 py-2 text-right ${r.net < 0 ? 'text-destructive' : ''}`}>{money(r.net)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section aria-labelledby="low-h" className="space-y-3">
                        <h2 id="low-h" className="font-display text-xl font-bold">
                            {t('Low stock')}
                        </h2>
                        {lowStock.length === 0 ? (
                            <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('Nothing is low. Set a reorder level on a medicine to watch it.')}</p>
                        ) : (
                            <ul className="divide-y rounded-md border bg-card text-sm">
                                {lowStock.map((r) => (
                                    <li key={r.medicine_id} className="flex items-center justify-between gap-3 px-4 py-2.5">
                                        <Link href={`/admin/stock/medicines/${r.medicine_id}`} className="font-medium hover:underline">
                                            {r.brand_name} <strong>{r.strength}</strong>
                                        </Link>
                                        <span className="tabular text-muted-foreground">
                                            <span className={r.on_hand === 0 ? 'font-semibold text-destructive' : 'font-medium text-foreground'}>{num(r.on_hand)}</span> / {num(r.reorder_level)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section aria-labelledby="fin-h" className="space-y-3">
                        <h2 id="fin-h" className="font-display text-xl font-bold">
                            {t('Finished today')}
                        </h2>
                        {finishedToday.length === 0 ? (
                            <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('Nothing ran out today.')}</p>
                        ) : (
                            <ul className="divide-y rounded-md border bg-card text-sm">
                                {finishedToday.map((r) => (
                                    <li key={r.medicine_id} className="px-4 py-2.5">
                                        <span className="font-medium">
                                            {r.brand_name} <strong>{r.strength}</strong>
                                        </span>
                                        <span className="block text-xs text-muted-foreground">{r.company}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section aria-labelledby="exp-h" className="space-y-3">
                    <h2 id="exp-h" className="font-display text-xl font-bold">
                        {t('Shop expenses')}
                    </h2>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post('/admin/stock/expenses', { preserveScroll: true, onSuccess: () => form.reset('amount', 'note') });
                        }}
                        className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-6"
                    >
                        <div className="sm:col-span-1">
                            <label htmlFor="e-date" className="text-sm font-medium">
                                {t('Date')}
                            </label>
                            <Input id="e-date" type="date" max={today} value={form.data.spent_on} onChange={(e) => form.setData('spent_on', e.target.value)} className="mt-1" />
                            <InputError message={errors.spent_on} />
                        </div>
                        <div className="sm:col-span-1">
                            <label htmlFor="e-cat" className="text-sm font-medium">
                                {t('Category')}
                            </label>
                            <Input id="e-cat" list="expense-categories" value={form.data.category} maxLength={40} onChange={(e) => form.setData('category', e.target.value)} className="mt-1" />
                            <datalist id="expense-categories">
                                {categories.map((c) => (
                                    <option key={c} value={c} label={t(c)} />
                                ))}
                            </datalist>
                            <InputError message={errors.category} />
                        </div>
                        <div className="sm:col-span-1">
                            <label htmlFor="e-amt" className="text-sm font-medium">
                                {t('Amount')}
                            </label>
                            <Input id="e-amt" type="number" min="0" step="0.01" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} className="mt-1" />
                            <InputError message={errors.amount} />
                        </div>
                        <div className="sm:col-span-2">
                            <label htmlFor="e-note" className="text-sm font-medium">
                                {t('Note')}
                            </label>
                            <Input id="e-note" value={form.data.note} maxLength={300} onChange={(e) => form.setData('note', e.target.value)} className="mt-1" />
                        </div>
                        <div className="flex items-end sm:col-span-1">
                            <Button type="submit" loading={form.processing}>
                                {t('Add expense')}
                            </Button>
                        </div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-6">
                            <input type="checkbox" checked={form.data.recurring} onChange={(e) => form.setData('recurring', e.target.checked)} />
                            {t('Repeats every month')}
                        </label>
                    </form>

                    {expenses.length === 0 ? (
                        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No expenses this month.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {expenses.map((x) => (
                                <li key={x.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                                    <span>
                                        <span className="font-medium">{t(x.category)}</span> {x.recurring && <span className="rounded-full border px-2 py-0.5 text-xs">{t('Monthly')}</span>}
                                        <span className="block text-xs text-muted-foreground">{[date(x.spent_on), x.note].filter(Boolean).join(' · ')}</span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        <span className="tabular font-medium">{money(x.amount)}</span>
                                        <Button variant="ghost" size="sm" onClick={() => window.confirm(t('Remove this expense?')) && router.delete(`/admin/stock/expenses/${x.id}`, { preserveScroll: true })}>
                                            {t('Remove')}
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
