import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Place = 'provider' | 'online' | 'home';

interface Offer {
    id: number;
    title: string;
    description: string | null;
    minutes: number;
    price: number;
    place: Place;
    area: string | null;
    active: boolean;
    hidden: boolean;
    slots: { id: number; at: string; capacity: number; booked: number }[];
}

interface Booking {
    id: number;
    service: string;
    status: 'pending' | 'confirmed' | 'done';
    name: string;
    phone: string;
    note: string | null;
    price: number;
    when: string;
    due: boolean;
}

interface Props {
    offers: Offer[];
    bookings: Booking[];
    confirmHours: number;
}

/** A form for one service: new, or changing one that is there. */
function OfferForm({ offer, onDone }: { offer?: Offer; onDone?: () => void }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ title: offer?.title ?? '', description: offer?.description ?? '', minutes: String(offer?.minutes ?? 60), price: String(offer?.price ?? 0), place: (offer?.place ?? 'provider') as Place, area: offer?.area ?? '' });
    const [busy, setBusy] = useState(false);
    const places: { value: Place; label: string }[] = [
        { value: 'provider', label: t('At my place') },
        { value: 'online', label: t('Online') },
        { value: 'home', label: t("At the buyer's home") },
    ];

    return (
        <form
            className="space-y-3 rounded-xl border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                const body = { ...f, minutes: Number(f.minutes), price: Number(f.price) };
                const opts = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: () => { if (!offer) setF({ ...f, title: '', description: '' }); onDone?.(); } };
                if (offer) router.put(`/wholesale/sell/services/${offer.id}`, body, opts);
                else router.post('/wholesale/sell/services', body, opts);
            }}
        >
            <label className="block text-sm font-medium">
                {t('Name of the service')}
                <Input className="mt-1" maxLength={120} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} />
            </label>
            <label className="block text-sm font-medium">
                {t('What it is (optional)')}
                <textarea className="mt-1 min-h-20 w-full rounded-md border bg-background p-3 text-sm" maxLength={2000} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} />
            </label>
            <div className="grid gap-3 sm:grid-cols-3">
                <label className="text-sm font-medium">
                    {t('Minutes')}
                    <Input className="mt-1" inputMode="numeric" value={f.minutes} onChange={(e) => setF({ ...f, minutes: e.target.value.replace(/\D/g, '') })} />
                </label>
                <label className="text-sm font-medium">
                    {t('Price (0 to ask you)')}
                    <Input className="mt-1" inputMode="decimal" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} />
                </label>
                <label className="text-sm font-medium">
                    {t('Area')}
                    <Input className="mt-1" maxLength={80} value={f.area} onChange={(e) => setF({ ...f, area: e.target.value })} />
                </label>
            </div>
            <ToggleGroup type="single" value={f.place} onValueChange={(v) => v && setF({ ...f, place: v as Place })} variant="outline" size="sm" className="flex-wrap justify-start" aria-label={t('Where it happens')}>
                {places.map((p) => (
                    <ToggleGroupItem key={p.value} value={p.value}>
                        {p.label}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>
            <InputError message={errors.service} />
            <Button type="submit" size="sm" disabled={busy || f.title.trim().length < 3} aria-busy={busy}>
                {busy ? t('Saving…') : offer ? t('Save') : t('Add the service')}
            </Button>
        </form>
    );
}

function Slots({ offer }: { offer: Offer }) {
    const { t, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [at, setAt] = useState('');
    const [capacity, setCapacity] = useState('1');
    const [days, setDays] = useState('1');

    return (
        <div className="space-y-3">
            <h4 className="font-display text-base font-bold">{t('Times people can book')}</h4>
            {offer.slots.length === 0 ? (
                <p className="text-sm text-[var(--pt-muted)]">{t('No time yet. Add one below.')}</p>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {offer.slots.map((s) => (
                        <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <span>{dateTime(s.at)}</span>
                            <span className="tabular text-xs text-[var(--pt-muted)]">{t('{a} of {b} places taken', { a: num(s.booked), b: num(s.capacity) })}</span>
                            <Button size="sm" variant="ghost" disabled={s.booked > 0} onClick={() => router.delete(`/wholesale/sell/services/slots/${s.id}`, { preserveScroll: true })}>
                                {t('Remove')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.post(`/wholesale/sell/services/${offer.id}/slots`, { starts_at: at.replace('T', ' '), capacity: Number(capacity), days: Number(days) }, { preserveScroll: true });
                }}
            >
                <label className="text-sm font-medium">
                    {t('Day and time')}
                    <Input className="mt-1 w-52" type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} />
                </label>
                <label className="text-sm font-medium">
                    {t('Places')}
                    <Input className="mt-1 w-20" inputMode="numeric" value={capacity} onChange={(e) => setCapacity(e.target.value.replace(/\D/g, ''))} />
                </label>
                <label className="text-sm font-medium">
                    {t('Every day for')}
                    <Input className="mt-1 w-20" inputMode="numeric" value={days} onChange={(e) => setDays(e.target.value.replace(/\D/g, ''))} />
                </label>
                <Button type="submit" size="sm" variant="outline" disabled={!at}>
                    {t('Add')}
                </Button>
            </form>
            <InputError message={errors.slots} />
        </div>
    );
}

/** A provider's services, the times they can do them, and the bookings waiting for an answer. */
export default function SellServices({ offers, bookings, confirmHours }: Props) {
    const { t, num, money, dateTime } = useI18n();
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const flash = (page.props.flash as { status?: string } | undefined)?.status;
    const [open, setOpen] = useState<number | null>(null);
    const [adding, setAdding] = useState(false);
    const state = { pending: t('Waiting for you'), confirmed: t('Confirmed'), done: t('Done') };

    return (
        <PublicLayout>
            <Head title={t('Services')} />
            <div className="mx-auto max-w-3xl space-y-8">
                <header className="space-y-1">
                    <Link href="/wholesale/sell" className="text-sm underline underline-offset-4">
                        {t('Sell')}
                    </Link>
                    <h1 className="font-display text-3xl font-extrabold">{t('Services')}</h1>
                    <p className="text-sm text-[var(--pt-muted)]">{t('Give a lesson, a repair or a visit. Add the times you can do it; people book one, and you confirm. A request you do not answer in {n} hours is declined by itself.', { n: num(confirmHours) })}</p>
                </header>
                {flash && (
                    <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                        {flash}
                    </p>
                )}

                <section className="space-y-3">
                    <h2 className="font-display text-xl font-bold">{t('Bookings')}</h2>
                    <InputError message={errors.booking} />
                    {bookings.length === 0 ? (
                        <p className="rounded-xl border p-5 text-sm text-[var(--pt-muted)]">{t('No booking waiting.')}</p>
                    ) : (
                        <ul className="divide-y rounded-[1.5rem] border bg-[var(--pt-surface)]">
                            {bookings.map((b) => (
                                <li key={b.id} className="space-y-2 px-4 py-3 text-sm">
                                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="font-semibold">{b.service}</span>
                                        <span className="font-medium">{state[b.status]}</span>
                                    </div>
                                    <p>
                                        {dateTime(b.when)} · {b.name} · <a href={`tel:${b.phone}`} className="underline">{b.phone}</a>
                                        {b.price > 0 && ` · ${money(b.price)}`}
                                    </p>
                                    {b.note && <p className="text-[var(--pt-muted)]">{b.note}</p>}
                                    <div className="flex flex-wrap gap-2">
                                        {b.status === 'pending' && (
                                            <Button size="sm" onClick={() => router.post(`/wholesale/sell/services/bookings/${b.id}/confirm`, {}, { preserveScroll: true })}>
                                                {t('Confirm')}
                                            </Button>
                                        )}
                                        {b.status !== 'done' && (
                                            <Button size="sm" variant="outline" onClick={() => { const reason = window.prompt(t('Why? (the buyer is told, optional)')); if (reason !== null) router.post(`/wholesale/sell/services/bookings/${b.id}/decline`, { reason }, { preserveScroll: true }); }}>
                                                {t('Decline')}
                                            </Button>
                                        )}
                                        {b.status === 'confirmed' && b.due && (
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/wholesale/sell/services/bookings/${b.id}/done`, {}, { preserveScroll: true })}>
                                                {t('Mark done')}
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-display text-xl font-bold">{t('Your services')}</h2>
                        <Button size="sm" variant="outline" aria-expanded={adding} onClick={() => setAdding(!adding)}>
                            {t('Add a service')}
                        </Button>
                    </div>
                    {adding && <OfferForm onDone={() => setAdding(false)} />}
                    {offers.length === 0 && !adding && <p className="rounded-xl border p-5 text-sm text-[var(--pt-muted)]">{t('You give no service yet.')}</p>}
                    <ul className="space-y-3">
                        {offers.map((o) => (
                            <li key={o.id} className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span>
                                        <span className="block font-display text-lg font-bold">{o.title}</span>
                                        <span className="text-xs text-[var(--pt-muted)]">
                                            {t('{n} minutes', { n: num(o.minutes) })} · {o.price > 0 ? money(o.price) : t('Ask the provider')}
                                            {o.hidden && ` · ${t('Hidden by us')}`}
                                            {!o.active && ` · ${t('Paused')}`}
                                        </span>
                                    </span>
                                    <span className="flex gap-2">
                                        <Button size="sm" variant="outline" onClick={() => router.put(`/wholesale/sell/services/${o.id}`, { title: o.title, minutes: o.minutes, price: o.price, place: o.place, area: o.area ?? '', description: o.description ?? '', active: !o.active }, { preserveScroll: true })}>
                                            {o.active ? t('Pause') : t('Open again')}
                                        </Button>
                                        <Button size="sm" variant="outline" aria-expanded={open === o.id} onClick={() => setOpen(open === o.id ? null : o.id)}>
                                            {t('Change')}
                                        </Button>
                                    </span>
                                </div>
                                {open === o.id && <OfferForm offer={o} onDone={() => setOpen(null)} />}
                                <Slots offer={o} />
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
        </PublicLayout>
    );
}
