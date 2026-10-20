import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { Check } from 'lucide-react';

export interface Unresolved {
    key: string;
    count: number;
    who?: string | null;
    minutes?: number;
    amount?: number;
    href: string;
}

/** Everything still hanging when the shop closes, each with a link to deal with it (spec 6.6). */
export default function UnresolvedList({ items, hide = [] }: { items: Unresolved[]; hide?: string[] }) {
    const { t, num, money } = useI18n();
    const shown = items.filter((i) => !hide.includes(i.key));

    const text = (i: Unresolved) => {
        switch (i.key) {
            case 'drafts':
                return i.who ? t('{who} has {n} drafts open', { who: i.who, n: num(i.count) }) : t('{n} drafts open', { n: num(i.count) });
            case 'notes':
                return t('{n} quick notes not done', { n: num(i.count) });
            case 'orders':
                return t('{n} online orders waiting', { n: num(i.count) });
            case 'preorders':
                return t('{n} pre-orders need an answer', { n: num(i.count) });
            case 'approvals':
                return t('{n} requests waiting for the admin', { n: num(i.count) });
            case 'mismatches':
                return t('{n} mismatch reports not looked at', { n: num(i.count) });
            case 'unpaid':
                return t('{n} sales today not fully paid, {amount} to collect', { n: num(i.count), amount: money(i.amount ?? 0) });
            default:
                return t("Today's cash is not counted yet");
        }
    };

    if (shown.length === 0) {
        return (
            <p className="flex items-center gap-2 rounded-md border border-success/30 bg-success/10 px-4 py-3 text-sm text-success">
                <Check aria-hidden className="size-4" />
                {t('Nothing is left open. You can close.')}
            </p>
        );
    }

    return (
        <section aria-labelledby="open-items-h" className="space-y-2">
            <h2 id="open-items-h" className="font-medium">
                {t('Still open')}
            </h2>
            <ul className="divide-y rounded-md border bg-card text-sm">
                {shown.map((i, n) => (
                    <li key={`${i.key}-${n}`}>
                        <Link href={i.href} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 hover:bg-muted/40">
                            <span>{text(i)}</span>
                            {i.key === 'drafts' && (i.minutes ?? 0) >= 60 && <span className="text-xs text-warning">{t('Oldest open for {m} minutes', { m: num(i.minutes ?? 0) })}</span>}
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
