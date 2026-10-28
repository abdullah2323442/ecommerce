import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export interface MpoOffer {
    id: number;
    seller: string;
    price: number;
    bonus: { buy: number; free: number } | null;
    stock: 'in_stock' | 'pre_order';
    days: number | null;
    quantity: number | null;
    can_bid?: boolean;
    my_bid?: { id: number; percent: number; boxes: number } | null;
}

function Row({ o, onChanged }: { o: MpoOffer; onChanged?: () => void }) {
    const { t, money, num } = useI18n();
    const [boxes, setBoxes] = useState('1');
    const [bidding, setBidding] = useState(false);
    const [percent, setPercent] = useState('');

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 py-2">
            <div className="min-w-48 flex-1">
                <p className="font-semibold">{o.seller}</p>
                <p className="text-sm text-[var(--pt-muted)]">
                    {o.stock === 'pre_order' ? t('Pre-order · arrives in {n} days', { n: num(o.days ?? 0) }) : o.quantity !== null ? t('In stock · {n} boxes', { n: num(o.quantity) }) : t('In stock')}
                    {o.bonus && ` · ${t('Take {buy}, get {free} free', { buy: num(o.bonus.buy), free: num(o.bonus.free) })}`}
                </p>
            </div>
            <span className="tabular font-semibold">
                {money(o.price)} <span className="text-xs font-normal text-[var(--pt-muted)]">/ {t('box')}</span>
            </span>
            <Input aria-label={t('Boxes')} className="h-9 w-20" type="number" min="1" step="1" value={boxes} onChange={(e) => setBoxes(e.target.value)} />
            <Button size="sm" onClick={() => router.post(`/mpo-offers/${o.id}/order`, { boxes: Math.max(1, Math.round(Number(boxes))) })}>
                {t('Order')}
            </Button>
            {o.can_bid && !o.my_bid && (
                <Button size="sm" variant="outline" onClick={() => setBidding(!bidding)}>
                    {t('Offer my price')}
                </Button>
            )}
            {o.my_bid && (
                <p className="flex w-full items-center gap-3 text-sm">
                    <span className="flex-1">{t('Your offer: {p}% off, {n} boxes. Waiting.', { p: num(o.my_bid.percent), n: num(o.my_bid.boxes) })}</span>
                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/mpo-bids/${o.my_bid!.id}`, { preserveScroll: true, onSuccess: () => onChanged?.() })}>
                        {t('Withdraw')}
                    </Button>
                </p>
            )}
            {bidding && (
                <div className="flex w-full flex-wrap items-center gap-2 text-sm">
                    <label className="flex items-center gap-1">
                        {t('Discount I ask for (%)')}
                        <Input className="h-9 w-24" type="number" min="1" max="90" step="any" value={percent} onChange={(e) => setPercent(e.target.value)} />
                    </label>
                    <Button size="sm" disabled={percent === ''} onClick={() => router.post(`/mpo-offers/${o.id}/bid`, { percent, boxes: Math.max(1, Math.round(Number(boxes))) }, { preserveScroll: true, onSuccess: () => { setBidding(false); onChanged?.(); } })}>
                        {t('Send my offer')}
                    </Button>
                    <span className="text-[var(--pt-muted)]">{t('Nobody else sees your offer.')}</span>
                </div>
            )}
        </li>
    );
}

/** Other sellers of the same medicine, always shown as "Anonymous N (store)". The price is the viewer's own market's. `onChanged` lets a page that holds its own search results read them again after an offer is sent or taken back. */
export default function MpoOffers({ offers, signedIn, onChanged }: { offers: MpoOffer[]; signedIn: boolean; onChanged?: () => void }) {
    const { t } = useI18n();

    if (offers.length === 0) return null;

    return (
        <section aria-labelledby="mpo-h" className="mt-6 max-w-xl space-y-1 rounded-2xl border border-[var(--pt-line)] p-4">
            <h2 id="mpo-h" className="font-display text-lg font-bold">
                {t('Other offers')}
            </h2>
            {signedIn ? (
                <ul className="divide-y">
                    {offers.map((o) => (
                        <Row key={o.id} o={o} onChanged={onChanged} />
                    ))}
                </ul>
            ) : (
                <>
                    <ul className="divide-y">
                        {offers.map((o) => (
                            <li key={o.id} className="flex items-center justify-between gap-3 py-2">
                                <span className="font-semibold">{o.seller}</span>
                                <span className="tabular font-semibold">{money0(o.price)}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="text-sm text-[var(--pt-muted)]">{t('Log in to order.')}</p>
                </>
            )}
        </section>
    );
}

const money0 = (n: number) => `৳${n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
