import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Listing {
    id: number;
    status: string;
    mpo: string;
    area: string | null;
    alias: string;
    stock_status: string;
    arrive_days: number | null;
    quantity: number | null;
    bonus_buy: number | null;
    bonus_free: number | null;
    markets: string[];
    price_wholesale: number | null;
    price_paikari: number | null;
    price_public: number | null;
    show_bonus: boolean;
    show_quantity: boolean;
    bids: { id: number; who: string | null; phone: string | null; percent: number; boxes: number }[];
}

interface Props {
    groups: { medicine: string; company: string | null; mrp: number; listings: Listing[] }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'MPO', href: '/admin/mpo' },
    { title: 'Products to look at', href: '/admin/mpo/listings' },
];
const MARKETS = [
    ['wholesale', 'Bulk buyers'],
    ['paikari', 'Shops'],
    ['public', 'Everyone'],
] as const;

function Card({ l, mrp }: { l: Listing; mrp: number }) {
    const { t, num, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [markets, setMarkets] = useState<string[]>(l.markets);
    const [price, setPrice] = useState<Record<string, string>>({ wholesale: String(l.price_wholesale ?? ''), paikari: String(l.price_paikari ?? ''), public: String(l.price_public ?? '') });
    const [stock, setStock] = useState(l.stock_status);
    const [days, setDays] = useState(String(l.arrive_days ?? 3));
    const [showBonus, setShowBonus] = useState(l.show_bonus || l.bonus_buy !== null);
    const [showQty, setShowQty] = useState(l.show_quantity);
    const [note, setNote] = useState('');

    return (
        <li className="space-y-2 rounded-md border p-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold">
                    {l.mpo} <span className="text-muted-foreground font-normal">{l.area}</span>
                </p>
                <p className="text-muted-foreground">
                    {t('Buyers see')}: {l.alias}
                </p>
            </div>
            <p className="text-muted-foreground">
                {t(l.stock_status === 'pre_order' ? 'Pre-order' : 'In stock')}
                {l.stock_status === 'pre_order' ? ` · ${t('{n} days', { n: num(l.arrive_days ?? 0) })}` : l.quantity !== null ? ` · ${num(l.quantity)} ${t('boxes')}` : ''}
                {l.bonus_buy ? ` · ${t('Bonus')} ${num(l.bonus_buy)}+${num(l.bonus_free ?? 0)}` : ''}
            </p>
            <div className="grid gap-2 sm:grid-cols-3">
                {MARKETS.map(([k, label]) => (
                    <div key={k} className="rounded border p-2">
                        <label className="flex items-center gap-2 font-medium">
                            <input type="checkbox" checked={markets.includes(k)} onChange={() => setMarkets(markets.includes(k) ? markets.filter((x) => x !== k) : [...markets, k])} />
                            {t(label)}
                        </label>
                        <Input aria-label={`${t(label)} ${t('price per box')}`} className="mt-1 h-8" type="number" min="0" step="any" disabled={!markets.includes(k)} placeholder={`${t('price per box')} (${t('printed')} ${money(mrp)})`} value={price[k]} onChange={(e) => setPrice({ ...price, [k]: e.target.value })} />
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap items-center gap-4">
                <label className="flex items-center gap-2">
                    <input type="radio" name={`st${l.id}`} checked={stock === 'in_stock'} onChange={() => setStock('in_stock')} />
                    {t('In stock')}
                </label>
                <label className="flex items-center gap-2">
                    <input type="radio" name={`st${l.id}`} checked={stock === 'pre_order'} onChange={() => setStock('pre_order')} />
                    {t('Pre-order')}
                </label>
                {stock === 'pre_order' && (
                    <select aria-label={t('Days')} value={days} onChange={(e) => setDays(e.target.value)} className="bg-background h-8 rounded-md border px-2">
                        {[2, 3, 4, 5].map((d) => (
                            <option key={d} value={d}>
                                {t('{n} days', { n: num(d) })}
                            </option>
                        ))}
                    </select>
                )}
                {l.bonus_buy && (
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={showBonus} onChange={(e) => setShowBonus(e.target.checked)} />
                        {t('Show the bonus')}
                    </label>
                )}
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={showQty} onChange={(e) => setShowQty(e.target.checked)} />
                    {t('Show the exact number')}
                </label>
            </div>
            {l.bids.length > 0 && (
                <ul className="rounded bg-muted/40 p-2">
                    {l.bids.map((b) => (
                        <li key={b.id}>
                            {b.who} {b.phone && `(${b.phone})`}: {num(b.percent)}% · {num(b.boxes)} {t('boxes')}
                        </li>
                    ))}
                </ul>
            )}
            <InputError message={errors.listing} />
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            `/admin/mpo/listings/${l.id}/approve`,
                            {
                                markets,
                                price_wholesale: price.wholesale || null,
                                price_paikari: price.paikari || null,
                                price_public: price.public || null,
                                show_bonus: showBonus,
                                show_quantity: showQty,
                                stock_status: stock,
                                arrive_days: stock === 'pre_order' ? days : null,
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    {l.status === 'approved' ? t('Save again') : t('Approve')}
                </Button>
                {l.status === 'submitted' && (
                    <>
                        <Input aria-label={t('Reason')} className="h-8 w-60" placeholder={t('Reason, the MPO will read it')} value={note} onChange={(e) => setNote(e.target.value)} maxLength={200} />
                        <Button size="sm" variant="outline" onClick={() => router.post(`/admin/mpo/listings/${l.id}/reject`, { note }, { preserveScroll: true })}>
                            {t('Decline')}
                        </Button>
                    </>
                )}
            </div>
        </li>
    );
}

export default function MpoListings({ groups }: Props) {
    const { t, money } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Products to look at')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Products to look at')}</h1>
                    <p className="text-muted-foreground text-sm">{t('Choose where each one shows and at what price. The same medicine from several MPOs sits side by side so you can compare.')}</p>
                </header>
                {groups.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center text-sm">{t('Nothing to look at.')}</p>
                ) : (
                    groups.map((g) => (
                        <section key={g.medicine} className="space-y-2">
                            <h2 className="font-medium">
                                {g.medicine} <span className="text-muted-foreground font-normal">{g.company} · {t('printed')} {money(g.mrp)}</span>
                            </h2>
                            <ul className="grid gap-3 lg:grid-cols-2">
                                {g.listings.map((l) => (
                                    <Card key={`${l.id}-${l.status}`} l={l} mrp={g.mrp} />
                                ))}
                            </ul>
                        </section>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
