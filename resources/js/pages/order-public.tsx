import AddToOrder from '@/components/add-to-order';
import DeliveryStatus from '@/components/delivery-status';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Input } from '@/components/ui/input';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { unitName } from './cart';

interface Line {
    id: number;
    name: string;
    strength: string | null;
    company: string | null;
    unit: string;
    quantity: number;
    requested: number;
    check: 'pending' | 'full' | 'partial' | 'unavailable' | null;
    unit_price: number;
    total: number;
}

interface PayInfo {
    can: boolean;
    why: string | null;
    amount: number;
    paid_online: number;
    practice: boolean;
    refund_due: boolean;
}

interface Props {
    pay: PayInfo;
    token: string;
    districts: string[];
    order: {
        status: 'draft' | 'placed' | 'verified' | 'final' | 'cancelled';
        number: string | null;
        placed_at: string | null;
        note: string | null;
        wanted_time: string | null;
        has_voice: boolean;
        has_photo: boolean;
        can_add_note: boolean;
        delivery_mode: string | null;
        ship_to: { name: string; phone: string; district: string; area: string; address: string; how_to_find?: string | null; for_whom?: string | null } | null;
        subtotal: number;
        delivery: number;
        total: number;
        cancel_minutes: number;
        can_edit_items: boolean;
        advance_needed: boolean;
        delivery_refund: number;
        sent_at: string | null;
        tracking: string | null;
        delivery_extra: number;
        delivered: boolean;
        not_received: boolean;
        courier_status: string | null;
        own_delivery: boolean;
        code: string | null;
        arrived: boolean;
        can_rate: boolean;
        can_change_address: boolean;
        mine: boolean;
        lines: Line[];
    };
}

/** Follow an order from the private link. Shows what staff have checked and what to pay; nothing about stock. */
function ChangeAddress({ token, ship, districts }: { token: string; ship: NonNullable<Props['order']['ship_to']>; districts: string[] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [open, setOpen] = useState(false);
    const [f, setF] = useState({ name: ship.name, phone: ship.phone, district: ship.district, area: ship.area, address: ship.address, how_to_find: ship.how_to_find ?? '', for_whom: ship.for_whom ?? '' });
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });

    if (!open) {
        return (
            <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                {t('Change the address')}
            </Button>
        );
    }

    return (
        <div className="grid gap-2 rounded-2xl border p-4 sm:grid-cols-2">
            <Input aria-label={t('Your name')} value={f.name} onChange={set('name')} maxLength={120} />
            <Input aria-label={t('Mobile number')} inputMode="tel" value={f.phone} onChange={set('phone')} />
            <select aria-label={t('District')} value={f.district} onChange={set('district')} className="h-10 rounded-md border bg-background px-2 text-sm">
                {districts.map((d) => (
                    <option key={d} value={d}>
                        {t(d)}
                    </option>
                ))}
            </select>
            <Input aria-label={t('Area or thana')} value={f.area} onChange={set('area')} maxLength={80} />
            <Input className="sm:col-span-2" aria-label={t('House, road and flat')} value={f.address} onChange={set('address')} maxLength={250} />
            <Input aria-label={t('How to get there')} value={f.how_to_find} onChange={set('how_to_find')} maxLength={200} />
            <Input aria-label={t('Ordering for someone else? Their name')} value={f.for_whom} onChange={set('for_whom')} maxLength={120} />
            <div className="flex items-center gap-2 sm:col-span-2">
                <Button size="sm" onClick={() => router.put(`/orders/${token}/address`, f, { preserveScroll: true, onSuccess: () => setOpen(false) })}>
                    {t('Save the address')}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>
                    {t('Cancel')}
                </Button>
                <InputError message={errors.address ?? errors.phone ?? errors.area ?? errors.name} />
            </div>
        </div>
    );
}

/** The delivery and the service, one to five stars each. Not the product, and not who they were (spec ch 20). */
function RateDelivery({ token }: { token: string }) {
    const { t } = useI18n();
    const [delivery, setDelivery] = useState(0);
    const [service, setService] = useState(0);
    const [comment, setComment] = useState('');
    const row = (label: string, value: number, set: (n: number) => void) => (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="text-sm font-medium">{label}</span>
            <div role="radiogroup" aria-label={label} className="flex gap-1">
                {[1, 2, 3, 4, 5].map((n) => (
                    <button key={n} type="button" role="radio" aria-checked={value === n} aria-label={t('{n} stars', { n })} onClick={() => set(n)} className={`text-3xl leading-none ${n <= value ? 'text-[var(--pt-gold,#c9a24b)]' : 'text-[var(--pt-line)]'}`}>
                        ★
                    </button>
                ))}
            </div>
        </div>
    );

    return (
        <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <h2 className="font-display text-xl font-bold">{t('How was the delivery?')}</h2>
            {row(t('The delivery'), delivery, setDelivery)}
            {row(t('Our service'), service, setService)}
            <textarea aria-label={t('Your words')} rows={2} maxLength={500} value={comment} onChange={(e) => setComment(e.target.value)} className="w-full rounded-md border bg-background p-2 text-sm" placeholder={t('Tell us more (optional)')} />
            <Button disabled={delivery === 0 || service === 0} onClick={() => router.post(`/orders/${token}/rate`, { delivery, service, comment }, { preserveScroll: true })}>
                {t('Send my rating')}
            </Button>
        </section>
    );
}

