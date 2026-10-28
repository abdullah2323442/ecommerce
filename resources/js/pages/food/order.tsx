import { trackOnce } from '@/lib/track';
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
    status: 'pending' | 'confirmed' | 'packing' | 'out' | 'ready' | 'delivered' | 'cancelled';
    mode: 'delivery' | 'eat_there';
    restaurant: string;
    restaurant_phone: string | null;
    lines: { name: string; price: number; quantity: number; note: string | null; ingredients: { base: { name: string; price: number }; items: { name: string; price: number }[] } | null }[];
    custom: boolean;
    subtotal: number;
    delivery_charge: number;
    distance_km: number | null;
    distance_by_road: boolean;
    charge_open: boolean;
    total: number;
    advance_due: number;
    advance_paid: number;
    minutes: number | null;
    ready_at: string | null;
    seconds_left: number | null;
    ship_to: { name?: string; phone?: string; area?: string; address?: string };
    eat_time: string | null;
    placed_at: string;
    cancel_reason: string | null;
    refund: number;
    ask_cancel: boolean;
    can_cancel: boolean;
    cancel_no_refund: boolean;
    can_complain: boolean;
    complaint: string | null;
    complaint_reply: string | null;
}

/** The timer on the buyer's screen runs from the minutes the restaurant promised (spec ch 17). */
function Timer({ readyAt }: { readyAt: string }) {
    const { t, num } = useI18n();
    const [left, setLeft] = useState(() => Math.max(0, Math.round((new Date(readyAt).getTime() - Date.now()) / 1000)));

    useEffect(() => {
        const id = window.setInterval(() => setLeft(Math.max(0, Math.round((new Date(readyAt).getTime() - Date.now()) / 1000))), 1000);

        return () => window.clearInterval(id);
    }, [readyAt]);

    const m = Math.floor(left / 60);
    const s = left % 60;

    return (
        <p className="tabular font-display text-4xl font-extrabold" role="timer" aria-live="off">
            {left > 0 ? `${num(m)}:${String(s).padStart(2, '0').replace(/\d/g, (d) => num(Number(d)))}` : t('Any moment now')}
        </p>
    );
}

