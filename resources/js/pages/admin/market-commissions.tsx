import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Row {
    seller_id: number;
    seller: string;
    orders: number;
    sales: number;
    commission: number;
    products: { title: string; sold: number; commission: number }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Market', href: '/admin/market' },
    { title: 'Commission', href: '/admin/market/commissions' },
];

export default function Commissions({ month, rows, problems }: { month: string; rows: Row[]; problems: { id: number; number: string; title: string; seller: string; seller_id: number; at: string }[] }) {
    const { t, money, num, dateTime } = useI18n();
    const total = rows.reduce((sum, r) => sum + r.commission, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Commission')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('Commission')}</h1>
                        <p className="text-sm text-muted-foreground">{t('Only complete sales count: delivered, and not reported as not received.')}</p>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        {t('Month')}
                        <input type="month" value={month} onChange={(e) => router.get('/admin/market/commissions', { month: e.target.value }, { preserveState: true })} className="h-9 rounded-md border bg-background px-2" />
                    </label>
                </div>
                <p className="tabular font-display text-3xl font-extrabold">{money(total)}</p>

                {rows.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">{t('No complete sales this month.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card">
                        {rows.map((r) => (
                            <li key={r.seller_id} className="space-y-1 px-4 py-3">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-medium">{r.seller}</p>
                                    <p className="tabular font-semibold">{t('Owes us {amount}', { amount: money(r.commission) })}</p>
                                </div>
                                <p className="tabular text-xs text-muted-foreground">
                                    {t('{n} orders', { n: num(r.orders) })} · {t('Sold for {amount}', { amount: money(r.sales) })}
                                </p>
                                <ul className="text-sm">
                                    {r.products.map((p) => (
                                        <li key={p.title} className="flex justify-between gap-3">
                                            <span>
                                                {p.title} × {num(p.sold)}
                                            </span>
                                            <span className="tabular">{money(p.commission)}</span>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                )}

                {problems.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Reported as not received')}</h2>
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {problems.map((p) => (
                                <li key={p.id} className="flex flex-wrap justify-between gap-2 px-4 py-2">
                                    <span>
                                        {p.number} · {p.title}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {p.seller} · {dateTime(p.at)}
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