/** Pay what is still owed, online, once staff have checked the order. The money goes through the payment company's own page. */
function PayBox({ token, pay }: { token: string; pay: PayInfo }) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const checking = pay.why === 'We are still checking your order. You can pay when it is ready.';

    if (!pay.can && pay.paid_online <= 0 && !pay.practice && !pay.refund_due && !checking) {
        return null;
    }

    return (
        <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <h2 className="font-display text-lg font-bold">{t('Payment')}</h2>
            <InputError message={errors.order} />
            {pay.paid_online > 0 && <p className="tabular text-sm font-semibold text-emerald-800">{t('Paid online')}: {money(pay.paid_online)}</p>}
            {pay.practice && <p className="text-sm text-[var(--pt-muted)]">{t('A practice payment was made. No real money was taken.')}</p>}
            {pay.refund_due && <p className="text-sm font-semibold">{t('Your payment will be returned to you. We will tell you.')}</p>}
            {checking && <p className="text-sm text-[var(--pt-muted)]">{t(pay.why ?? '')}</p>}
            {pay.can && (
                <>
                    <p className="text-sm">{t('You can pay what is left now with bKash, Nagad, Rocket, a card or your bank. Or pay when it arrives.')}</p>
                    <Button onClick={() => router.post(`/orders/${token}/pay`, {})}>{t('Pay {amount} online', { amount: money(pay.amount) })}</Button>
                </>
            )}
        </section>
    );
}

