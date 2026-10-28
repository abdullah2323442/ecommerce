import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { unitLabel } from '@/lib/units';

interface CaseRow {
    id: number;
    kind: 'complaint' | 'return';
    reason: string;
    status: 'open' | 'solved' | 'returned' | 'escalated' | 'closed';
    seller_note: string | null;
    our_note: string | null;
    at: string;
    can_escalate: boolean;
}

interface PayInfo {
    can: boolean;
    why: string | null;
    amount: number;
    online: boolean;
    paid_online: number;
    practice: boolean;
    refund_due: boolean;
}

interface DigitalInfo {
    name: string | null;
    ready: boolean;
    left: number;
    until: string | null;
    paid: boolean;
    over: boolean;
    max: number;
    days: number;
}

interface Props {
    digital: DigitalInfo | null;
    pay: PayInfo;
    token: string;
    cases: CaseRow[];
    order: {
        number: string;
        title: string;
        variant: string | null;
        unit: string;
        quantity: number;
        unit_price: number;
        delivery_charge: number;
        token_off: number;
        total: number;
        status: 'placed' | 'accepted' | 'shipped' | 'delivered' | 'cancelled' | 'not_received' | 'returned';
        tracking: string | null;
        placed_at: string;
        cancel_reason: string | null;
        seller: string;
        ship_to: { name: string | null; phone: string | null; address: string };
        can_cancel: boolean;
        pickup: boolean;
        preorder: boolean;
        preorder_days: number | null;
        pickup_where: string | null;
        can_review: boolean;
        can_answer: boolean;
        can_complain: boolean;
        can_return: boolean;
        return_days: number;
        return_note: string | null;
    };
}

/** The buyer follows an order from the private link. The seller is named only as we allow; the buyer pays the seller when it arrives. */
function ReviewForm({ token }: { token: string }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [stars, setStars] = useState(0);
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);

    return (
        <section className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
            <h2 className="font-display text-xl font-bold">{t('How was it?')}</h2>
            <div role="radiogroup" aria-label={t('Stars')} className="flex gap-1">
                {[1, 2, 3, 4, 5].map((n) => (
                    <button key={n} type="button" role="radio" aria-checked={stars === n} aria-label={t('{n} stars', { n })} onClick={() => setStars(n)} className={`text-3xl leading-none ${n <= stars ? 'text-[var(--pt-gold,#c9a24b)]' : 'text-[var(--pt-line)]'}`}>
                        ★
                    </button>
                ))}
            </div>
            <textarea aria-label={t('Your words')} rows={3} maxLength={1000} value={body} onChange={(e) => setBody(e.target.value)} className="w-full rounded-md border bg-background p-2 text-sm" placeholder={t('Tell others what you think (optional)')} />
            <label className="block text-sm font-medium">
                {t('Photos (up to three)')}
                <input type="file" accept="image/*" multiple className="mt-1 block text-sm" onChange={(e) => setFiles(Array.from(e.target.files ?? []).slice(0, 3))} />
            </label>
            <InputError message={errors.order ?? errors.rating ?? errors.photos} />
            <Button disabled={stars === 0} onClick={() => router.post(`/market/orders/${token}/review`, { rating: stars, body, photos: files }, { forceFormData: true, preserveScroll: true })}>
                {t('Send my review')}
            </Button>
            <p className="text-xs text-[var(--pt-muted)]">{t('Your name is not shown. People see that you are a verified buyer.')}</p>
        </section>
    );
}