export default function FoodOrder({ order }: { order: Order }) {
    useEffect(() => trackOnce(order.number, 'Purchase', { transaction: order.number, value: order.total }), [order.number, order.total]);
    const { t, money, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [text, setText] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const open = !['delivered', 'cancelled'].includes(order.status);
    const steps = order.mode === 'delivery' ? ['pending', 'confirmed', 'packing', 'out', 'delivered'] : ['pending', 'confirmed', 'ready', 'delivered'];
    const label = (s: string) => t(s === 'pending' ? 'Pending' : s === 'confirmed' ? 'Confirmed: cooking' : s === 'packing' ? 'Packing' : s === 'out' ? 'Out for delivery' : s === 'ready' ? 'Ready' : s === 'delivered' ? (order.mode === 'delivery' ? 'Delivered' : 'Served') : 'Cancelled');
    const at = steps.indexOf(order.status);

    // The page asks again every little while: the restaurant moves the order along on its own screen.
    useEffect(() => {
        if (!open) {
            return;
        }
        const id = window.setInterval(() => document.visibilityState === 'visible' && router.reload(), 15000);

        return () => window.clearInterval(id);
    }, [open]);

    const post = (path: string, body: Record<string, unknown> = {}, multipart = false) => router.post(`/food/orders/${order.token}/${path}`, body as Record<string, string>, { preserveScroll: true, forceFormData: multipart });

    return (
        <PublicLayout>
            <Head title={order.number} />
            <div className="mx-auto max-w-2xl space-y-6">
                <Link href="/food" className="text-sm underline underline-offset-4">
                    {t('Food')}
                </Link>
                <div>
                    <h1 className="font-display text-3xl font-extrabold">{order.restaurant}</h1>
                    <p className="text-sm text-[var(--pt-muted)]">
                        {order.number} · {dateTime(order.placed_at)}
                    </p>
                </div>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <a href={`/receipt/food/${order.token}`} target="_blank" rel="noreferrer" className="inline-block text-sm font-semibold underline underline-offset-4">
                    {t('Print the receipt')}
                </a>
                <InputError message={errors.order ?? errors.text ?? errors.photo} />

                {order.status === 'cancelled' ? (
                    <p className="rounded-[1.5rem] border border-dashed p-4 text-sm">
                        {t('This order was cancelled.')} {order.cancel_reason} {order.refund > 0 ? t('Your advance of {amount} is refunded.', { amount: money(order.refund) }) : order.advance_paid > 0 ? t('The advance is not refunded.') : ''}
                    </p>
                ) : (
                    <ol className={`grid gap-2 text-center text-xs ${steps.length === 5 ? 'grid-cols-5' : 'grid-cols-4'}`}>
                        {steps.map((s, i) => (
                            <li key={s} className={`rounded-[1.25rem] border px-1 py-2 ${i <= at ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)] text-[var(--pt-muted)]'}`}>
                                {label(s)}
                            </li>
                        ))}
                    </ol>
                )}

                {order.custom && order.status === 'pending' && <p className="rounded-md bg-info/10 p-3 text-sm text-info">{t('Custom food takes a little longer to make. The restaurant will tell you how long.')}</p>}

                {order.status === 'confirmed' && order.ready_at && (
                    <section className="space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-center">
                        <p className="text-sm text-[var(--pt-muted)]">{t('Cooking. It will be ready in about')}</p>
                        <Timer readyAt={order.ready_at} />
                    </section>
                )}

                {order.ask_cancel && (
                    <section role="alert" className="space-y-2 rounded-[1.5rem] border border-warning/40 bg-warning/10 p-4">
                        <p className="font-semibold">{t('Your order has not been approved yet. Do you want to cancel it?')}</p>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" onClick={() => post('cancel', { reason: t('The restaurant did not confirm') })}>
                                {t('Yes, cancel it')}
                            </Button>
                            {order.restaurant_phone && <span className="self-center text-sm">{t('Or wait a little longer.')}</span>}
                        </div>
                    </section>
                )}

                {order.advance_due > 0 && order.status === 'pending' && <p className="rounded-md bg-warning/10 p-3 text-sm text-warning">{t('The restaurant needs an advance of {amount} before it starts cooking.', { amount: money(order.advance_due) })}</p>}

                <dl className="tabular space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                    {order.lines.map((l, i) => (
                        <div key={i}>
                            <div className="flex justify-between">
                                <dt>
                                    {num(l.quantity)} × {l.name}
                                </dt>
                                <dd>{money(l.price * l.quantity)}</dd>
                            </div>
                            {l.ingredients && (
                                <ul className="ms-4 text-xs text-[var(--pt-muted)]">
                                    <li className="flex justify-between">
                                        <span>{l.ingredients.base.name}</span>
                                        <span>{money(l.ingredients.base.price)}</span>
                                    </li>
                                    {l.ingredients.items.map((x, k) => (
                                        <li key={k} className="flex justify-between">
                                            <span>+ {x.name}</span>
                                            <span>{money(x.price)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {l.note && <p className="text-xs text-[var(--pt-muted)]">{l.note}</p>}
                        </div>
                    ))}
                    {order.mode === 'delivery' && (
                        <div className="flex justify-between">
                            <dt>{t('Delivery charge')}</dt>
                            <dd>
                                {order.charge_open ? t('The restaurant will tell you') : money(order.delivery_charge)}
                                {order.distance_km !== null && <span className="text-[var(--pt-muted)]"> · {order.distance_by_road ? t('{km} km', { km: num(order.distance_km) }) : t('{km} km (estimate)', { km: num(order.distance_km) })}</span>}
                            </dd>
                        </div>
                    )}
                    <div className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                        <dt>{t('Total')}</dt>
                        <dd>{money(order.total)}</dd>
                    </div>
                    <p className="pt-1 text-xs text-[var(--pt-muted)]">{order.advance_paid > 0 ? t('Advance received: {amount}', { amount: money(order.advance_paid) }) : t('You pay when it arrives.')}</p>
                </dl>

                <p className="text-sm">
                    {order.mode === 'delivery' ? (
                        <>
                            <strong>{t('Delivered to')}:</strong> {order.ship_to.name}, {order.ship_to.phone}, {order.ship_to.address}, {order.ship_to.area}
                        </>
                    ) : (
                        <>
                            <strong>{t("I'll eat there")}</strong> {order.eat_time && `· ${order.eat_time}`}
                        </>
                    )}
                </p>

                {order.can_cancel && order.status !== 'pending' && (
                    <Button variant="outline" onClick={() => window.confirm(order.cancel_no_refund ? t('Cooking has started. If you cancel, the advance is not refunded. Cancel anyway?') : t('Cancel this order?')) && post('cancel', { reason: '' })}>
                        {t('Cancel this order')}
                    </Button>
                )}
                {order.can_cancel && order.status === 'pending' && !order.ask_cancel && (
                    <Button variant="outline" onClick={() => window.confirm(t('Cancel this order?')) && post('cancel', { reason: '' })}>
                        {t('Cancel this order')}
                    </Button>
                )}

                {order.complaint && (
                    <p className="rounded-[1.5rem] border p-4 text-sm">
                        <strong>{t('Your complaint')}:</strong> {order.complaint}
                        <span className="block text-[var(--pt-muted)]">{order.complaint_reply ?? t('The restaurant and we have it. We will look at it.')}</span>
                    </p>
                )}
                {order.can_complain && (
                    <section className="space-y-2 rounded-[1.5rem] border p-4">
                        <p className="font-semibold">{t('Something wrong with the food?')}</p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Input aria-label={t('What is wrong?')} className="h-9 min-w-56 flex-1" placeholder={t('What is wrong?')} value={text} onChange={(e) => setText(e.target.value)} />
                            <input type="file" accept="image/*" aria-label={t('Photo of the food')} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} className="text-sm" />
                            <Button variant="outline" disabled={text.trim().length < 3 || !photo} onClick={() => post('complain', { text, photo }, true)}>
                                {t('Send with the photo')}
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
