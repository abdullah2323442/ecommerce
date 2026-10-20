import AddToOrder from '@/components/add-to-order';
import { usePortal } from '@/pages/paikari/index';
import DeliveryStatus from '@/components/delivery-status';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { Search } from './index';

interface Line {
    id: number;
    name: string;
    strength: string | null;
    unit: string;
    quantity: number;
    requested: number;
    offer?: boolean;
    check: 'pending' | 'full' | 'partial' | 'unavailable' | null;
    unit_price: number;
    total: number;
}

interface Props {
    order: { id: number; cancel_requested: boolean; status: 'draft' | 'placed' | 'verified' | 'final' | 'cancelled'; number: string | null; total: number; paid_amount: number; delivery_mode: string | null; sent_at: string | null; tracking: string | null; delivery_extra: number; delivered: boolean; not_received: boolean; wanted_time: string | null; has_voice: boolean; has_photo: boolean; can_note: boolean; lines: Line[] };
}

export default function Order({ order }: Props) {
    const { base } = usePortal();
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const live = order.status === 'placed' || order.status === 'verified';

    // The buyer sees staff checking the order as it happens: the page refreshes itself while it is open.
    useEffect(() => {
        if (!live) return;
        const timer = setInterval(() => router.reload({ only: ['order'] }), 6000);
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

    return (
        <PublicLayout>
            <Head title={order.number ?? t('Order')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href={base} className="text-sm underline underline-offset-4">
                    {t('Back to orders')}
                </Link>
                <h1 className="font-display text-3xl font-extrabold">{order.number ? `${t('Memo')} ${order.number}` : t('Your order')}</h1>

                {order.status === 'cancelled' ? (
                    <p className="rounded-2xl border border-dashed p-4 text-sm">{t('This order was cancelled.')}</p>
                ) : (
                    <ol className="flex gap-2" aria-label={t('Progress')}>
                        {steps.map((s, i) => (
                            <li key={s.key} className={`flex-1 rounded-full px-3 py-1.5 text-center text-sm font-semibold ${i <= at ? 'bg-[var(--pt-deep)] text-white' : 'bg-[var(--pt-sage)] text-[var(--pt-muted)]'}`}>
                                {s.label}
                            </li>
                        ))}
                    </ol>
                )}

                <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                    {order.lines.map((l) => (
                        <li key={l.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                            <div className="min-w-48 flex-1">
                                <p className="font-medium">
                                    {l.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{l.strength}</span>
                                </p>
                                <p className="text-xs text-[var(--pt-muted)]">
                                    {l.offer && <strong className="mr-1 text-[var(--pt-deep)]">{t('OFFER')}</strong>}
                                    {t(l.unit === 'box' ? 'Box' : l.unit === 'strip' ? 'Strip' : 'Piece')} · {money(l.unit_price)}
                                </p>
                            </div>
                            <p className="tabular text-sm">
                                {num(l.requested)}
                                {order.status !== 'draft' && l.check && l.check !== 'pending' && l.quantity !== l.requested && ` → ${num(l.quantity)}`}
                            </p>
                            {order.status !== 'draft' && (
                                <p className={`w-40 text-sm font-semibold ${l.check === 'unavailable' ? 'text-danger' : l.check === 'partial' ? 'text-warning' : l.check === 'full' ? 'text-success' : 'text-[var(--pt-muted)]'}`}>{checkLabel(l)}</p>
                            )}
                            <p className="tabular w-24 text-right font-semibold">{money(l.total)}</p>
                        </li>
                    ))}
                </ul>

                <p className="flex justify-between font-display text-xl font-bold">
                    <span>{t('Total')}</span>
                    <span className="tabular">{money(order.total)}</span>
                </p>
                <DeliveryStatus sentAt={order.sent_at} tracking={order.tracking} extra={order.delivery_extra} delivered={order.delivered} notReceived={order.not_received} url={`${base}/orders/${order.id}`} />

                {(order.status === 'placed' || order.status === 'verified') && (
                    <a href={`/memo/${order.id}?mrp=1`} target="_blank" rel="noreferrer" className="inline-block text-sm font-semibold underline underline-offset-4">
                        {t('Preliminary memo at printed prices')}
                    </a>
                )}
                {order.status === 'final' && (
                    <a href={`/memo/${order.id}`} target="_blank" rel="noreferrer" className="inline-block text-sm font-semibold underline underline-offset-4">
                        {t('Open the memo')}
                    </a>
                )}
                {order.status === 'final' && (
                    <p className={`text-sm font-semibold ${order.paid_amount >= order.total ? 'text-success' : 'text-warning'}`}>
                        {order.paid_amount >= order.total ? t('Paid') : t('Due {amount}', { amount: money(order.total - order.paid_amount) })}
                    </p>
                )}
                <InputError message={errors.order} />
                {order.can_note && <AddToOrder url={`${base}/orders/${order.id}/note`} sent={order} />}
                {(order.status === 'placed' || order.status === 'verified') && (
                    <div className="space-y-1">
                        <p className="text-sm text-[var(--pt-muted)]">{t('Need more? New items are checked the same way.')}</p>
                        <Search url={`${base}/orders/${order.id}/add`} saveable={false} />
                    </div>
                )}
                {(order.status === 'placed' || order.status === 'verified') && (
                    order.cancel_requested ? (
                        <p className="text-sm font-semibold text-warning">{t('Cancellation requested. Waiting for our staff.')}</p>
                    ) : (
                    <Button variant="outline" onClick={() => confirm(t('Cancel this order?')) && router.post(`${base}/orders/${order.id}/cancel`)}>
                        {t('Cancel order')}
                    </Button>
                    )
                )}
            </div>
        </PublicLayout>
    );
}
