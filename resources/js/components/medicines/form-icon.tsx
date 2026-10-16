import { type ReactNode } from 'react';

const stroke = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.6, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

/** Fine line glyphs, one per kind of medicine form. Decorative: the form is always also written in words. */
const glyphs: Record<string, ReactNode> = {
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
            <path d="M28 6l14 14M31 9l-3 3M24 16 10 30l-2 10 10-2 14-14M23 21l4 4" />
        </svg>
    ),
    tube: (
        <svg viewBox="0 0 48 48" {...stroke}>
            <path d="M12 8h24l-3 8H15zM15 16 13 40h22l-2-24M20 40v3h8v-3M18 28h12" />
        </svg>
    ),
};

export function glyphFor(form: string | null): ReactNode {
    const f = (form ?? '').toLowerCase();
    if (f.includes('tablet')) return glyphs.tablet;
    if (f.includes('injection') || f.includes('infusion')) return glyphs.syringe;
    if (f.includes('drop')) return glyphs.dropper;
    if (['cream', 'ointment', 'gel', 'lotion'].some((w) => f.includes(w))) return glyphs.tube;
    if (['syrup', 'suspension', 'solution', 'elixir', 'liquid'].some((w) => f.includes(w))) return glyphs.bottle;
    return glyphs.capsule;
}

export function FormIcon({ form, className = 'size-5' }: { form: string | null; className?: string }) {
    return (
        <span aria-hidden className={`inline-flex shrink-0 [&>svg]:size-full ${className}`}>
            {glyphFor(form)}
        </span>
    );
}