export default function MarketOrder({ token, order, cases, pay, digital }: Props) {
    const { t, money, num, dateTime } = useI18n();
    const [kind, setKind] = useState<'complaint' | 'return' | null>(null);
    const [reason, setReason] = useState('');
    const caseState = (s: CaseRow['status']) => t(s === 'open' ? 'Waiting for the seller' : s === 'solved' ? 'The seller says it is solved' : s === 'returned' ? 'Sent back' : s === 'escalated' ? 'With us' : 'Closed');
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const steps = [
        { key: 'placed', label: t('Sent') },
        { key: 'accepted', label: t('Accepted') },
        { key: 'shipped', label: t('On the way') },
        { key: 'delivered', label: t('Done') },
    ];
    const at = Math.max(0, steps.findIndex((s) => s.key === order.status));
    const post = (path: string, body: Record<string, string> = {}) => router.post(`/market/orders/${token}/${path}`, body, { preserveScroll: true });

    return (
        <PublicLayout>
            <Head title={order.number} />
            <div className="mx-auto max-w-2xl space-y-6">
                <Link href="/market" className="text-sm underline underline-offset-4">
                    {t('Back to the market')}
                </Link>
                <h1 className="font-display text-3xl font-extrabold">
                    {order.title}
                    {order.variant && <span className="block text-lg font-semibold text-[var(--pt-muted)]">{order.variant}</span>}
                </h1>
                <p className="text-sm text-[var(--pt-muted)]">
                    {order.number} · {dateTime(order.placed_at)} · {t('Sold by')} {order.seller}
                </p>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <InputError message={errors.order} />

                {order.status === 'cancelled' ? (
                    <p className="rounded-[1.5rem] border border-dashed p-4 text-sm">{t('This order was cancelled.')} {order.cancel_reason}</p>
                ) : order.status === 'returned' ? (
                    <p className="rounded-[1.5rem] border border-dashed p-4 text-sm">{t('This order was sent back to the seller.')}</p>
                ) : order.status === 'not_received' ? (
                    <p className="rounded-[1.5rem] border border-warning/40 bg-warning/10 p-4 text-sm">{t('You said this did not arrive. We are looking into it.')}</p>
                ) : (
                    <ol className="grid grid-cols-4 gap-2 text-center text-xs">
                        {steps.map((s, i) => (
                            <li key={s.key} className={`rounded-[1.25rem] border px-1 py-2 ${i <= at ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)] text-[var(--pt-muted)]'}`}>
                                {s.label}
                            </li>
                        ))}
                    </ol>
                )}

                {order.preorder && ['placed', 'accepted'].includes(order.status) && <p className="rounded-md bg-warning/10 px-3 py-1.5 text-sm">{order.preorder_days ? t('This is a pre-order. The seller will have it ready within {n} days, and we tell you.', { n: num(order.preorder_days) }) : t('This is a pre-order. The seller sends it when they have it again, and we tell you.')}</p>}
                {order.tracking && <p className="text-sm">{t('Tracking')}: {order.tracking}</p>}

                <dl className="tabular space-y-1 rounded-[1.5rem] border border-[var(--pt-line)] bg-[var(--pt-surface)] p-4 text-sm">
                    <div className="flex justify-between">
                        <dt>
                            {num(order.quantity)} {unitLabel(order.unit)} × {money(order.unit_price)}
                        </dt>
                        <dd>{money(order.quantity * order.unit_price)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>{t('Delivery charge')}</dt>
                        <dd>{money(order.delivery_charge)}</dd>
                    </div>
                    {order.token_off > 0 && (
                        <div className="flex justify-between">
                            <dt>{t('Aqua Token')}</dt>
                            <dd>−{money(order.token_off)}</dd>
                        </div>
                    )}
                    <div className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                        <dt>{t('Total')}</dt>
                        <dd>{money(order.total)}</dd>
                    </div>
                    {order.status === 'returned' || order.status === 'cancelled' ? (
                        <p className="pt-1 text-xs text-[var(--pt-muted)]">{t('Nothing to pay for this order.')}</p>
                    ) : (
                        !pay.online && <p className="pt-1 text-xs text-[var(--pt-muted)]">{t('You pay when it arrives.')}</p>
                    )}
                </dl>

                {order.pickup ? (
                    <p className="text-sm">
                        <strong>{t('You collect it')}:</strong> {order.pickup_where ?? t('We will tell you where when the seller says it is ready.')}
                    </p>
                ) : (
                    <p className="text-sm">
                        <strong>{t('Delivered to')}:</strong> {order.ship_to.name}, {order.ship_to.phone}, {order.ship_to.address}
                    </p>
                )}

                {digital && (
                    <section className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                        <h2 className="font-display text-lg font-bold">{t('Your download')}</h2>
                        {digital.name && <p className="text-sm font-medium">{digital.name}</p>}
                        {!digital.paid && <p className="text-sm text-[var(--pt-muted)]">{t('You can download it as soon as you have paid. You get {n} downloads in {d} days.', { n: num(digital.max), d: num(digital.days) })}</p>}
                        {digital.paid && digital.ready && (
                            <>
                                <a href={`/market/orders/${token}/download`} className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-sm font-semibold text-primary-foreground">
                                    {t('Download')}
                                </a>
                                <p className="text-xs text-[var(--pt-muted)]">
                                    {t('{n} downloads left.', { n: num(digital.left) })} {digital.until && t('Until {date}.', { date: dateTime(digital.until) })}
                                </p>
                            </>
                        )}
                        {digital.paid && !digital.ready && <p className="text-sm font-semibold">{digital.over ? t('The download time for this order is over.') : t('You have used all your downloads.')}</p>}
                    </section>
                )}
                {(pay.online || pay.refund_due) && (
                    <section className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                        <h2 className="font-display text-lg font-bold">{t('Payment')}</h2>
                        {pay.paid_online > 0 && <p className="tabular text-sm font-semibold text-success">{t('Paid online')}: {money(pay.paid_online)}</p>}
                        {pay.practice && <p className="text-sm text-[var(--pt-muted)]">{t('A practice payment was made. No real money was taken.')}</p>}
                        {pay.refund_due && <p className="text-sm font-semibold">{t('Your payment will be returned to you. We will tell you.')}</p>}
                        {pay.can && (
                            <>
                                <p className="text-sm">{t('Pay now with bKash, Nagad, Rocket, a card or your bank. The seller sees your order after you pay.')}</p>
                                <Button onClick={() => router.post(`/market/orders/${token}/pay`, {})}>{t('Pay {amount} online', { amount: money(pay.amount) })}</Button>
                            </>
                        )}
                    </section>
                )}
                <a href={`/receipt/market/${token}`} target="_blank" rel="noreferrer" className="inline-block text-sm font-semibold underline underline-offset-4">
                    {t('Print the receipt')}
                </a>
                <Link href={`/support/new?order=${token}`} className="ms-5 inline-block text-sm font-semibold underline underline-offset-4">
                    {t('Get help with this order')}
                </Link>
                {order.can_review && <ReviewForm token={token} />}
                {order.can_cancel && (
                    <Button variant="outline" onClick={() => window.confirm(t('Cancel this order?')) && post('cancel')}>
                        {t('Cancel this order')}
                    </Button>
                )}
                {order.can_answer && (
                    <div className="flex flex-wrap items-center gap-3 rounded-[1.5rem] border p-4">
                        <p className="flex-1 text-sm font-semibold">{t('Did it arrive?')}</p>
                        <Button onClick={() => post('received')}>{t('Yes, I got it')}</Button>
                        <Button variant="outline" onClick={() => post('not-received')}>
                            {t('No, it did not arrive')}
                        </Button>
                    </div>
                )}

                {cases.length > 0 && (
                    <ul className="space-y-2">
                        {cases.map((c) => (
                            <li key={c.id} className="space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-semibold">{c.kind === 'return' ? t('Return') : t('Complaint')}</p>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">{caseState(c.status)}</span>
                                </div>
                                <p>{c.reason}</p>
                                {c.seller_note && <p className="text-[var(--pt-muted)]">{t('The seller')}: {c.seller_note}</p>}
                                {c.our_note && <p className="rounded-md bg-warning/10 p-2">{c.our_note}</p>}
                                {c.can_escalate && (
                                    <Button size="sm" variant="outline" onClick={() => post(`cases/${c.id}/escalate`)}>
                                        {t('Ask us to look into it')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {(order.can_complain || order.can_return) && (
                    <div className="space-y-3 rounded-[1.5rem] border p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            {order.can_complain && (
                                <Button size="sm" variant={kind === 'complaint' ? 'default' : 'outline'} onClick={() => setKind('complaint')}>
                                    {t('I have a complaint')}
                                </Button>
                            )}
                            {order.can_return && (
                                <Button size="sm" variant={kind === 'return' ? 'default' : 'outline'} onClick={() => setKind('return')}>
                                    {t('I want to send it back')}
                                </Button>
                            )}
                        </div>
                        {order.can_return && <p className="text-xs text-[var(--pt-muted)]">{t('You can send it back within {n} days of getting it. It goes straight to the seller, and you pay nothing for the delivery.', { n: num(order.return_days) })}{order.return_note && ` ${order.return_note}`}</p>}
                        {kind && (
                            <div className="flex flex-wrap items-center gap-2">
                                <Input aria-label={t('What went wrong?')} className="h-9 min-w-64 flex-1" placeholder={t('What went wrong?')} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={300} />
                                <Button disabled={reason.trim().length < 3} onClick={() => post('case', { kind, reason })}>
                                    {t('Send')}
                                </Button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
