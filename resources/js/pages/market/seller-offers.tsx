import SellerBrand from '@/components/seller-brand';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Offer {
    id: number;
    listing: string;
    quantity: number;
    offered: number;
    you_get: number;
    commission: number;
    now: number | null;
    expires_at: string | null;
}

function Card({ o }: { o: Offer }) {
    const { t, money, num, dateTime } = useI18n();
    const [price, setPrice] = useState('');
    const answer = (a: string, extra: Record<string, string> = {}) => router.post(`/wholesale/sell/offers/${o.id}`, { answer: a, ...extra }, { preserveScroll: true });

    return (
        <li className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold">{o.listing}</p>
                {o.expires_at && <span className="text-xs text-[var(--pt-muted)]">{t('Answer before {when}', { when: dateTime(o.expires_at) })}</span>}
            </div>
            <p className="tabular">
                {num(o.quantity)} × {money(o.offered)} {o.now !== null && <span className="text-[var(--pt-muted)]">({t('today {price}', { price: money(o.now) })})</span>}
            </p>
            <p className="tabular text-[var(--pt-muted)]">{t('If you agree, you are paid {price} each. Our part stays {commission}.', { price: money(o.you_get), commission: money(o.commission) })}</p>
            <div className="flex flex-wrap items-center gap-2">
                <Button size="sm" onClick={() => answer('accept')}>
                    {t('Agree')}
                </Button>
                <Input aria-label={t('Another price')} className="h-8 w-32" inputMode="decimal" placeholder={t('Another price')} value={price} onChange={(e) => setPrice(e.target.value)} />
                <Button size="sm" variant="outline" disabled={price.trim() === ''} onClick={() => answer('counter', { price })}>
                    {t('Name this price')}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => answer('decline')}>
                    {t('No')}
                </Button>
            </div>
        </li>
    );
}

export default function SellerOffers({ offers, reasons, hours }: { offers: Offer[]; reasons: Record<string, number>; hours: number }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const label = (r: string) => t(r === 'price' ? 'The price is too high' : r === 'cheaper' ? 'Found it cheaper elsewhere' : r === 'not_wanted' ? 'Not what they wanted' : r === 'delivery' ? 'The delivery charge is too high' : 'Do not need it now');
    const total = Object.values(reasons).reduce((a, b) => a + b, 0);

    return (
        <PublicLayout>
            <Head title={t('Price offers')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href="/wholesale/sell" className="text-sm underline underline-offset-4">
                    {t('Sell')}
                </Link>
                <div>
                    <SellerBrand />
                    <h1 className="font-display text-3xl font-extrabold">{t('Price offers')}</h1>
                    <p className="text-sm text-[var(--pt-muted)]">{t('A buyer names a price. Agree, name another price, or say no. If nobody answers in {n} hours, the offer is cancelled and the buyer is told.', { n: num(hours) })}</p>
                </div>
                {flash && <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">{flash}</p>}
                <InputError message={errors.offer} />

                {offers.length === 0 ? <p className="rounded-2xl border border-dashed p-8 text-center text-sm text-[var(--pt-muted)]">{t('No offer is waiting.')}</p> : <ul className="space-y-3">{offers.map((o) => <Card key={o.id} o={o} />)}</ul>}

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Why buyers leave without buying')}</h2>
                    {total === 0 ? (
                        <p className="text-sm text-[var(--pt-muted)]">{t('Nobody has told us yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)] text-sm">
                            {Object.entries(reasons).map(([r, n]) => (
                                <li key={r} className="flex justify-between px-4 py-2">
                                    <span>{label(r)}</span>
                                    <span className="tabular font-semibold">{num(n)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </PublicLayout>
    );
}
