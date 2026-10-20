import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Offer {
    id: number;
    listing_id: number;
    listing: string;
    quantity: number;
    offered: number;
    status: 'waiting' | 'accepted' | 'countered' | 'declined' | 'expired' | 'ordered' | 'withdrawn' | 'noted';
    agreed: number | null;
    expires_at: string | null;
    at: string;
}

function OrderBox({ o, districts }: { o: Offer; districts: string[] }) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', phone: '', district: districts[0] ?? '', area: '', address: '', how_to_find: '', note: '' });
    const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });

    return (
        <form
            className="mt-3 grid gap-2 rounded-[1.25rem] border bg-background p-3 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(`/market-offers/${o.id}/order`, f);
            }}
        >
            <p className="text-sm font-semibold sm:col-span-2">{t('Order at {price} each', { price: money(o.agreed ?? 0) })}</p>
            <Input aria-label={t('Name')} placeholder={t('Name')} value={f.name} onChange={set('name')} />
            <Input aria-label={t('Mobile number')} placeholder={t('Mobile number')} inputMode="tel" value={f.phone} onChange={set('phone')} />
            <select aria-label={t('District')} value={f.district} onChange={set('district')} className="h-9 rounded-md border bg-background px-2 text-sm">
                {districts.map((d) => (
                    <option key={d} value={d}>
                        {d}
                    </option>
                ))}
            </select>
            <Input aria-label={t('Area')} placeholder={t('Area')} value={f.area} onChange={set('area')} />
            <Input className="sm:col-span-2" aria-label={t('Address')} placeholder={t('Address')} value={f.address} onChange={set('address')} />
            <InputError className="sm:col-span-2" message={errors.order ?? errors.phone ?? errors.address} />
            <Button type="submit" className="sm:col-span-2">
                {t('Send the order')}
            </Button>
        </form>
    );
}

export default function MarketOffers({ offers, districts }: { offers: Offer[]; districts: string[] }) {
    const { t, money, num, dateTime } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const state = (o: Offer) =>
        t(o.status === 'waiting' ? 'Waiting for the seller' : o.status === 'accepted' ? 'The seller agreed' : o.status === 'countered' ? 'The seller named another price' : o.status === 'declined' ? 'Not taken' : o.status === 'expired' ? 'Ran out' : o.status === 'ordered' ? 'Ordered' : 'Withdrawn');

    return (
        <PublicLayout>
            <Head title={t('Your price offers')} />
            <div className="mx-auto max-w-3xl space-y-4">
                <h1 className="font-display text-3xl font-extrabold">{t('Your price offers')}</h1>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                {offers.length === 0 ? (
                    <p className="rounded-[1.5rem] border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('You have made no offer yet.')}</p>
                ) : (
                    <ul className="space-y-3">
                        {offers.map((o) => (
                            <li key={o.id} className="rounded-[1.5rem] border bg-[var(--pt-surface)] p-4 text-sm">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <Link href={`/market/${o.listing_id}`} className="font-semibold hover:underline">
                                        {o.listing}
                                    </Link>
                                    <span className="font-semibold">{state(o)}</span>
                                </div>
                                <p className="tabular text-[var(--pt-muted)]">
                                    {num(o.quantity)} × {money(o.offered)} · {dateTime(o.at)}
                                </p>
                                {(o.status === 'accepted' || o.status === 'countered') && o.agreed !== null && (
                                    <>
                                        <p className="mt-1 tabular">{o.status === 'accepted' ? t('Agreed price: {price} each', { price: money(o.agreed) }) : t('The seller asks {price} each', { price: money(o.agreed) })}</p>
                                        <OrderBox o={o} districts={districts} />
                                    </>
                                )}
                                {['waiting', 'accepted', 'countered'].includes(o.status) && (
                                    <Button size="sm" variant="ghost" className="mt-2" onClick={() => router.post(`/market-offers/${o.id}/withdraw`, {}, { preserveScroll: true })}>
                                        {t('Withdraw')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
