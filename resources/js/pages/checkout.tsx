import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import LocateButton from '@/components/locate-button';
import OrderExtras from '@/components/order-extras';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { unitName } from './cart';

interface Address {
    id: number;
    label: string | null;
    name: string;
    phone: string;
    district: string;
    area: string;
    address: string;
    how_to_find: string | null;
    lat?: number | null;
    lng?: number | null;
}

interface Quote {
    source: 'none' | 'fixed' | 'free' | 'courier' | 'flat' | 'blocked' | 'closed';
    cold?: boolean;
    weight_kg: number | null;
    zone: string;
    options: { courier_id: number | null; name: string; charge: number }[];
}

interface Props {
    advance_over: number;
    lines: { key: string; name: string; strength: string | null; unit: string; quantity: number; total: number }[];
    subtotal: number;
    districts: string[];
    locate: boolean;
    delivery: { district: string; pickup: boolean; address: string | null };
    quote: Quote;
    addresses: Address[];
    defaults: { name: string | null; phone: string | null };
    notice: 'holiday' | 'night' | null;
    signedIn: boolean;
    token: { amount: number } | null;
}

/** Name, phone and address are needed even without an account; only the password and sign-up are skipped (spec ch 13). */
export default function Checkout({ advance_over, lines, subtotal, districts, locate, delivery, quote: first, addresses, defaults, notice, signedIn, token }: Props) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const form = useForm({
        name: defaults.name ?? '',
        phone: defaults.phone ?? '',
        district: delivery.district,
        area: '',
        address: '',
        how_to_find: '',
        lat: null as number | null,
        lng: null as number | null,
        for_whom: '',
        delivery_mode: 'delivery',
        note: '',
        save_address: false as boolean,
        use_token: true as boolean,
        courier_id: null as number | null,
        voice: null as File | null,
        photo: null as File | null,
        wanted_time: '',
    });
    const d = form.data;
    const [quote, setQuote] = useState<Quote>(first);
    const byCourier = quote.source === 'courier';
    const picked = byCourier ? quote.options.find((o) => o.courier_id === d.courier_id) : quote.options[0];
    const charge = picked?.charge ?? 0;

    // The charge is worked out on the server from the cart and the address; ask again when either changes.
    useEffect(() => {
        const timer = setTimeout(() => {
            const q = new URLSearchParams({ district: d.district, area: d.area, mode: d.delivery_mode });
            fetch(`/checkout/quote?${q}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((next: Quote) => {
                    setQuote(next);
                    if (!next.options.some((o) => o.courier_id === d.courier_id)) form.setData('courier_id', null);
                })
                .catch(() => undefined);
        }, 250);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [d.district, d.area, d.delivery_mode]);
    const fill = (a: Address) => form.setData({ ...d, name: a.name, phone: a.phone, district: a.district, area: a.area, address: a.address, how_to_find: a.how_to_find ?? '', lat: a.lat ?? null, lng: a.lng ?? null });
    const bad = (k: string) => (errors as Record<string, string>)[k];

    return (
        <PublicLayout>
            <Head title={t('Checkout')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('Checkout')}</h1>
                {notice && (
                    <p role="note" className="rounded-2xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                        {notice === 'holiday' ? t('We are closed today. You can still order. We will check it on the next working day.') : t('It is night. You can still order. We will check it in the morning.')}
                    </p>
                )}
                {!signedIn && (
                    <p className="text-sm text-[var(--pt-muted)]">
                        {t('You can order without an account.')}{' '}
                        <Link href="/login" className="font-semibold underline underline-offset-4">
                            {t('Have an account? Log in to see your orders and keep your addresses.')}
                        </Link>
                    </p>
                )}

                <form
                    className="space-y-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/checkout', { forceFormData: true });
                    }}
                >
                    {addresses.length > 0 && (
                        <section aria-labelledby="saved-h" className="space-y-2">
                            <h2 id="saved-h" className="font-display text-xl font-bold">
                                {t('Your saved addresses')}
                            </h2>
                            <ul className="grid gap-2 sm:grid-cols-2">
                                {addresses.map((a) => (
                                    <li key={a.id} className="flex items-start gap-2 rounded-2xl border bg-[var(--pt-surface)] p-3 text-sm">
                                        <button type="button" onClick={() => fill(a)} className="flex-1 text-left">
                                            <span className="block font-semibold">{a.label || a.name}</span>
                                            <span className="block text-[var(--pt-muted)]">
                                                {a.address}, {a.area}, {t(a.district)}
                                            </span>
                                        </button>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => router.delete(`/addresses/${a.id}`, { preserveScroll: true })}>
                                            {t('Remove')}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <section aria-labelledby="who-h" className="space-y-3">
                        <h2 id="who-h" className="font-display text-xl font-bold">
                            {t('Where should we send it?')}
                        </h2>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label htmlFor="co-name" className="text-sm font-medium">
                                    {t('Your name')}
                                </label>
                                <Input id="co-name" className="mt-1" value={d.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={120} autoComplete="name" />
                                <InputError message={bad('name')} />
                            </div>
                            <div>
                                <label htmlFor="co-phone" className="text-sm font-medium">
                                    {t('Mobile number')}
                                </label>
                                <Input id="co-phone" className="mt-1" inputMode="tel" value={d.phone} onChange={(e) => form.setData('phone', e.target.value)} autoComplete="tel" placeholder="01XXXXXXXXX" />
                                <InputError message={bad('phone')} />
                            </div>
                            {locate && (
                                <LocateButton
                                    onFound={(f) =>
                                        form.setData({
                                            ...d,
                                            district: f.district && districts.includes(f.district) ? f.district : d.district,
                                            area: f.area || d.area,
                                            address: d.address || f.line,
                                            lat: f.lat,
                                            lng: f.lng,
                                        })
                                    }
                                />
                            )}
                            <div>
                                <label htmlFor="co-district" className="text-sm font-medium">
                                    {t('District')}
                                </label>
                                <select id="co-district" value={d.district} onChange={(e) => form.setData('district', e.target.value)} className="mt-1 block h-10 w-full rounded-md border bg-background px-2 text-sm">
                                    {districts.map((x) => (
                                        <option key={x} value={x}>
                                            {t(x)}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={bad('district')} />
                            </div>
                            <div>
                                <label htmlFor="co-area" className="text-sm font-medium">
                                    {t('Area or thana')}
                                </label>
                                <Input id="co-area" className="mt-1" value={d.area} onChange={(e) => form.setData('area', e.target.value)} maxLength={80} />
                                <InputError message={bad('area')} />
                            </div>
                            <div className="sm:col-span-2">
                                <label htmlFor="co-address" className="text-sm font-medium">
                                    {t('House, road and flat')}
                                </label>
                                <Input id="co-address" className="mt-1" value={d.address} onChange={(e) => form.setData('address', e.target.value)} maxLength={250} autoComplete="street-address" />
                                <InputError message={bad('address')} />
                            </div>
                            <div>
                                <label htmlFor="co-find" className="text-sm font-medium">
                                    {t('How to get there')}
                                </label>
                                <Input id="co-find" className="mt-1" value={d.how_to_find} onChange={(e) => form.setData('how_to_find', e.target.value)} maxLength={200} placeholder={t('Next to the mosque, red gate')} />
                            </div>
                            <div>
                                <label htmlFor="co-for" className="text-sm font-medium">
                                    {t('Ordering for someone else? Their name')}
                                </label>
                                <Input id="co-for" className="mt-1" value={d.for_whom} onChange={(e) => form.setData('for_whom', e.target.value)} maxLength={120} />
                            </div>
                        </div>
                        {signedIn && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={d.save_address} onChange={(e) => form.setData('save_address', e.target.checked)} />
                                {t('Save this address for next time')}
                            </label>
                        )}
                    </section>

                    <section aria-labelledby="how-h" className="space-y-2">
                        <h2 id="how-h" className="font-display text-xl font-bold">
                            {t('How will you get it?')}
                        </h2>
                        <div className="grid gap-2 sm:grid-cols-2">
                            <label className={`flex cursor-pointer items-start gap-3 rounded-2xl border p-3 ${d.delivery_mode === 'delivery' ? 'border-[var(--pt-deep)] bg-[var(--pt-surface)]' : ''}`}>
                                <input type="radio" name="mode" checked={d.delivery_mode === 'delivery'} onChange={() => form.setData('delivery_mode', 'delivery')} className="mt-1" />
                                <span>
                                    <span className="block font-semibold">{t('Send it to my address')}</span>
                                    <span className="text-sm text-[var(--pt-muted)]">
                                        {byCourier ? t('Pick a courier below.') : quote.source === 'free' ? t('Delivery is free on this order.') : `${t('Delivery charge')}: ${money(quote.options[0]?.charge ?? 0)}`}
                                    </span>
                                </span>
                            </label>
                            {delivery.pickup && (
                                <label className={`flex cursor-pointer items-start gap-3 rounded-2xl border p-3 ${d.delivery_mode === 'collect' ? 'border-[var(--pt-deep)] bg-[var(--pt-surface)]' : ''}`}>
                                    <input type="radio" name="mode" checked={d.delivery_mode === 'collect'} onChange={() => form.setData('delivery_mode', 'collect')} className="mt-1" />
                                    <span>
                                        <span className="block font-semibold">{t('I will collect it from the shop')}</span>
                                        <span className="text-sm text-[var(--pt-muted)]">{delivery.address}</span>
                                    </span>
                                </label>
                            )}
                        </div>
                        {byCourier && d.delivery_mode === 'delivery' && (
                            <fieldset className="space-y-2">
                                <legend className="text-sm font-medium">
                                    {t('Choose a courier')}
                                    {quote.weight_kg !== null && <span className="text-[var(--pt-muted)]"> · {t('Weight')} {num(quote.weight_kg)} {t('kg')}</span>}
                                </legend>
                                {quote.options.map((o) => (
                                    <label key={o.courier_id} className={`flex cursor-pointer items-center gap-3 rounded-2xl border p-3 ${d.courier_id === o.courier_id ? 'border-[var(--pt-deep)] bg-[var(--pt-surface)]' : ''}`}>
                                        <input type="radio" name="courier" checked={d.courier_id === o.courier_id} onChange={() => form.setData('courier_id', o.courier_id)} />
                                        <span className="flex-1 font-semibold">{o.name}</span>
                                        <span className="tabular font-semibold">{money(o.charge)}</span>
                                    </label>
                                ))}
                            </fieldset>
                        )}
                        {quote.cold && quote.source !== 'blocked' && d.delivery_mode === 'delivery' && (
                            <p className="text-sm text-[var(--pt-muted)]">{t('Something in your cart must stay cold. It goes by our own delivery, packed with ice.')}</p>
                        )}
                        {quote.source === 'blocked' && d.delivery_mode === 'delivery' && (
                            <p role="alert" className="rounded-2xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                                {t('Something in your cart must stay cold, so it cannot go by courier to this address. Collect it from the shop, or send it to an address in our own delivery area.')}
                            </p>
                        )}
                        {quote.source === 'closed' && d.delivery_mode === 'delivery' && (
                            <p role="alert" className="rounded-2xl border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                                {t('We do not deliver to this district. You can collect it from the shop.')}
                            </p>
                        )}
                        {advance_over > 0 && subtotal >= advance_over && d.delivery_mode !== 'collect' && <p className="rounded-md bg-warning/10 px-3 py-2 text-sm">{t('This order is big, so the delivery charge is paid first. After you order we tell you how, and we send the goods once it is received.')}</p>}
                        {quote.source === 'fixed' && <p className="text-sm text-[var(--pt-muted)]">{t('One of the products in your cart has its own delivery charge.')}</p>}
                        <p className="text-sm text-[var(--pt-muted)]">{t('You pay when it arrives.')}</p>
                    </section>

                    <div>
                        <label htmlFor="co-note" className="text-sm font-medium">
                            {t('Note')}
                        </label>
                        <Input id="co-note" className="mt-1" value={d.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={300} />
                    </div>
                    <OrderExtras value={{ voice: d.voice, photo: d.photo, wanted_time: d.wanted_time }} onChange={(x) => form.setData({ ...form.data, ...x })} errors={errors} />

                    <section aria-labelledby="sum-h" className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <h2 id="sum-h" className="font-display text-xl font-bold">
                            {t('Your order')}
                        </h2>
                        <ul className="divide-y text-sm">
                            {lines.map((l) => (
                                <li key={l.key} className="flex justify-between gap-3 py-1.5">
                                    <span>
                                        {l.name} {l.strength} · {num(l.quantity)} {t(unitName(l.unit))}
                                    </span>
                                    <span className="tabular">{money(l.total)}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="flex justify-between text-sm">
                            <span>{t('Delivery charge')}</span>
                            <span className="tabular">{money(charge)}</span>
                        </p>
                        <p className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                            <span>{t('Total')}</span>
                            <span className="tabular">{money(subtotal + charge)}</span>
                        </p>
                        {token && (
                            <label className="flex items-start gap-2 rounded-md bg-[var(--pt-sage)] px-3 py-2 text-sm">
                                <input type="checkbox" className="mt-1" checked={d.use_token} onChange={(e) => form.setData('use_token', e.target.checked)} />
                                <span>
                                    {t('Use my Aqua Token ({amount} off)', { amount: money(token.amount) })}
                                    <span className="block text-xs text-[var(--pt-muted)]">{t('It comes off when we finish your order. A token and an offer price do not go together.')}</span>
                                </span>
                            </label>
                        )}
                        <p className="text-xs text-[var(--pt-muted)]">{t('We check each item and tell you what we can supply. You pay only for that.')}</p>
                    </section>

                    <InputError message={bad('order')} />
                    <Button type="submit" className="h-12 w-full text-base" loading={form.processing} disabled={byCourier && d.delivery_mode === 'delivery' && d.courier_id === null}>
                        {t('Send order')}
                    </Button>
                </form>
            </div>
        </PublicLayout>
    );
}
