import Flash from '@/components/admin/flash';
import TakePayment from '@/components/admin/take-payment';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Clock } from 'lucide-react';

interface Props {
    customer: { id: number; name: string; phone: string | null; type: string };
    account: {
        owed: number;
        open: { id: number; number: string | null; when: string | null; total: number; paid: number; due: number; days: number; late: boolean }[];
        payments: { id: number; memo: string | null; amount: number; method: string; note: string | null; when: string; by: string | null }[];
    };
    overdueDays: number;
    methods: string[];
}

const NAMES: Record<string, string> = { cash: 'Cash', bkash: 'bKash', nagad: 'Nagad', rocket: 'Rocket', bank: 'Bank', card: 'Card' };

/** One customer's account: what they owe (it leads), taking a payment, the memos still open and what they have paid. */
export default function LedgerShow({ customer, account, overdueDays, methods }: Props) {
    const { t, money, date, dateTime, num } = useI18n();
    // One level only: the page has its own back link, and a second level crowds the header on a narrow phone.
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credit ledger', href: '/admin/ledger' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${customer.name} - ${t('Credit ledger')}`} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />

                <header className="space-y-1">
                    <Link href="/admin/ledger" className="inline-flex items-center gap-1 text-sm text-muted-foreground underline-offset-4 hover:underline">
                        <ArrowLeft aria-hidden className="size-4" />
                        {t('Credit ledger')}
                    </Link>
                    <h1 className="text-lg font-semibold">{customer.name}</h1>
                    <p className="text-sm text-muted-foreground">{customer.phone}</p>
                    <p className="mt-3 font-display text-5xl font-extrabold tracking-tight tabular" aria-label={t('Owes us')}>
                        {money(account.owed)}
                    </p>
                    <p className="text-sm text-muted-foreground">{account.owed > 0 ? t('Owes us, on {memos}', { memos: account.open.length === 1 ? t('{n} memo', { n: num(1) }) : t('{n} memos', { n: num(account.open.length) }) }) : t('Owes nothing.')}</p>
                </header>

                {account.owed > 0 ? (
                    <TakePayment url={`/admin/ledger/${customer.id}/payment`} methods={methods} owed={account.owed} action={t('Take payment')} />
                ) : (
                    <p role="status" className="flex items-center gap-2 rounded-lg border bg-muted/40 p-3 text-sm">
                        <CheckCircle2 aria-hidden className="size-4 text-success" />
                        {t('This account is clear. There is nothing to collect.')}
                    </p>
                )}

                {account.open.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="text-lg font-semibold">{t('Memos still open')}</h2>
                        <p className="text-sm text-muted-foreground">{t('A payment goes to the oldest memo first. Late means older than {days} days.', { days: num(overdueDays) })}</p>
                        <ul className="divide-y rounded-lg border text-sm">
                            {account.open.map((m) => (
                                <li key={m.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                                    <Link href={`/admin/counter/${m.id}`} className="w-28 font-mono text-xs underline underline-offset-2">
                                        {m.number ?? `#${m.id}`}
                                    </Link>
                                    <span className="text-xs text-muted-foreground">{m.when && date(m.when)}</span>
                                    {m.late && (
                                        <span className="inline-flex items-center gap-1 rounded-md border border-warning/40 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                            <Clock aria-hidden className="size-3" />
                                            {t('Late: {days} days', { days: num(m.days) })}
                                        </span>
                                    )}
                                    <span className="ms-auto text-xs text-muted-foreground">
                                        {t('Total {total}, paid {paid}', { total: money(m.total), paid: money(m.paid) })}
                                    </span>
                                    <span className="tabular w-24 text-right text-base font-bold">{money(m.due)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">{t('Payments received')}</h2>
                    {account.payments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('No payment has been taken yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {account.payments.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2">
                                    <span className="w-36 text-xs text-muted-foreground">{dateTime(p.when)}</span>
                                    <span className="font-mono text-xs">{p.memo}</span>
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
