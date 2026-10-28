import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    required: number;
    own: number | null;
    held: number;
    short: number;
}

interface Props {
    on: boolean;
    usual: number;
    rows: Row[];
    open: number | null;
    history: { id: number; amount: number; kind: 'received' | 'returned' | 'deducted'; reason: string | null; at: string; by: string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Seller deposits', href: '/admin/market/deposits' }];

/** One seller's money kept with us: take it in, give it back, deduct with a reason, and set their own amount. */
function Panel({ row, history }: { row: Row; history: Props['history'] }) {
    const { t, money, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');
    const [own, setOwn] = useState(row.own === null ? '' : String(row.own));
    const ok = Number(amount) > 0;
    const go = (path: string) => router.post(`/admin/market/deposits/${row.id}/${path}`, { amount, note }, { preserveScroll: true, onSuccess: () => (setAmount(''), setNote('')) });
    const kind = (k: Props['history'][number]['kind']) => t(k === 'received' ? 'Taken in' : k === 'returned' ? 'Given back' : 'Deducted');

    return (
        <section className="space-y-4 rounded-lg border bg-card p-4" aria-label={row.name}>
            <div>
                <h2 className="text-lg font-semibold">{row.name}</h2>
                <p className="font-display text-4xl font-extrabold tracking-tight tabular">{money(row.held)}</p>
                <p className="text-sm text-muted-foreground">{t('Held now. Asked for: {amount}', { amount: money(row.required) })}</p>
            </div>

            <div className="flex flex-wrap items-end gap-2">
                <label className="min-w-32 flex-1 text-sm font-medium">
                    {t('Amount')}
                    <Input className="mt-1" type="number" inputMode="decimal" min="0" step="any" value={amount} onChange={(e) => setAmount(e.target.value)} />
                </label>
                <label className="min-w-40 flex-[2] text-sm font-medium">
                    {t('Reason or note')}
                    <Input className="mt-1" maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
                </label>
            </div>
            <InputError message={errors.deposit ?? errors.amount} />
            <div className="flex flex-wrap gap-2">
                <Button disabled={!ok} onClick={() => go('receive')}>
                    {t('Take it in')}
                </Button>
                <Button variant="outline" disabled={!ok} onClick={() => go('give-back')}>
                    {t('Give back')}
                </Button>
                <Button variant="destructive" disabled={!ok || note.trim().length < 3} onClick={() => window.confirm(t('Take this money from the deposit? The seller is told why.')) && go('deduct')}>
                    {t('Deduct')}
                </Button>
            </div>
            <p className="text-xs text-muted-foreground">{t('Deducting needs a reason. The seller is told about every change.')}</p>

            <form
                className="flex flex-wrap items-end gap-2 border-t pt-3"
                onSubmit={(e) => (e.preventDefault(), router.put(`/admin/market/deposits/${row.id}/required`, { required: own === '' ? null : own }, { preserveScroll: true }))}
            >
                <label className="min-w-40 flex-1 text-sm font-medium">
                    {t('This seller is asked for (empty means the usual amount)')}
                    <Input className="mt-1" type="number" inputMode="decimal" min="0" step="any" value={own} onChange={(e) => setOwn(e.target.value)} />
                </label>
                <Button type="submit" variant="outline">
                    {t('Save')}
                </Button>
            </form>

            <div className="space-y-1 border-t pt-3">
                <h3 className="text-sm font-semibold">{t('What happened')}</h3>
                {history.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('Nothing yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border text-sm">
                        {history.map((h) => (
                            <li key={h.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2">
                                <span className="w-36 text-xs text-muted-foreground">{dateTime(h.at)}</span>
                                <span className="font-medium">{kind(h.kind)}</span>
                                {h.reason && <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">{h.reason}</span>}
                                {h.by && <span className="text-xs text-muted-foreground">{h.by}</span>}
                                <span className="tabular ms-auto font-semibold">{h.amount < 0 ? `-${money(Math.abs(h.amount))}` : money(h.amount)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

export default function MarketDeposits({ on, usual, rows, open, history }: Props) {
    const { t, money } = useI18n();
    const chosen = rows.find((r) => r.id === open) ?? null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Seller deposits')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />
                <header className="space-y-1">
                    <h1 className="text-lg font-semibold">{t('Seller deposits')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Money a seller keeps with us against returns and unpaid commission. It is given back when they leave, or kept for a reason you write.')}</p>
                    <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm" role="status">
                        {on ? t('Deposits are on. The usual amount is {amount}.', { amount: money(usual) }) : t('Deposits are off, so nobody is asked for one. Turn them on in Settings under Market when you are ready.')}{' '}
                        <Link href="/admin/settings" className="font-medium underline underline-offset-4">
                            {t('Open Settings')}
                        </Link>
                    </p>
                </header>

                {chosen && <Panel key={chosen.id} row={chosen} history={history} />}

                {rows.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">{t('No seller has listed anything yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {rows.map((r) => (
                            <li key={r.id}>
                                <Link href={`/admin/market/deposits?seller=${r.id}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 hover:bg-muted focus-visible:bg-muted focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring" aria-current={r.id === open ? 'true' : undefined}>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">{r.name}</span>
                                        <span className="block text-xs text-muted-foreground">{r.required > 0 ? t('Asked for {amount}', { amount: money(r.required) }) : t('Not asked for a deposit')}</span>
                                    </span>
                                    {r.short > 0 && (
                                        <span className="inline-flex items-center gap-1 rounded-md border border-warning/40 bg-warning/10 px-2 py-0.5 text-xs font-semibold text-warning">
                                            <Clock aria-hidden className="size-3" />
                                            {t('Short by {amount}', { amount: money(r.short) })}
                                        </span>
                                    )}
                                    <span className="tabular text-lg font-bold">{money(r.held)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