export default function OrderPublic({ token, districts, order, pay }: Props) {
    const { t, money, num, dateTime } = useI18n();
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const status = (page.props.flash as { status?: string } | undefined)?.status;
    const live = order.status === 'placed' || order.status === 'verified';

    // The page follows staff as they check the order.
    useEffect(() => {
        if (!live) return;
        const timer = setInterval(() => router.reload({ only: ['order'] }), 8000);
        return () => clearInterval(timer);
    }, [live]);

    const steps = [
        { key: 'placed', label: t('Sent') },
        { key: 'verified', label: t('Checked') },
        { key: 'final', label: t('Done') },
    ];
    const at = order.status === 'placed' ? 0 : order.status === 'verified' ? 1 : order.status === 'final' ? 2 : -1;
    const checkLabel = (l: Line) =>
        l.check === 'full' ? t('Available') : l.check === 'partial' ? t('Only {n} available', { n: num(l.quantity) }) : l.check === 'unavailable' ? t('Not available') : t('Checking…');
    const ship = order.ship_to;

    return (
        <PublicLayout>
            <Head title={order.number ?? t('Your order')} />
            <div className="mx-auto max-w-3xl space-y-6">
                {status && <p className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-800">{status}</p>}
                <InputError message={errors.order} />
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold">{order.number ?? t('Your order')}</h1>
                    {order.placed_at && <p className="text-[var(--pt-muted)]">{dateTime(order.placed_at)}</p>}
                </header>

                {order.status === 'cancelled' ? (
                    <p role="status" className="rounded-2xl border border-red-500/40 bg-red-500/10 px-4 py-3 font-semibold">
                        {t('This order was cancelled.')}
                    </p>
                ) : (
                    <ol className="grid grid-cols-3 gap-2" aria-label={t('Order progress')}>
                        {steps.map((s, i) => (
                            <li key={s.key} className={`rounded-xl border px-3 py-2 text-center text-sm font-semibold ${i <= at ? 'border-[var(--pt-deep)] bg-[var(--pt-deep)] text-white' : 'text-[var(--pt-muted)]'}`}>
                                {s.label}
                            </li>
                        ))}
                    </ol>
                )}

                <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                    {order.lines.map((l) => (
                        <li key={l.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                            <div className="min-w-48 flex-1">
                                <p className="font-semibold">
                                    {l.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{l.strength}</span>
                                </p>
                                <p className="text-sm text-[var(--pt-muted)]">
                                    {num(l.requested)} {t(unitName(l.unit))} · {money(l.unit_price)}
                                </p>
                            </div>
                            {order.can_edit_items && order.status === 'placed' && (
                                <span className="flex items-center gap-1">
                                    <Input aria-label={t('Amount')} className="h-8 w-20" type="number" min="1" step="1" defaultValue={l.requested} onBlur={(e) => Number(e.target.value) > 0 && Number(e.target.value) !== l.requested && router.put(`/orders/${token}/lines/${l.id}`, { quantity: e.target.value }, { preserveScroll: true })} />
                                    {order.lines.length > 1 && (
                                        <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Take this off your order?')) && router.delete(`/orders/${token}/lines/${l.id}`, { preserveScroll: true })}>
                                            {t('Remove')}
                                        </Button>
                                    )}
                                </span>
                            )}
                            {order.status !== 'cancelled' && <span className={`text-sm font-semibold ${l.check === 'unavailable' ? 'text-red-700' : l.check === 'partial' ? 'text-amber-700' : ''}`}>{checkLabel(l)}</span>}
                            <span className="tabular w-24 text-right font-semibold">{l.check === 'unavailable' ? '—' : money(l.total)}</span>
                        </li>
                    ))}
                </ul>

                <div className="space-y-1 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <p className="flex justify-between text-sm">
                        <span>{t('Items')}</span>
                        <span className="tabular">{money(order.subtotal)}</span>
                    </p>
                    <p className="flex justify-between text-sm">
                        <span>{t('Delivery charge')}</span>
                        <span className="tabular">{money(order.delivery)}</span>
                    </p>
                    <p className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                        <span>{t('Total')}</span>
                        <span className="tabular">{money(order.total)}</span>
                    </p>
                    <p className="text-sm text-[var(--pt-muted)]">{t('You pay when it arrives.')}</p>
                </div>

                {ship && (
                    <section className="space-y-0.5 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                        <h2 className="font-display text-lg font-bold">{order.delivery_mode === 'collect' ? t('You will collect it from the shop') : t('Deliver to')}</h2>
                        <p>
                            {ship.name} · {ship.phone}
                            {ship.for_whom && ` · ${t('For')} ${ship.for_whom}`}
                        </p>
                        {order.delivery_mode !== 'collect' && (
                            <p>
                                {ship.address}, {ship.area}, {t(ship.district)}
                            </p>
                        )}
                        {ship.how_to_find && <p className="text-[var(--pt-muted)]">{ship.how_to_find}</p>}
                    </section>
                )}

                {order.own_delivery && order.code && (
                    <section role="status" className={`space-y-1 rounded-2xl border p-4 ${order.arrived ? 'border-emerald-600/40 bg-emerald-600/10' : 'border-[var(--pt-line)] bg-[var(--pt-surface)]'}`}>
                        {order.arrived && <p className="font-semibold">{t('Our delivery person is at your door.')}</p>}
                        <p className="text-sm">{t('Tell this code to our delivery person when you get your order. Do not tell it to anyone else.')}</p>
                        <p className="tabular font-display text-4xl font-extrabold tracking-[0.3em]">{order.code}</p>
                    </section>
                )}

                {order.can_rate && <RateDelivery token={token} />}

                <PayBox token={token} pay={pay} />

                <DeliveryStatus courierStatus={order.courier_status} sentAt={order.sent_at} tracking={order.tracking} extra={order.delivery_extra} delivered={order.delivered} notReceived={order.not_received} url={`/orders/${token}`} />

                {order.can_change_address && ship && <ChangeAddress token={token} ship={ship} districts={districts} />}
                {order.can_add_note && <AddToOrder url={`/orders/${token}/note`} sent={order} />}

                {order.status === 'cancelled' && order.delivery_refund > 0 && (
                    <p className="rounded-xl border p-3 text-sm">{t('Half of the delivery charge you paid goes back to you ({n}). The other half stays for the handling.', { n: money(order.delivery_refund) })}</p>
                )}
                {order.advance_needed && order.status !== 'cancelled' && (
                    <p role="note" className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                        {t('This order is big, so the delivery charge is paid first. Please send the delivery charge and call us. We send the goods once it is received.')}
                    </p>
                )}
                {order.cancel_minutes > 0 && (
                    <div className="flex flex-wrap items-center gap-3 rounded-2xl border p-4">
                        <p className="flex-1 text-sm">{t('You can cancel this order yourself for {n} more minutes.', { n: num(order.cancel_minutes) })}</p>
                        <Button variant="outline" onClick={() => confirm(t('Cancel this order?')) && router.post(`/orders/${token}/cancel`)}>
                            {t('Cancel order')}
                        </Button>
                    </div>
                )}

                <Link href={`/support/new?order=${token}`} className="inline-block text-sm font-semibold underline underline-offset-4">
                    {t('Get help with this order')}
                </Link>

                {order.number && order.status === 'final' && (
                    <div className="flex flex-wrap gap-x-5 gap-y-2 text-sm font-semibold">
                        <Link href={`/memo/t/${token}`} className="underline underline-offset-4">
                            {t('Open the memo')}
                        </Link>
                        {order.mine ? (
                            <Link href="/my-orders/returns" className="underline underline-offset-4">
                                {t('Ask to return something')}
                            </Link>
                        ) : (
                            <span className="font-normal text-[var(--pt-muted)]">{t('To ask for a return, log in to the account you ordered with. If you ordered without one, call us.')}</span>
                        )}
                    </div>
                )}

                <p className="text-sm text-[var(--pt-muted)]">{t('Keep this page. The link is private. Anyone who has it can see this order.')}</p>
            </div>
        </PublicLayout>
    );
}
