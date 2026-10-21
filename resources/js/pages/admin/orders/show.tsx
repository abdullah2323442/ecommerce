import ControlledDetails, { type ControlledItem } from '@/components/admin/controlled-details';
import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { postJson } from '@/lib/post-json';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Line {
    id: number;
    name: string;
    strength: string | null;
    company: string | null;
    cold: boolean;
    unit: string;
    requested: number;
    quantity: number;
    check: 'pending' | 'full' | 'partial' | 'unavailable' | null;
    checked_by: string | null;
    unit_price: number;
    offer?: boolean;
    offer_delivery?: number | null;
}

interface ShipTo {
    name: string;
    phone: string;
    district: string;
    area: string;
    address: string;
    how_to_find?: string | null;
    for_whom?: string | null;
    lat?: number | null;
    lng?: number | null;
}

interface Props {
    controlled: ControlledItem[];
    controlledDefaults: { buyer_name: string | null; buyer_phone: string | null } | null;
    approvals: { id: number; kind: string; line_id: number | null; value: number; status: string }[];
    order: { wanted_time: string | null; has_voice: boolean; has_photo: boolean; id: number; status: string; cancel_requested: boolean; flagged: boolean; late_cancellations: number; customer: string | null; phone: string | null; delivery_mode: string | null; note: string | null; ship_to: ShipTo | null; payment_terms: string | null; public: boolean; working: string | null; trade: boolean; courier_terms: string | null; courier_cost: number | null; cartons: number | null; advance_needed: boolean; advance_at: string | null; delivery_refund: number; people: { id: number; name: string }[]; delivery_person: string | null; arrived_at: string | null; courier_id: number | null; courier_panel: { provider: string; name: string; booked: boolean; consignment: string | null; status: string | null; status_label: string | null; status_at: string | null; tracking: string | null; blocked: string | null; collect: number; places?: boolean } | null; sent_at: string | null; tracking: string | null; delivery_extra: number; delivered_at: string | null; delivered_by: string | null; not_received: boolean; total: number; subtotal: number; delivery_charge: number; free_over: number; lines: Line[] };
}

