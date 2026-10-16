import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export interface OfferCardData {
    id: number;
    name: string;
    strength: string | null;
    company?: string | null;
    min_boxes: number;
    step_percent: number;
    mrp: number;
    price: number;
    state: 'live' | 'sold_out';
    stock: number | null;
    weight_kg: number | null;
    delivery_charge: number | null;
}

/**
 * One offer, shown under the normal medicine. Boxes only. A sold-out offer stays for a while, greyed, and cannot be
 * ordered. The box count is only shown when the admin chose to show it; the limit is enforced on the server either way.
 */
export default function OfferCard({ offer, postTo, showName = false }: { offer: OfferCardData; postTo: string; showName?: boolean }) {
    const { t, money, num } = useI18n();
    const [boxes, setBoxes] = useState(String(offer.min_boxes));
    const gone = offer.state === 'sold_out';

    return (
        <div
            className={`flex flex-wrap items-center gap-3 rounded-xl border px-3 py-2 ${gone ? 'bg-muted/40 text-muted-foreground border-dashed grayscale' : 'border-[var(--pt-amber)]/60 bg-[var(--pt-amber)]/10'}`}
        >
            <span className="rounded-full bg-[var(--pt-deep)] px-2.5 py-0.5 text-xs font-bold tracking-wide text-[var(--pt-amber)]">
                {t('OFFER')}
            </span>
            <div className="min-w-40 flex-1">
                {showName && (
                    <p className="font-semibold">
                        {offer.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{offer.strength}</span>
                    </p>
                )}
                {gone ? (
                    <p className="text-sm font-semibold">{t('Was on offer a moment ago. Stock out.')}</p>
                ) : (
                    <p className="text-sm">
                        <span className="tabular font-semibold">{money(offer.price)}</span> {t('a box')} ·{' '}
                        {t('minimum {n} boxes', { n: num(offer.min_boxes) })}
                        {offer.step_percent > 0 && ` · ${t('{p}% more for each extra box', { p: num(offer.step_percent) })}`}
                        {offer.stock !== null ? ` · ${t('{n} boxes available', { n: num(offer.stock) })}` : ` · ${t('In stock')}`}
                    </p>
                )}
            </div>
            {!gone && (
                <>
                    <label className="sr-only" htmlFor={`ob-${offer.id}`}>
                        {t('Boxes')}
                    </label>
                    <Input
                        id={`ob-${offer.id}`}
                        className="h-9 w-20"
                        type="number"
                        min="1"
                        step="1"
                        max={offer.stock ?? undefined}
                        value={boxes}
                        onChange={(e) => setBoxes(e.target.value)}
                    />
                    <Button size="sm" disabled={Number(boxes) < 1} onClick={() => router.post(postTo, { boxes }, { preserveScroll: true })}>
                        {t('Order')}
                    </Button>
                </>
            )}
        </div>
    );
}
