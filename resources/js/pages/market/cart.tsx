import { WasPrice } from '@/components/market/sale-price';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { unitLabel } from '@/lib/units';

interface Line {
    key: string;
    id: number;
    title: string;
    variant: string | null;
    unit: string;
    quantity: number;
    price: number;
    total: number;
    was?: number | null;
    bundle?: string | null;
    saved?: number;
}

interface Pack {
    n: number;
    seller: string;
    delivery_charge: number;
    goods: number;
    total: number;
    lines: Line[];
}

interface Props {
    cart: { packages: Pack[]; total: number; cod: boolean; online_open: boolean };
    districts: string[];
    defaults: { name: string | null; phone: string | null };
    signedIn: boolean;
}

/** The market cart (spec ch 13): many sellers, one cart, one checkout; each seller's goods become their own package. */
export default function MarketCart({ cart, districts, defaults, signedIn }: Props) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const form = useForm({ name: defaults.name ?? '', phone: defaults.phone ?? '', district: districts[0] ?? '', area: '', address: '', how_to_find: '', note: '', use_token: true as boolean });

    return (
        <PublicLayout>
            <Head title={t('Your market cart')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <h1 className="font-display text-3xl font-extrabold">{t('Your market cart')}</h1>
                {flash && (
                    <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                        {flash}
                    </p>
                )}
                {cart.packages.length === 0 ? (
                    <p className="rounded-[1.5rem] border border-dashed p-10 text-center text-[var(--pt-muted)]">
                        {t('Nothing here yet.')} <Link href="/market" className="underline">{t('Look at the market')}</Link>
                    </p>
                ) : (
                    <>
                        <p className="text-sm text-[var(--pt-muted)]">{t('One cart, one checkout. Each seller packs and sends their own package, with their own delivery charge.')}</p>
                        {cart.packages.map((p) => (
                            <section key={p.n} className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
                                <h2 className="font-display text-lg font-bold">
                                    {t('Package {n}', { n: num(p.n) })} · {p.seller}
                                </h2>
                                <ul className="divide-y text-sm">
                                    {p.lines.map((l) => (
                                        <li key={l.key} className="flex flex-wrap items-center gap-2 py-2">
                                            <span className="min-w-40 flex-1">
                                                <Link href={`/market/${l.id}`} className="font-medium hover:underline">
                                                    {l.title}
                                                </Link>
                                                {l.variant && <span className="text-[var(--pt-muted)]"> · {l.variant}</span>}
                                                <span className="block text-xs text-[var(--pt-muted)]">
                                                    <WasPrice was={l.was} className="text-xs" />
                                                    {money(l.price)} / {unitLabel(l.unit)}
                                                </span>
                                                {l.bundle && l.saved ? (
                                                    <span className="block text-xs font-semibold">
                                                        {t('{bundle}: you save {amount}', { bundle: l.bundle, amount: money(l.saved) })}
                                                    </span>
                                                ) : null}
                                            </span>
                                            <Input
                                                aria-label={t('Amount')}
                                                className="h-8 w-20"
                                                type="number"
                                                min="0.01"
                                                step="any"
                                                defaultValue={l.quantity}
                                                onBlur={(e) => Number(e.target.value) > 0 && Number(e.target.value) !== l.quantity && router.put(`/market-cart/${l.key}`, { quantity: e.target.value }, { preserveScroll: true })}
                                            />
                                            <span className="tabular w-24 text-right">{money(l.total)}</span>
                                            <Button size="sm" variant="ghost" onClick={() => router.delete(`/market-cart/${l.key}`, { preserveScroll: true })}>
                                                {t('Remove')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                                <p className="flex justify-between text-sm">
                                    <span>{t('Delivery charge')}</span>
                                    <span className="tabular">{money(p.delivery_charge)}</span>
                                </p>
                                <p className="flex justify-between font-semibold">
                                    <span>{t('Package total')}</span>
                                    <span className="tabular">{money(p.total)}</span>
                                </p>
                            </section>
                        ))}
                        <p className="flex justify-between text-lg font-bold">
                            <span>{t('Total')}</span>
                            <span className="tabular">{money(cart.total)}</span>
                        </p>

                        <form
                            className="grid gap-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 sm:grid-cols-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/market-cart/checkout');
                            }}
                        >
                            <h2 className="font-display text-xl font-bold sm:col-span-2">{t('Where to send it')}</h2>
                            <InputError className="sm:col-span-2" message={errors.order} />
                            <Input aria-label={t('Name')} placeholder={t('Name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <Input aria-label={t('Mobile number')} placeholder={t('Mobile number')} inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            <select aria-label={t('District')} value={form.data.district} onChange={(e) => form.setData('district', e.target.value)} className="h-9 rounded-md border bg-background px-2 text-sm">
                                {districts.map((d) => (
                                    <option key={d} value={d}>
                                        {d}
                                    </option>
                                ))}
                            </select>
                            <Input aria-label={t('Area')} placeholder={t('Area')} value={form.data.area} onChange={(e) => form.setData('area', e.target.value)} />
                            <Input className="sm:col-span-2" aria-label={t('Address')} placeholder={t('Address')} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                            <Input className="sm:col-span-2" aria-label={t('How to find you (optional)')} placeholder={t('How to find you (optional)')} value={form.data.how_to_find} onChange={(e) => form.setData('how_to_find', e.target.value)} />
                            <InputError className="sm:col-span-2" message={errors.phone ?? errors.address ?? errors.area ?? errors.name} />
                            {signedIn && (
                                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                    <input type="checkbox" checked={form.data.use_token} onChange={(e) => form.setData('use_token', e.target.checked)} />
                                    {t('Use my token on the first package if I have one')}
                                </label>
                            )}
                            <p className="text-sm sm:col-span-2">{cart.cod ? t('You pay each seller when their package arrives.') : t('Something in your cart is paid online only, so cash on delivery is not offered. You pay for each package online after you send the orders.')}</p>
                            {!cart.cod && !cart.online_open && <p className="rounded-md border border-warning/40 bg-warning/10 px-3 py-2 text-sm sm:col-span-2">{t('Online payment is not open yet, so this cart cannot be ordered now.')}</p>}
                            <Button type="submit" disabled={form.processing || (!cart.cod && !cart.online_open)} className="sm:col-span-2">
                                {t('Send the orders')}
                            </Button>
                        </form>
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