function LineRow({ orderId, line, open, approvals }: { orderId: number; line: Line; open: boolean; approvals: Props['approvals'] }) {
    const errors = usePage().props.errors as Record<string, string>;
    const asked = approvals.find((a) => a.kind === 'line_price' && a.line_id === line.id);
    const { t, num, money } = useI18n();
    const [part, setPart] = useState('');
    const [price, setPrice] = useState('');
    // First click wins: the server turns this away if someone else already checked the line since this screen was drawn.
    const send = (status: string, quantity?: string) => router.post(`/admin/orders/${orderId}/lines/${line.id}/verify`, { status, quantity, expected: line.check ?? 'pending' }, { preserveScroll: true });

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
            <div className="min-w-48 flex-1">
                <p className="font-medium">
                    {line.name} <span className="text-sm font-normal text-muted-foreground">{line.strength}</span>
                </p>
                {line.cold && <p className="text-xs font-semibold text-info">{t('Keep refrigerated. Pack with ice.')}</p>}
                <p className="text-xs text-muted-foreground">
                    {line.offer && <strong className="mr-1 rounded bg-[var(--pt-deep,#0e0d0c)] px-1.5 py-0.5 text-[10px] tracking-wide text-[#e8c97a]">{t('OFFER')}</strong>}
                    {line.company} · {t(line.unit === 'box' ? 'Box' : line.unit === 'strip' ? 'Strip' : 'Piece')}
                    {line.checked_by && line.check && line.check !== 'pending' && ` · ${t('checked by {name}', { name: line.checked_by })}`}
                    {line.offer && line.offer_delivery != null && ` · ${t('Delivery from {amount}', { amount: money(line.offer_delivery) })}`}
                </p>
            </div>
            <p className="tabular text-sm">
                {t('Asked for')} {num(line.requested)}
            </p>
            {open ? (
                <div className="flex items-center gap-1">
                    <Input aria-label={t('Price')} className="h-8 w-24" type="number" min="0" step="any" placeholder={String(line.unit_price)} value={price} onChange={(e) => setPrice(e.target.value)} />
                    <Button size="sm" variant="ghost" disabled={price === ''} onClick={() => router.put(`/admin/orders/${orderId}/lines/${line.id}/price`, { price }, { preserveScroll: true, onSuccess: () => setPrice('') })}>
                        {t('Set price')}
                    </Button>
                    {(errors.order === t('This price needs the admin.') || asked?.status === 'pending') && price !== '' && (
                        <Button size="sm" disabled={asked?.status === 'pending'} onClick={() => router.post('/admin/approvals', { invoice_id: orderId, kind: 'line_price', value: price, line_id: line.id }, { preserveScroll: true })}>
                            {asked?.status === 'pending' ? t('Waiting for the admin…') : t('Need admin approval')}
                        </Button>
                    )}
                </div>
            ) : (
                <p className="tabular text-sm">{money(line.unit_price)}</p>
            )}
            {open ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant={line.check === 'full' ? 'default' : 'outline'} onClick={() => send('full')}>
                        {t('Full')}
                    </Button>
                    <Input aria-label={t('Quantity')} className="h-8 w-20" type="number" min="0" step="any" value={part} onChange={(e) => setPart(e.target.value)} />
                    <Button size="sm" variant={line.check === 'partial' ? 'default' : 'outline'} disabled={part === ''} onClick={() => send('partial', part)}>
                        {t('Partial')}
                    </Button>
                    <Button size="sm" variant={line.check === 'unavailable' ? 'destructive' : 'outline'} onClick={() => send('unavailable')}>
                        {t('Not available')}
                    </Button>
                </div>
            ) : (
                <p className="text-sm font-semibold">{line.check === 'unavailable' ? t('Not available') : num(line.quantity)}</p>
            )}
        </li>
    );
}

/** Pathao books by its own city and zone. We show our best guess and the staff can change it before the parcel is booked. */
function CourierPlaces({ orderId, onPick }: { orderId: number; onPick: (p: { city_id: number | null; zone_id: number | null }) => void }) {
    const { t } = useI18n();
    const [cities, setCities] = useState<{ id: number; name: string }[]>([]);
    const [zones, setZones] = useState<{ id: number; name: string }[]>([]);
    const [city, setCity] = useState<number | null>(null);
    const [zone, setZone] = useState<number | null>(null);
    const [problem, setProblem] = useState<string | null>(null);

    const load = (c: number | null, keepGuess: boolean) => {
        fetch(`/admin/orders/${orderId}/courier-places${c ? `?city=${c}` : ''}`, { headers: { Accept: 'application/json' } })
            .then(async (r) => ({ ok: r.ok, j: await r.json() }))
            .then(({ ok, j }) => {
                if (!ok) {
                    setProblem(j.message ?? t('Could not load the places.'));

                    return;
                }
                setCities(j.cities);
                setZones(j.zones);
                setCity(j.city_id);
                const z = keepGuess ? j.zone_id : null;
                setZone(z);
                onPick({ city_id: j.city_id, zone_id: z });
            })
            .catch(() => setProblem(t('Could not load the places.')));
    };

    useEffect(() => load(null, true), [orderId]);

    if (problem) {
        return <p className="text-sm text-warning">{problem}</p>;
    }

    return (
        <div className="flex flex-wrap gap-3 text-sm">
            <label>
                {t('City')}
                <select className="mt-1 block h-9 rounded-md border bg-background px-2" value={city ?? ''} onChange={(e) => { setZone(null); onPick({ city_id: Number(e.target.value) || null, zone_id: null }); load(Number(e.target.value) || null, false); }}>
                    <option value="">—</option>
                    {cities.map((c) => (
                        <option key={c.id} value={c.id}>{c.name}</option>
                    ))}
                </select>
            </label>
            <label>
                {t('Zone')}
                <select className="mt-1 block h-9 rounded-md border bg-background px-2" value={zone ?? ''} onChange={(e) => { const z = Number(e.target.value) || null; setZone(z); onPick({ city_id: city, zone_id: z }); }}>
                    <option value="">—</option>
                    {zones.map((z) => (
                        <option key={z.id} value={z.id}>{z.name}</option>
                    ))}
                </select>
            </label>
        </div>
    );
}

