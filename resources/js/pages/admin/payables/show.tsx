import Flash from '@/components/admin/flash';
import TakePayment from '@/components/admin/take-payment';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock } from 'lucide-react';

interface Props {
    source: { id: number; name: string; type: string };
    account: {
        owed: number;
        open: { id: number; number: string | null; date: string | null; total: number; paid: number; owed: number; due_date: string | null; late: boolean }[];
        payments: { id: number; invoice: string | null; amount: number; method: string; note: string | null; when: string; by: string | null }[];
    };
    methods: string[];
}

const NAMES: Record<string, string> = { cash: 'Cash', bkash: 'bKash', nagad: 'Nagad', rocket: 'Rocket', bank: 'Bank', cheque: 'Cheque' };

/** One company's account: what we owe it (it leads), recording a payment, the invoices still open by due day, and what we paid. */
export default function PayablesShow({ source, account, methods }: Props) {
    const { t, money, date, dateTime, num } = useI18n();
    // One level only: the page has its own back link, and a second level crowds the header on a narrow phone.
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Company dues', href: '/admin/payables' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${source.name} - ${t('Company dues')}`} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />

                <header className="space-y-1">
                    <Link href="/admin/payables" className="inline-flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline">
                        <ArrowLeft aria-hidden className="size-4" />
                        {t('Company dues')}
                    </Link>
                    <h1 className="text-lg font-semibold">{source.name}</h1>
                    <p className="mt-3 font-display text-5xl font-extrabold tracking-tight tabular" aria-label={t('We owe')}>
                        {money(account.owed)}
                    </p>
                    <p className="text-sm text-muted-foreground">{account.owed > 0 ? t('We owe, on {invoices}', { invoices: account.open.length === 1 ? t('{n} invoice', { n: num(1) }) : t('{n} invoices', { n: num(account.open.length) }) }) : t('We owe nothing.')}</p>
                </header>

                {account.owed > 0 ? (
                    <TakePayment url={`/admin/payables/${source.id}/payment`} methods={methods} owed={account.owed} action={t('Record payment')} />
                ) : (
                    <p role="status" className="flex items-center gap-2 rounded-lg border bg-muted/40 p-3 text-sm">
                        <CheckCircle2 aria-hidden className="size-4 text-success" />
                        {t('Everything is paid. There is nothing to pay.')}
                    </p>
                )}

                {account.open.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-lg font-semibold">{t('Invoices still open')}</h2>
                        <p className="text-sm text-muted-foreground">{t('A payment goes to the invoice that falls due first.')}</p>
                        <ul className="divide-y rounded-lg border text-sm">
                            {account.open.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                                    <span className="w-28 font-mono text-xs">{p.number ?? `#${p.id}`}</span>
                                    <span className="text-xs text-muted-foreground">{p.date && date(p.date)}</span>
                                    {p.late ? (
                                        <span className="inline-flex items-center gap-1 rounded-md border border-warning/40 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                            <Clock aria-hidden className="size-3" />
                                            {t('Late: was due {date}', { date: date(p.due_date as string) })}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">{p.due_date ? t('Due {date}', { date: date(p.due_date) }) : t('No due day')}</span>
                                    )}
                                    <span className="ms-auto text-xs text-muted-foreground">{t('Total {total}, paid {paid}', { total: money(p.total), paid: money(p.paid) })}</span>
                                    <span className="tabular w-24 text-right text-base font-bold">{money(p.owed)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">{t('Payments we made')}</h2>
                    {account.payments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No payment has been recorded here yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {account.payments.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2">
                                    <span className="w-36 text-xs text-muted-foreground">{dateTime(p.when)}</span>
                                    <span className="font-mono text-xs">{p.invoice}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {t(NAMES[p.method] ?? p.method)}
                                        {p.by && <> · {p.by}</>}
                                    </span>
                                    {p.note && <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{p.note}</span>}
                                    <span className="tabular ms-auto font-semibold">{money(p.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
