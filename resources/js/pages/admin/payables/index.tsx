import Flash from '@/components/admin/flash';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight, Clock } from 'lucide-react';

interface Row {
    id: number;
    name: string;
    type: string;
    owed: number;
    invoices: number;
    next_due: string | null;
    late_owed: number;
}

interface Props {
    rows: Row[];
    total: number;
    late: { invoices: number; owed: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Company dues', href: '/admin/payables' }];

/** What we owe each company or supplier (the ledger's other side). The total leads; the most overdue come first. */
export default function Payables({ rows, total, late }: Props) {
    const { t, money, date, num } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Company dues')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />

                <header className="space-y-1">
                    <h1 className="text-lg font-semibold">{t('Company dues')}</h1>
                    <p className="text-sm text-muted-foreground">{t('What we still owe each company or supplier, and the day it is due. Set it when you enter a purchase that is not paid in full.')}</p>
                    <p className="mt-3 font-display text-5xl font-extrabold tracking-tight tabular" aria-label={t('We owe')}>
                        {money(total)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {late.invoices > 0 ? (
                            <span className="inline-flex items-center gap-1 font-medium text-warning">
                                <Clock aria-hidden className="size-4" />
                                {t('{n} past their due day, {amount}', { n: num(late.invoices), amount: money(late.owed) })}
                            </span>
                        ) : (
                            t('Nothing is past its due day.')
                        )}
                    </p>
                </header>

                {rows.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <p className="font-medium">{t('We owe nobody anything.')}</p>
                        <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">{t('A purchase is counted as paid when you enter it. If you pay only part, the rest is listed here with its due day.')}</p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {rows.map((r) => (
                            <li key={r.id}>
                                <Link href={`/admin/payables/${r.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-muted focus-visible:bg-muted focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring">
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">{r.name}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {r.invoices === 1 ? t('{n} invoice', { n: num(1) }) : t('{n} invoices', { n: num(r.invoices) })}
                                            {r.next_due && <> · {t('next due {date}', { date: date(r.next_due) })}</>}
                                        </span>
                                    </span>
                                    {r.late_owed > 0 && (
                                        <span className="inline-flex shrink-0 items-center gap-1 rounded-md border border-warning/40 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                            <Clock aria-hidden className="size-3" />
                                            {t('Late: {amount}', { amount: money(r.late_owed) })}
                                        </span>
                                    )}
                                    <span className="tabular shrink-0 text-lg font-bold">{money(r.owed)}</span>
                                    <ChevronRight aria-hidden className="size-4 shrink-0 text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
