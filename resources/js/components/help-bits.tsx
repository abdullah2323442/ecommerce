import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';

export const categoryLabel = (c: string) => (c === 'worker' ? 'Need a worker' : c === 'product' ? 'Looking for a product' : 'Need help');

/** Said plainly on every Help page (spec ch 19): this is only a place to connect. */
export function HelpNotice() {
    const { t } = useI18n();

    return (
        <p role="note" className="rounded-2xl border border-[var(--pt-amber)]/50 bg-[var(--pt-amber)]/10 px-4 py-3 text-sm">
            {t('This board is only a place to meet. What you agree with another person is between the two of you, and we are not responsible for it. Do not pay anyone before you are sure.')}
        </p>
    );
}

export interface ProductCardData {
    id: number;
    gone: boolean;
    name?: string;
    strength?: string | null;
    company?: string | null;
    image?: string | null;
    unit?: 'box' | 'piece';
    price?: number;
}

/** A product linked in a comment shows as a card: photo, name, the viewer's own price and a way to open it (spec ch 19). */
export function ProductCard({ card }: { card: ProductCardData }) {
    const { t, money } = useI18n();

    if (card.gone) {
        return <p className="rounded-xl border border-dashed px-3 py-2 text-sm text-[var(--pt-muted)]">{t('This product is no longer available.')}</p>;
    }

    return (
        <div className="flex items-center gap-3 rounded-xl border border-[var(--pt-line)] bg-[var(--pt-surface)] p-2">
            {card.image ? (
                <img src={card.image} alt="" loading="lazy" className="size-14 rounded-lg object-cover" />
            ) : (
                <div aria-hidden className="size-14 rounded-lg bg-[var(--pt-sage)]" />
            )}
            <div className="min-w-0 flex-1">
                <p className="truncate font-semibold">
                    {card.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{card.strength}</span>
                </p>
                <p className="truncate text-xs text-[var(--pt-muted)]">{card.company}</p>
                <p className="tabular text-sm font-semibold">
                    {money(card.price ?? 0)} <span className="font-normal text-[var(--pt-muted)]">/ {t(card.unit === 'box' ? 'Box' : 'Piece')}</span>
                </p>
            </div>
            <Link href={`/medicines/${card.id}`} className="rounded-full border border-[var(--pt-ink)] px-3 py-1 text-sm font-semibold hover:bg-[var(--pt-ink)] hover:text-[var(--pt-surface)]">
                {t('View')}
            </Link>
        </div>
    );
}
