import { useI18n } from '@/lib/i18n';
import { Timer } from 'lucide-react';

/** What a product cost before its flash sale. Struck through, and read aloud as such. */
export function WasPrice({ was, className = '' }: { was: number | null | undefined; className?: string }) {
    const { t, money } = useI18n();

    if (was == null) return null;

    return (
        <>
            <span className="sr-only">{t('Was')} </span>
            <del className={`tabular font-normal text-[var(--pt-muted)] ${className}`}>{money(was)}</del>{' '}
        </>
    );
}

/** The percent off and when the sale ends, for the product page and the card. */
export function SaleTag({ percent, endsAt, long = false }: { percent: number | null | undefined; endsAt: string | null | undefined; long?: boolean }) {
    const { t, num, dateTime } = useI18n();

    if (percent == null) return null;

    return (
        <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1 rounded-full border border-[var(--pt-ink)] px-2.5 py-0.5 text-xs font-semibold">
            <Timer aria-hidden className="size-3.5" />
            <span>{t('{n}% off', { n: num(percent) })}</span>
            {long && endsAt && <span className="font-normal">{t('Sale ends {when}', { when: dateTime(endsAt) })}</span>}
        </span>
    );
}
