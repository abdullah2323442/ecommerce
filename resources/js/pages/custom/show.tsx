import CustomChat, { type ChatData, type MeasureProps } from '@/components/custom-chat';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { customState } from './index';

interface Item {
    label: string;
    kind?: string;
    garment?: string;
    quantity: number;
    unit: string;
    values: Record<string, number>;
    measured_on?: string;
}

interface Order {
    token: string;
    number: string;
    kind: 'wood' | 'clothing';
    status: string;
    material: string | null;
    fabric_by: 'shop' | 'buyer' | null;
    items: Item[];
    details: Record<string, string | boolean> | null;
    note: string | null;
    urgent: boolean;
    has_photo: boolean;
    price: number | null;
    urgent_fee: number;
    advance_percent: number | null;
    advance_due: number;
    advance_paid: number;
    days: number | null;
    due_on: string | null;
    memo_note: string | null;
    placed_at: string;
    cancel_reason: string | null;
    refund: number;
    complaint: string | null;
    complaint_status: string | null;
    complaint_note: string | null;
    progress: number;
    waiting: boolean;
    late: boolean;
    can_confirm: boolean;
    can_cancel: 'refund' | 'no_refund' | null;
    can_complain: boolean;
    history: { kind: string; note: string | null; at: string }[];
}