export default function OrderShow({ order, approvals, controlled, controlledDefaults }: Props) {
    const [place, setPlace] = useState<{ city_id: number | null; zone_id: number | null }>({ city_id: null, zone_id: null });
    const { t, money, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const open = order.status === 'placed';
    const [charge, setCharge] = useState(String(order.delivery_charge || ''));
    const [tracking, setTracking] = useState('');
    const [extra, setExtra] = useState('');
    const [terms, setTerms] = useState('');
    const [cost, setCost] = useState('');
    const [cartons, setCartons] = useState('');
    const [other, setOther] = useState<string | null>(order.working);

    // Tell the server this order is open here, and learn who else has it open. Others can still come in.
    useEffect(() => {
        const beat = () => postJson<{ other: string | null }>(`/admin/orders/${order.id}/working`).then((r) => r && setOther(r.other));
        beat(); // at once, not only after the first half minute: someone opening the order a moment later must see this
        const timer = setInterval(beat, 30000);
        return () => clearInterval(timer);
    }, [order.id]);

    // Both screens stay current (spec ch 7): while the order is being checked, pick up what the other person changed every few seconds. Not while the tab is hidden.
    useEffect(() => {
        if (!open) {
            return;
        }
        const timer = setInterval(() => document.visibilityState === 'visible' && router.reload({ only: ['order'] }), 5000);
        return () => clearInterval(timer);
    }, [open]);
    const sendable = (order.status === 'verified' || order.status === 'final') && !order.sent_at && (order.delivery_mode === 'send' || order.delivery_mode === 'delivery');
    const overFree = order.free_over > 0 && order.subtotal >= order.free_over && Number(charge) > 0;
    const crumbs: BreadcrumbItem[] = [
        { title: 'Online orders', href: '/admin/orders' },
        { title: order.customer ?? 'Order', href: `/admin/orders/${order.id}` },
    ];

    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title={order.customer ?? t('Order')} />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4">
                <Flash />
                <InputError message={errors.order} />
                {other && (
                    <p role="status" className="rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm font-semibold">
                        {t('{name} is working on this order.', { name: other })}
                    </p>
                )}
                <ControlledDetails items={controlled} defaults={controlledDefaults} />
                {order.cancel_requested && (
                    <div role="alert" className="flex flex-wrap items-center gap-2 rounded-md border border-danger/50 bg-danger/10 px-3 py-2 text-sm">
                        <strong className="flex-1">{t('This shop wants to cancel.')}</strong>
                        <Button size="sm" variant="destructive" onClick={() => router.post(`/admin/orders/${order.id}/cancel-decision`, { approve: true })}>
                            {t('Cancel the order')}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => router.post(`/admin/orders/${order.id}/cancel-decision`, { approve: false })}>
                            {t('Keep it')}
                        </Button>
                    </div>
                )}
                {order.flagged && (
                    <p role="alert" className="rounded-md border border-warning/50 bg-warning/10 px-3 py-2 text-sm font-semibold">
                        {t('This shop has cancelled {n} checked orders lately.', { n: num(order.late_cancellations) })}
                    </p>
                )}
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{order.customer}</h1>
                    <p className="text-sm text-muted-foreground">
                        {order.phone} · {t(order.delivery_mode === 'send' ? 'Send it by courier' : order.delivery_mode === 'delivery' ? 'Deliver it to me' : 'I will collect it')}
                    </p>
                    {order.note && <p className="mt-2 rounded-md bg-muted p-2 text-sm">{order.note}</p>}
                    {order.wanted_time && <p className="mt-2 text-sm font-semibold">{t('Needed by {time}', { time: order.wanted_time })}</p>}
                    {order.has_voice && (
                        <div className="mt-2">
                            <p className="text-xs text-muted-foreground">{t('Voice note')}</p>
                            <audio controls preload="none" src={`/admin/orders/${order.id}/attachment/voice`} className="h-10 max-w-full" />
                        </div>
                    )}
                    {order.has_photo && (
                        <a href={`/admin/orders/${order.id}/attachment/photo`} target="_blank" rel="noreferrer" className="mt-2 inline-block">
                            <img src={`/admin/orders/${order.id}/attachment/photo`} alt={t('Photo from the buyer')} className="max-h-40 rounded-md border" />
                        </a>
                    )}
                    {order.payment_terms && (
                        <p className="mt-1 text-sm font-medium">{t(order.payment_terms === 'cod' ? 'Pay when it arrives' : 'Pay in advance')}</p>
                    )}
                </header>

                {order.not_received && (
                    <div role="alert" className="flex flex-wrap items-center gap-2 rounded-md border border-danger/50 bg-danger/10 px-3 py-2 text-sm">
                        <strong className="flex-1">{t('The buyer says this did not arrive.')}</strong>
                        <Button size="sm" variant="outline" onClick={() => router.post(`/admin/orders/${order.id}/not-received`, { arrived: true }, { preserveScroll: true })}>
                            {t('It did arrive')}
                        </Button>
                        <Button size="sm" variant="destructive" onClick={() => router.post(`/admin/orders/${order.id}/not-received`, { arrived: false }, { preserveScroll: true })}>
                            {t('It was lost')}
                        </Button>
                    </div>
                )}

                {order.status === 'cancelled' && order.delivery_refund > 0 && (
                    <p className="rounded-md border border-warning/40 bg-warning/5 px-3 py-2 text-sm">{t('Cancelled before sending: half of the delivery charge goes back ({n}). The other half stays for the handling.', { n: money(order.delivery_refund) })}</p>
                )}
                {order.advance_needed && (
                    <section className="space-y-2 rounded-md border border-warning/40 bg-warning/5 p-3">
                        <h2 className="font-medium">{t('Delivery charge first')}</h2>
                        {order.advance_at ? (
                            <p className="text-sm">{t('The delivery charge was received {when}.', { when: dateTime(order.advance_at) })}</p>
                        ) : (
                            <>
                                <p className="text-muted-foreground text-sm">{t('This order is over the set amount, so the delivery charge ({n}) must be received before it is sent.', { n: money(order.delivery_charge) })}</p>
                                <Button size="sm" onClick={() => router.post(`/admin/orders/${order.id}/delivery-advance`, {}, { preserveScroll: true })}>
                                    {t('Delivery charge received')}
                                </Button>
                            </>
                        )}
                    </section>
                )}

                {sendable && (
                    <section className="space-y-2 rounded-md border p-3">
                        <h2 className="font-medium">{t('Send it')}</h2>
                        <p className="text-muted-foreground text-sm">{t('If sending cost more than the buyer was charged, write the extra. It is added and shown on the memo.')}</p>
                        <div className="flex flex-wrap items-end gap-2">
                            <label className="text-sm">
                                {t('Tracking note')}
                                <Input className="mt-1 h-9 w-56" value={tracking} onChange={(e) => setTracking(e.target.value)} maxLength={80} />
                            </label>
                            <label className="text-sm">
                                {t('Extra delivery charge')}
                                <Input className="mt-1 h-9 w-28" type="number" min="0" step="any" value={extra} onChange={(e) => setExtra(e.target.value)} />
                            </label>
                            {order.trade && (
                                <>
                                    <label className="text-sm">
                                        {t('Courier charge')}
                                        <select value={terms} onChange={(e) => setTerms(e.target.value)} className="bg-background mt-1 block h-9 w-52 rounded-md border px-2 text-sm">
                                            <option value="">{t('As quoted')}</option>
                                            <option value="split">{t('Shared 50-50')}</option>
                                            <option value="fixed">{t('A fixed amount')}</option>
                                        </select>
                                    </label>
                                    {terms !== '' && (
                                        <>
                                            <label className="text-sm">
                                                {terms === 'split' ? t('What the courier charged') : t('The fixed amount')}
                                                <Input className="mt-1 h-9 w-28" type="number" min="0" step="any" value={cost} onChange={(e) => setCost(e.target.value)} />
                                            </label>
                                            <label className="text-sm">
                                                {t('Cartons')}
                                                <Input className="mt-1 h-9 w-20" type="number" min="1" step="1" value={cartons} onChange={(e) => setCartons(e.target.value)} />
                                            </label>
                                        </>
                                    )}
                                </>
                            )}
                            <Button onClick={() => router.post(`/admin/orders/${order.id}/send`, { tracking: tracking || null, extra: terms ? null : extra || null, terms: terms || null, courier_cost: terms ? cost || null : null, cartons: terms ? cartons || null : null }, { preserveScroll: true })}>{t('Mark as sent')}</Button>
                        </div>
                    </section>
                )}
                {(order.status === 'verified' || order.status === 'final') && !order.delivered_at && order.delivery_mode === 'delivery' && !order.courier_id && order.people.length > 0 && (
                    <section className="space-y-2 rounded-md border p-3">
                        <h2 className="font-medium">{t('Send with our own delivery person')}</h2>
                        {order.delivery_person ? (
                            <p className="text-sm">
                                {t('With')} <strong>{order.delivery_person}</strong>
                                {order.arrived_at && ` · ${t('At the door')}`}
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-sm">{t('They get it on their list. The buyer gets a code to tell them at the door.')}</p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {order.people.map((p) => (
                                <Button key={p.id} size="sm" variant="outline" onClick={() => router.post(`/admin/orders/${order.id}/assign`, { person_id: p.id }, { preserveScroll: true })}>
                                    {p.name}
                                </Button>
                            ))}
                        </div>
                    </section>
                )}
                {order.courier_panel && (
                    <section className="space-y-2 rounded-md border p-3">
                        <h2 className="font-medium">{t('Book with {name}', { name: order.courier_panel.name })}</h2>
                        {order.courier_panel.booked ? (
                            <>
                                <p className="text-sm">
                                    {t('Booked.')} {t('Their number')}: <strong>{order.courier_panel.consignment}</strong>
                                    {order.courier_panel.status_label && ` · ${t(order.courier_panel.status_label)}`}
                                    {order.courier_panel.status_at && ` · ${dateTime(order.courier_panel.status_at)}`}
                                </p>
                                {!order.delivered_at && (
                                    <Button size="sm" variant="outline" onClick={() => router.post(`/admin/orders/${order.id}/courier-status`, {}, { preserveScroll: true })}>
                                        {t('Ask where it is now')}
                                    </Button>
                                )}
                            </>
                        ) : order.courier_panel.blocked ? (
                            <p className="text-muted-foreground text-sm">{t(order.courier_panel.blocked)}</p>
                        ) : (
                            <>
                                <p className="text-muted-foreground text-sm">{t('The courier will collect {amount} from the buyer.', { amount: money(order.courier_panel.collect) })}</p>
                                {order.courier_panel.places && <CourierPlaces orderId={order.id} onPick={setPlace} />}
                                <Button onClick={() => window.confirm(t('Book this parcel now? A booking is charged once.')) && router.post(`/admin/orders/${order.id}/book-courier`, order.courier_panel?.places ? { city_id: place.city_id, zone_id: place.zone_id } : {}, { preserveScroll: true })}>{t('Book the parcel')}</Button>
                            </>
                        )}
                    </section>
                )}
                {order.sent_at && (
                    <p className="text-sm">
                        {t('Sent')} {dateTime(order.sent_at)}
                        {order.tracking && ` · ${order.tracking}`}
                        {order.courier_terms && ` · ${order.courier_terms === 'split' ? t('Courier charge shared 50-50') : t('Courier charge fixed')}${order.cartons ? `, ${t('{n} cartons', { n: num(order.cartons) })}` : ''}`}
                        {order.delivery_extra > 0 && ` · ${t('Extra delivery charge')} ${money(order.delivery_extra)}`}
                        {order.delivered_at && ` · ${t('Delivered')} (${t(order.delivered_by === 'auto' ? 'confirmed by itself' : order.delivered_by === 'otp' ? 'the buyer gave the code' : order.delivered_by === 'photo' ? 'photo of the parcel' : 'the buyer said so')})`}
                    </p>
                )}

                {order.ship_to && (
                    <section className="space-y-0.5 rounded-md border p-3 text-sm">
                        <h2 className="font-medium">{t('Deliver to')}</h2>
                        <p>
                            {order.ship_to.name} · {order.ship_to.phone}
                            {order.ship_to.for_whom && ` · ${t('For')} ${order.ship_to.for_whom}`}
                        </p>
                        <p>
                            {order.ship_to.address}, {order.ship_to.area}, {t(order.ship_to.district)}
                        </p>
                        {order.ship_to.how_to_find && <p className="text-muted-foreground">{order.ship_to.how_to_find}</p>}
                        {order.ship_to.lat != null && order.ship_to.lng != null && (
                            <a href={`https://www.openstreetmap.org/?mlat=${order.ship_to.lat}&mlon=${order.ship_to.lng}#map=17/${order.ship_to.lat}/${order.ship_to.lng}`} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                                {t('Open the delivery place on the map')}
                            </a>
                        )}
                    </section>
                )}

                <ul className="divide-y rounded-md border">
                    {order.lines.map((l) => (
                        <LineRow key={l.id} orderId={order.id} line={l} open={open} approvals={approvals} />
                    ))}
                </ul>

                {order.delivery_mode === 'delivery' && (open || order.status === 'verified') && (
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="w-32">
                            <label htmlFor="charge" className="text-xs text-muted-foreground">
                                {t('Delivery charge')}
                            </label>
                            <Input id="charge" type="number" min="0" step="any" value={charge} onChange={(e) => setCharge(e.target.value)} />
                        </div>
                        <Button variant="outline" disabled={charge === ''} onClick={() => router.put(`/admin/orders/${order.id}/delivery`, { charge }, { preserveScroll: true })}>
                            {t('Save')}
                        </Button>
                        {overFree && <p className="text-sm font-semibold text-warning">{t('This order is past the free-delivery amount.')}</p>}
                    </div>
                )}

                <p className="flex justify-between font-display text-xl font-extrabold">
                    <span>{t('Total')}</span>
                    <span className="tabular">{money(order.total)}</span>
                </p>

                {open && (
                    <Button disabled={order.lines.some((l) => l.check === 'pending')} onClick={() => router.post(`/admin/orders/${order.id}/complete`)}>
                        {t('Checking done')}
                    </Button>
                )}
                {order.status === 'verified' && (
                    <Button variant="outline" onClick={() => confirm(t('Packed but not collected? The order is cancelled and the goods go back.')) && router.post(`/admin/orders/${order.id}/not-collected`)}>
                        {t('Not collected')}
                    </Button>
                )}
                {order.status === 'verified' && (
                    <Button asChild>
                        <Link href={`/admin/counter/${order.id}`}>{t('Finish at the counter')}</Link>
                    </Button>
                )}
            </div>
        </AppLayout>
    );
}
