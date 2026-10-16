import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

export interface FormTile {
    slug: string;
    name: string;
    count: number;
}

const stroke = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

/** Fine line icons, one per kind of medicine form. */
const icons: Record<string, ReactNode> = {
    tablet: (
        <svg viewBox="0 0 48 48" {...stroke}>
            <circle cx="24" cy="24" r="14" />
            <path d="M12 24h24" />
        </svg>
    ),
    capsule: (
        <svg viewBox="0 0 48 48" {...stroke}>
            <rect x="8" y="17" width="32" height="14" rx="7" transform="rotate(-35 24 24)" />
            <path d="m19 15 10 18" />
        </svg>
    ),
    syringe: (
        <svg viewBox="0 0 48 48" {...stroke}>
            <path d="m30 8 10 10M33 13l-17 17-4 8 8-4 17-17M26 22l-3-3M20 28l-3-3M9 39l-4 4" />
        </svg>
    ),
    bottle: (
        <svg viewBox="0 0 48 48" {...stroke}>
            <path d="M19 6h10M20 6v7l-5 6v21a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V19l-5-6V6M15 28h18" />
        </svg>
    ),
    dropper: (
        <svg viewBox="0 0 48 48" {...stroke}>
            <path d="M28 6l14 14M31 9l-3 3M24 16 10 30l-2 10 10-2 14-14M23 21l4 4M24 40c2-2 3-4 3-6" />
        </svg>
    ),
    tube: (
        <svg viewBox="0 0 48 48" {...stroke}>
            <path d="M12 8h24l-3 8H15zM15 16 13 40h22l-2-24M20 40v3h8v-3M18 28h12" />
        </svg>
    ),
};

export function iconFor(slug: string): ReactNode {
    if (slug.includes('tablet')) return icons.tablet;
    if (slug === 'injection') return icons.syringe;
    if (slug.includes('drop')) return icons.dropper;
    if (['cream', 'ointment', 'gel', 'lotion'].some((w) => slug.includes(w))) return icons.tube;
    if (['syrup', 'suspension', 'solution', 'elixir'].some((w) => slug.includes(w))) return icons.bottle;
    return icons.capsule;
}

/** A row of round icon buttons, the way a department strip reads on a phone: swipe along, tap one. */
export default function FormTiles({ forms }: { forms: FormTile[] }) {
    const { t, num } = useI18n();

    return (
        <ul className="-mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:grid sm:grid-cols-4 sm:overflow-visible sm:px-0 lg:grid-cols-8">
            {forms.map((f) => (
                <li key={f.slug} className="w-28 shrink-0 snap-start sm:w-auto">
                    <Link
                        href={route('forms.show', f.slug)}
                        className="group flex flex-col items-center gap-3 rounded-3xl border border-[var(--pt-line)] bg-[var(--pt-surface)] px-3 py-5 text-center transition-[border-color,box-shadow] duration-150 outline-none hover:border-[var(--pt-amber)] hover:shadow-lg focus-visible:ring-4 focus-visible:ring-[var(--pt-amber)]/40"
                    >
                        <span className="flex size-14 items-center justify-center rounded-full bg-[var(--pt-sage)] p-3.5 text-[var(--pt-teal)] transition-colors group-hover:bg-[var(--pt-deep)] group-hover:text-[#e7cf9a]">
                            {iconFor(f.slug)}
                        </span>
                        <span>
                            <span className="font-display block text-lg leading-tight font-bold capitalize">{t(f.name)}</span>
                            <span className="tabular mt-0.5 block text-sm text-[var(--pt-muted)]">{num(f.count)}</span>
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}