/** One made-to-order job. The memo is a total: what the wood or wages cost us is never here. */
export default function CustomShow({ order, chat, measure }: { order: Order; chat: ChatData; measure: MeasureProps | null }) {
    const { t, money, num, date, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [checked, setChecked] = useState(false);
    const [complaint, setComplaint] = useState('');
    const [reason, setReason] = useState('');
    const post = (path: string, body: Record<string, string | number> = {}) => router.post(`/custom/${order.token}/${path}`, body, { preserveScroll: true });

    return (
        <PublicLayout>
            <Head title={order.number} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href="/custom" className="text-sm underline underline-offset-4">
                    {t('Made to order')}
                </Link>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h1 className="font-display text-3xl font-extrabold">
                        {order.number} · {order.kind === 'wood' ? t('Wood') : t('Clothing')}
                    </h1>
                    <span className="rounded-full bg-muted px-3 py-1 text-sm font-semibold">{customState(t, order.status)}</span>
                </div>
                <p className="text-sm text-[var(--pt-muted)]">{dateTime(order.placed_at)}</p>
                <a href={`/receipt/custom/${order.token}`} target="_blank" rel="noreferrer" className="inline-block text-sm font-semibold underline underline-offset-4">
                    {t('Print the receipt')}
                </a>
                {flash && <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">{flash}</p>}
                <InputError message={errors.order} />

                {order.status === 'cancelled' && (
                    <p className="rounded-2xl border border-dashed p-4 text-sm">
                        {t('This job was cancelled.')} {order.cancel_reason} {order.refund > 0 ? t('Your advance of {amount} is refunded.', { amount: money(order.refund) }) : t('The advance is not refunded.')}
                    </p>
                )}

                {order.price !== null && (
                    <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <h2 className="font-display text-xl font-bold">{t('Our price')}</h2>
                        <dl className="tabular space-y-1 text-sm">
                            {order.urgent_fee > 0 && (
                                <div className="flex justify-between">
                                    <dt>{t('Urgent charge')}</dt>
                                    <dd>{money(order.urgent_fee)}</dd>
                                </div>
                            )}
                            <div className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                                <dt>{t('Total')}</dt>
                                <dd>{money(order.price)}</dd>
                            </div>
                            {order.advance_percent !== null && (
                                <div className="flex justify-between">
                                    <dt>{t('Advance ({n}%)', { n: num(order.advance_percent) })}</dt>
                                    <dd>{money((order.price * order.advance_percent) / 100)}</dd>
                                </div>
                            )}
                            {order.advance_paid > 0 && (
                                <div className="flex justify-between">
                                    <dt>{t('Advance received')}</dt>
                                    <dd>{money(order.advance_paid)}</dd>
                                </div>
                            )}
                        </dl>
                        {order.days && <p className="text-sm">{t('It takes about {n} days.', { n: num(order.days) })} {order.due_on && t('Due on {date}.', { date: date(order.due_on) })}</p>}
                        {order.memo_note && <p className="text-sm">{order.memo_note}</p>}
                        <p className="text-xs text-[var(--pt-muted)]">{t('Delivery is by arrangement. Returns do not apply to made-to-order work. If you cancel after work has started, the advance is not refunded.')}</p>
                    </section>
                )}

                <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                    <h2 className="font-display text-xl font-bold">{t('What you asked for')}</h2>
                    {order.material && (
                        <p>
                            {order.kind === 'wood' ? t('Wood') : t('Fabric')}: <strong>{order.material}</strong>
                        </p>
                    )}
                    {order.fabric_by === 'buyer' && <p>{t('You bring your own fabric.')}</p>}
                    {order.items.map((it, i) => (
                        <div key={i} className="rounded-xl bg-muted/50 p-3">
                            <p className="font-semibold">
                                {it.label || (order.kind === 'wood' ? t('Size') : t('Person {n}', { n: num(i + 1) }))}
                                {it.garment && ` · ${it.garment}`}
                                {order.kind === 'clothing' && ` × ${num(it.quantity)}`}
                            </p>
                            <p className="tabular">
                                {Object.entries(it.values).map(([k, v]) => (
                                    <span key={k} className="me-3 inline-block">
                                        {t(k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()))}: <strong>{num(v)}</strong>
                                    </span>
                                ))}
                                ({t(it.unit === 'inch' ? 'Inch' : it.unit === 'foot' ? 'Foot' : 'Centimetre')})
                            </p>
                            {it.measured_on && <p className="text-xs text-[var(--pt-muted)]">{t('Measured on {date}', { date: date(it.measured_on) })}</p>}
                        </div>
                    ))}
                    {order.details && Object.keys(order.details).length > 0 && (
                        <p>
                            {Object.entries(order.details).map(([k, v]) => (
                                <span key={k} className="me-3 inline-block">
                                    {t(k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()))}
                                    {typeof v === 'boolean' ? '' : `: ${v}`}
                                </span>
                            ))}
                        </p>
                    )}
                    {order.note && <p>{order.note}</p>}
                    {order.urgent && <p className="font-semibold">{t('Urgent')}</p>}
                    {order.has_photo && <img src={`/custom/${order.token}/photo`} alt="" className="max-h-64 rounded-xl border object-cover" />}
                </section>

                {['making', 'trial'].includes(order.status) && (
                    <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <p className="font-display text-xl font-bold">{t('Your job is {n}% done', { n: num(order.progress) })}</p>
                        <div className="h-3 rounded-full bg-muted">
                            <div className="h-3 rounded-full bg-[var(--pt-ink)]" style={{ width: `${Math.min(100, order.progress)}%` }} />
                        </div>
                        {order.waiting && <p className="text-sm font-semibold">{t('Waiting for materials. The delay is not on your side.')}</p>}
                        {order.late && <p className="text-sm font-semibold">{t('This is taking longer than promised. We are sorry, and we are looking into it.')}</p>}
                    </section>
                )}

                {order.can_confirm && (
                    <section className="space-y-3 rounded-2xl border border-[var(--pt-ink)] p-4">
                        <p className="font-semibold">{t('Check the measurements above. Once you confirm, they are your responsibility.')}</p>
                        <label className="flex items-start gap-2 text-sm">
                            <input type="checkbox" className="mt-1" checked={checked} onChange={(e) => setChecked(e.target.checked)} /> {t('I have checked the measurements and I agree to the price.')}
                        </label>
                        <Button disabled={!checked} onClick={() => post('confirm', { checked: 1 })}>
                            {t('Confirm')}
                        </Button>
                    </section>
                )}

                {order.can_cancel && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Input aria-label={t('Reason')} className="h-9 w-64" placeholder={t('Why (optional)')} value={reason} onChange={(e) => setReason(e.target.value)} />
                        <Button
                            variant="outline"
                            onClick={() => window.confirm(order.can_cancel === 'refund' ? t('Cancel this job? Your advance comes back.') : t('Work has started. If you cancel, the advance is not refunded. Cancel anyway?')) && post('cancel', { reason })}
                        >
                            {t('Cancel this job')}
                        </Button>
                    </div>
                )}

                {order.complaint && (
                    <p className="rounded-2xl border p-4 text-sm">
                        <strong>{t('Work not done right')}:</strong> {order.complaint}
                        <span className="block text-[var(--pt-muted)]">
                            {order.complaint_status === 'open' ? t('We are looking at it.') : order.complaint_status === 'fixed' ? t('We will put it right.') : order.complaint_status === 'refunded' ? t('We have refunded part of the price.') : t('This was made to the measurements you confirmed.')}
                            {order.complaint_note && ` ${order.complaint_note}`}
                        </span>
                    </p>
                )}
                {order.can_complain && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Input aria-label={t('What is wrong?')} className="h-9 min-w-64 flex-1" placeholder={t('What is wrong?')} value={complaint} onChange={(e) => setComplaint(e.target.value)} />
                        <Button variant="outline" disabled={complaint.trim().length < 3} onClick={() => post('complain', { text: complaint })}>
                            {t('Work not done right')}
                        </Button>
                    </div>
                )}

                <CustomChat
                    chat={chat}
                    postUrl={`/custom/${order.token}/chat`}
                    fileUrl={(id) => `/custom/${order.token}/chat/${id}/file`}
                    measure={measure}
                    measureUrl={`/custom/${order.token}/chat/measurements`}
                    answerUrl={(id) => `/custom/${order.token}/chat/${id}/offer`}
                />

                <section className="space-y-1">
                    <h2 className="font-display text-xl font-bold">{t('What happened')}</h2>
                    <ul className="space-y-1 text-sm">
                        {order.history.map((h, i) => (
                            <li key={i}>
                                <span className="text-[var(--pt-muted)]">{dateTime(h.at)}</span> · {h.note ? t(h.note) : h.kind}
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </PublicLayout>
    );
}
