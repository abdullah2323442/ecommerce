import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Order {
    token: string;
    number: string;
    status: 'placed' | 'claimed' | 'paid' | 'topped_up' | 'failed' | 'cancelled';
    game: string;
    package: string;
    price: number;
    player_id: string;
    zone: string | null;
    player_name: string | null;
    pay_method: string | null;
    pay_note: string | null;
    fail_reason: string | null;
    refund: 'none' | 'due' | 'done';
    at: string;
    late: boolean;
    can_cancel: boolean;
    late_minutes: number;
}

export default function GamingOrder({ order, pay, methods }: { order: Order; pay: Record<string, string>; methods: string[] }) {
    const { t, money, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [method, setMethod] = useState(Object.keys(pay)[0] ?? methods[0]);
    const [ref, setRef] = useState('');
    const open = !['topped_up', 'failed', 'cancelled'].includes(order.status);
    const steps = ['placed', 'claimed', 'paid', 'topped_up'];
    const label = (s: string) => t(s === 'placed' ? 'Pay' : s === 'claimed' ? 'We check the payment' : s === 'paid' ? 'Top-up in progress' : 'Topped up');
    const at = Math.max(0, steps.indexOf(order.status));
    const name = (m: string) => (m === 'bkash' ? 'bKash' : m === 'nagad' ? 'Nagad' : 'Rocket');

    // The page asks again while it waits: we check the payment, then the diamonds go.
    useEffect(() => {
        if (!open) {
            return;
        }
        const id = window.setInterval(() => document.visibilityState === 'visible' && router.reload(), 20000);

        return () => window.clearInterval(id);
    }, [open]);

    return (
        <PublicLayout>
            <Head title={order.number} />
            <div className="mx-auto max-w-2xl space-y-6">
                <Link href="/gaming" className="text-sm underline underline-offset-4">
                    {t('Game top-up')}
                </Link>
                <div>
                    <h1 className="font-display text-3xl font-extrabold">
                        {order.game} · {order.package}
                    </h1>
                    <p className="text-sm text-[var(--pt-muted)]">
                        {order.number} · {dateTime(order.at)}
                    </p>
                </div>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <a href={`/receipt/game/${order.token}`} target="_blank" rel="noreferrer" className="inline-block text-sm font-semibold underline underline-offset-4">
                    {t('Print the receipt')}
                </a>
                <InputError message={errors.order ?? errors.ref ?? errors.method} />

                {order.status === 'cancelled' ? (
                    <p className="rounded-[1.5rem] border border-dashed p-4 text-sm">
                        {t('This order was cancelled.')} {order.refund === 'due' ? t('Your money will be refunded.') : order.refund === 'done' ? t('Your money was refunded.') : ''}
                    </p>
                ) : order.status === 'failed' ? (
                    <p role="alert" className="rounded-[1.5rem] border border-danger/40 bg-danger/10 p-4 text-sm text-danger">
                        {t('The top-up did not go through.')} {order.fail_reason} {order.refund === 'done' ? t('Your money was refunded.') : t('Your money will be refunded.')}
                    </p>
                ) : (
                    <ol className="grid grid-cols-4 gap-2 text-center text-xs">
                        {steps.map((s, i) => (
                            <li key={s} className={`rounded-[1.25rem] border px-1 py-2 ${i <= at ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)] text-[var(--pt-muted)]'}`}>
                                {label(s)}
                            </li>
                        ))}
                    </ol>
                )}

                <dl className="tabular space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                    <div className="flex justify-between">
                        <dt>{t('Player ID')}</dt>
                        <dd className="font-semibold">
                            {order.player_id} {order.zone && `(${order.zone})`}
                        </dd>
                    </div>
                    {order.player_name && (
                        <div className="flex justify-between">
                            <dt>{t('Player')}</dt>
                            <dd className="font-semibold">{order.player_name}</dd>
                        </div>
                    )}
                    <div className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                        <dt>{t('To pay')}</dt>
                        <dd>{money(order.price)}</dd>
                    </div>
                </dl>

                {order.status === 'placed' && (
                    <section className="space-y-3 rounded-[1.5rem] border border-[var(--pt-ink)] p-4">
                        <h2 className="font-display text-xl font-bold">{t('Pay {amount}', { amount: money(order.price) })}</h2>
                        {order.pay_note && <p role="alert" className="rounded-md bg-warning/10 p-2 text-sm text-warning">{t('Your last payment was not accepted')}: {order.pay_note}</p>}
                        {Object.keys(pay).length === 0 ? (
                            <p className="text-sm text-[var(--pt-muted)]">{t('Online payment is not open yet. Please try again soon.')}</p>
                        ) : (
                            <>
                                <p className="text-sm">{t('Send the exact amount with Send Money to one of these numbers, then write the transaction id from your message below.')}</p>
                                <ul className="space-y-1 text-sm">
                                    {Object.entries(pay).map(([m, n]) => (
                                        <li key={m} className="flex justify-between rounded-md bg-muted/50 px-3 py-2">
                                            <span className="font-semibold">{name(m)}</span>
                                            <span className="tabular">{n}</span>
                                        </li>
                                    ))}
                                </ul>
                                <div className="flex flex-wrap items-end gap-2">
                                    <label className="text-sm font-medium">
                                        {t('You paid with')}
                                        <select value={method} onChange={(e) => setMethod(e.target.value)} className="mt-1 block h-9 rounded-md border bg-background px-2 text-sm">
                                            {Object.keys(pay).map((m) => (
                                                <option key={m} value={m}>
                                                    {name(m)}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="min-w-48 flex-1 text-sm font-medium">
                                        {t('Transaction id')}
                                        <Input className="mt-1" value={ref} maxLength={40} onChange={(e) => setRef(e.target.value)} />
                                    </label>
                                    <Button disabled={ref.trim().length < 6} onClick={() => router.post(`/gaming/orders/${order.token}/claim`, { method, ref }, { preserveScroll: true })}>
                                        {t('I have paid')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </section>
                )}

                {order.status === 'claimed' && <p className="rounded-[1.5rem] border p-4 text-sm">{t('We are checking your payment. Then the diamonds go to your ID.')}</p>}
                {order.status === 'paid' && !order.late && <p className="rounded-[1.5rem] border p-4 text-sm">{t('Paid. Your top-up is being done.')}</p>}
                {order.status === 'topped_up' && <p role="status" className="rounded-[1.5rem] border border-success/30 bg-success/10 p-4 text-sm font-semibold text-success">{t('Done. The diamonds are in your account.')}</p>}

                {order.late && (
                    <p role="alert" className="rounded-[1.5rem] border border-warning/40 bg-warning/10 p-4 text-sm">
                        {t('This is taking more than {n} minutes. You can wait a little longer, or cancel it and get your money back.', { n: num(order.late_minutes) })}
                    </p>
                )}
                {order.can_cancel && (
                    <Button variant="outline" onClick={() => window.confirm(order.status === 'paid' ? t('Cancel this top-up and get your money back?') : t('Cancel this order?')) && router.post(`/gaming/orders/${order.token}/cancel`, {}, { preserveScroll: true })}>
                        {order.status === 'paid' ? t('Cancel and get my money back') : t('Cancel this order')}
                    </Button>
                )}
            </div>
        </PublicLayout>
    );
}
