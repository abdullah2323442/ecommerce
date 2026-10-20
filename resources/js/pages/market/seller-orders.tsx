import SellerBrand from '@/components/seller-brand';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { unitLabel } from './index';

interface Order {
    id: number;
    number: string;
    title: string;
    variant: string | null;
    pickup: boolean;
    preorder: boolean;
    unit: string;
    quantity: number;
    status: 'placed' | 'accepted' | 'shipped' | 'delivered' | 'cancelled' | 'not_received' | 'returned';
    note: string | null;
    tracking: string | null;
    placed_at: string;
    delivery_charge: number;
    you_get: number;
    unseen: boolean;
    phone: string | null;
    address: string | null;
    how_to_find: string | null;
    cancel_reason: string | null;
    cases: { id: number; kind: 'complaint' | 'return'; reason: string; status: string; seller_note: string | null; our_note: string | null }[];
}

interface SellerRecord {
    sold: number;
    returned: number;
    returned_orders: number;
    complaints: number;
    reasons: string[];
}

function CaseBox({ c }: { c: Order['cases'][number] }) {
    const { t } = useI18n();
    const [note, setNote] = useState('');
    const answer = (action: string) => router.post(`/wholesale/sell/cases/${c.id}/answer`, { action, note }, { preserveScroll: true });
    const state = (s: string) => t(s === 'open' ? 'Waiting for you' : s === 'solved' ? 'You solved it' : s === 'returned' ? 'Sent back to you' : s === 'escalated' ? 'With us' : 'Closed');

    return (
        <div className="space-y-2 rounded-[1.25rem] border border-warning/40 bg-warning/5 p-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold">{c.kind === 'return' ? t('The buyer wants to send it back') : t('The buyer has a complaint')}</p>
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">{state(c.status)}</span>
            </div>
            <p>{c.reason}</p>
            {c.seller_note && <p className="text-[var(--pt-muted)]">{c.seller_note}</p>}
            {c.our_note && <p className="rounded-md bg-warning/10 p-2">{t('Our note')}: {c.our_note}</p>}
            {c.status === 'open' && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('Reply')} className="h-8 w-64" placeholder={t('A few words for the buyer')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button size="sm" disabled={note.trim().length < 3} onClick={() => answer('solved')}>
                        {t('I solved it')}
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => answer('return')}>
                        {t('Take it back')}
                    </Button>
                    <Button size="sm" variant="ghost" disabled={note.trim().length < 3} onClick={() => answer('cannot')}>
                        {t('I cannot solve it')}
                    </Button>
                </div>
            )}
        </div>
    );
}

