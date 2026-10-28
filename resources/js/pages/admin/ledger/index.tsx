import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Clock, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    phone: string | null;
    owed: number;
    memos: number;
    oldest_days: number;
    late: boolean;
}

interface Props {
    rows: Row[];
    total: number;
    late: { customers: number; owed: number };
    noCustomer: { count: number; owed: number };
    overdueDays: number;
    q: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credit ledger', href: '/admin/ledger' }];

/** Who owes us (baki khata). The total leads; each customer opens their own statement. */
export default function Ledger({ rows, total, late, noCustomer, overdueDays, q }: Props) {
    const { t, money, num } = useI18n();
    const [search, setSearch] = useState(q);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Credit ledger')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />

                <header className="space-y-1">
                    <h1 className="text-lg font-semibold">{t('Credit ledger')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Who owes us money on memos left on credit. Open a name to see their account and take a payment.')}</p>
                    <p className="mt-3 font-display text-5xl font-extrabold tracking-tight tabular" aria-label={t('Owed to us')}>
                        {money(total)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {late.customers > 0 ? (
                            <span className="inline-flex items-center gap-1 font-medium text-warning">
                                <Clock aria-hidden className="size-4" />
                                {t('{n} late, {amount} (older than {days} days)', { n: num(late.customers), amount: money(late.owed), days: num(overdueDays) })}
                            </span>
                        ) : (
                            t('Nobody is late. A customer is late after {days} days.', { days: num(overdueDays) })
                        )}
                    </p>
                </header>

                {noCustomer.count > 0 && (
                    <div role="note" className="flex items-start gap-2 rounded-lg border bg-muted/40 p-3 text-sm">
                        <TriangleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
                        <p>
                            {t('{amount} is owed on {n} memos with no customer on them, so it is not listed here.', { amount: money(noCustomer.owed), n: num(noCustomer.count) })}{' '}
                            <Link href="/admin/invoices?due=1" className="font-medium underline underline-offset-4">
                                {t('See them in All orders')}
                            </Link>
                        </p>
                    </div>
                )}

                <form className="flex gap-2" onSubmit={(e) => (e.preventDefault(), router.get('/admin/ledger', { q: search }, { preserveState: true }))}>
                    <Input aria-label={t('Find a customer')} placeholder={t('Name or phone')} value={search} onChange={(e) => setSearch(e.target.value)} />
                    <Button type="submit" variant="outline">
                        {t('Find')}
                    </Button>
                </form>

                {rows.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <p className="font-medium">{q !== '' ? t('Nobody found.') : t('Nobody owes us anything.')}</p>
                        {q === '' && <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">{t('When a sale is left on credit and a customer is named on the memo, they appear here with what they owe.')}</p>}
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {rows.map((r) => (
                            <li key={r.id}>
                                <Link href={`/admin/ledger/${r.id}`} className="flex items-center gap-3 px-4 py-3 hover:bg-muted focus-visible:bg-muted focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring">
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">{r.name}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {r.phone && <>{r.phone} · </>}
                                            {t('{memos}, the oldest {days}', { memos: r.memos === 1 ? t('{n} memo', { n: num(r.memos) }) : t('{n} memos', { n: num(r.memos) }), days: r.oldest_days === 1 ? t('{n} day old', { n: num(r.oldest_days) }) : t('{n} days old', { n: num(r.oldest_days) }) })}
                                        </span>
                                    </span>
                                    {r.late && (
                                        <span className="inline-flex shrink-0 items-center gap-1 rounded-md border border-warning/40 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                            <Clock aria-hidden className="size-3" />
                                            {t('Late')}
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