function Row({ o }: { o: Order }) {
    const { t, money, num, dateTime } = useI18n();
    const [tracking, setTracking] = useState('');
    const [reason, setReason] = useState('');
    const [cancelling, setCancelling] = useState(false);
    const state = (s: Order['status']) => t(s === 'placed' ? 'New' : s === 'accepted' ? 'Accepted' : s === 'shipped' ? 'On the way' : s === 'delivered' ? 'Done' : s === 'not_received' ? 'Buyer says not received' : s === 'returned' ? 'Sent back to you' : 'Cancelled');
    const post = (path: string, body: Record<string, string> = {}) => router.post(`/wholesale/sell/orders/${o.id}/${path}`, body, { preserveScroll: true });
    const open = o.status === 'placed' || o.status === 'accepted';

    return (
        <li className={`space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 ${o.unseen ? 'border-[var(--pt-amber)]' : ''}`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold">
                    {o.title}{o.variant ? ` (${o.variant})` : ''} · {num(o.quantity)} {unitLabel(o.unit)}
                </p>
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">{state(o.status)}</span>
            </div>
            <p className="text-xs text-[var(--pt-muted)]">
                {o.number} · {dateTime(o.placed_at)}
            </p>
            <p className="tabular text-sm">
                {t('You get')}: <strong>{money(o.you_get)}</strong> <span className="text-[var(--pt-muted)]">({t('including the delivery charge')})</span>
            </p>
            <div className="rounded-[1.25rem] bg-muted/50 p-3 text-sm">
                <p>
                    <strong>{t('Phone')}:</strong> {o.phone}
                </p>
                <p>
                    <strong>{t('Address')}:</strong> {o.address}
                </p>
                {o.how_to_find && <p>{o.how_to_find}</p>}
                {o.phone === '***' && <p className="mt-1 text-xs text-[var(--pt-muted)]">{t('The buyer details are hidden once an order is finished.')}</p>}
            </div>
            {o.preorder && <p className="rounded-md bg-warning/10 px-3 py-1.5 text-sm font-semibold">{t('Pre-order: you did not have this in stock. Send it when you have it again.')}</p>}
            {o.note && <p className="text-sm">{o.note}</p>}
            {o.tracking && <p className="text-sm">{t('Tracking')}: {o.tracking}</p>}
            {o.status === 'cancelled' && o.cancel_reason && <p className="text-sm">{o.cancel_reason}</p>}
            {o.cases.map((c) => (
                <CaseBox key={c.id} c={c} />
            ))}

            {open && (
                <div className="flex flex-wrap items-center gap-2">
                    {o.status === 'placed' && <Button size="sm" onClick={() => post('accept')}>{t('Accept')}</Button>}
                    {o.pickup ? (
                        o.status === 'accepted' && (
                            <Button size="sm" variant="outline" onClick={() => post('collected')}>
                                {t('Handed over')}
                            </Button>
                        )
                    ) : (
                        <>
                            <Input aria-label={t('Tracking note')} className="h-8 w-48" placeholder={t('Tracking note (optional)')} value={tracking} onChange={(e) => setTracking(e.target.value)} />
                            <Button size="sm" variant="outline" onClick={() => post('ship', { tracking })}>
                                {t('I have sent it')}
                            </Button>
                        </>
                    )}
                    {cancelling ? (
                        <>
                            <Input aria-label={t('Reason')} className="h-8 w-48" placeholder={t('Why (the buyer is told)')} value={reason} onChange={(e) => setReason(e.target.value)} />
                            <Button size="sm" variant="destructive" disabled={reason.trim().length < 3} onClick={() => post('cancel', { reason })}>
                                {t('Cancel the order')}
                            </Button>
                        </>
                    ) : (
                        <Button size="sm" variant="ghost" onClick={() => setCancelling(true)}>
                            {t('Cancel')}
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

export default function SellerOrders({ orders, paused, record }: { orders: Order[]; paused: boolean; record: SellerRecord }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    return (
        <PublicLayout>
            <Head title={t('Your orders')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <SellerBrand />
                    <h1 className="font-display text-3xl font-extrabold">{t('Orders for your products')}</h1>
                    <Link href="/wholesale/sell" className="text-sm font-semibold underline underline-offset-4">
                        {t('Your listings')}
                    </Link>
                </div>
                {paused && <p className="rounded-[1.5rem] border border-warning/40 bg-warning/10 px-4 py-3 text-sm">{t('You are on holiday. New orders are off.')}</p>}
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <InputError message={errors.order ?? errors.reason} />
                <div className="space-y-1 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                    <p className="font-semibold">{t('Your record')}</p>
                    <p className="tabular">
                        {t('Sold')}: <strong>{num(record.sold)}</strong> · {t('Sent back')}: <strong>{num(record.returned)}</strong> ({t('{n} orders', { n: num(record.returned_orders) })}) · {t('Complaints')}: <strong>{num(record.complaints)}</strong>
                    </p>
                    {record.reasons.length > 0 && (
                        <ul className="list-disc ps-5 text-[var(--pt-muted)]">
                            {record.reasons.map((r, i) => (
                                <li key={i}>{r}</li>
                            ))}
                        </ul>
                    )}
                </div>
                <p className="text-sm text-[var(--pt-muted)]">{t('Pack and send it yourself. The buyer pays you when it arrives. Do not put cards, leaflets or your page address in the package.')}</p>
                {orders.length === 0 ? (
                    <p className="rounded-[1.5rem] border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('No orders yet.')}</p>
                ) : (
                    <ul className="space-y-3">
                        {orders.map((o) => (
                            <Row key={o.id} o={o} />
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
