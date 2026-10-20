import { useI18n } from '@/lib/i18n';
import { BadgeCheck, Headset, RotateCcw, ShieldCheck, Truck } from 'lucide-react';

export interface FrontBlock {
    id: number;
    kind: string;
    title: string | null;
    text: string | null;
    button: string | null;
    image?: string | null;
    items?: { title: string; text: string }[];
    [key: string]: unknown;
}

export interface Front {
    blocks: FrontBlock[];
    [key: string]: unknown;
}

export const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

const ICONS = [Truck, BadgeCheck, RotateCcw, Headset, ShieldCheck];

/** A section heading in the display face, with an optional line under it. */
export function SectionHead({ title, text, className = '' }: { title: string | null; text?: string | null; className?: string }) {
    if (!title) return null;

    return (
        <div className={className}>
            <h2 style={DISPLAY} className="text-4xl leading-none text-balance sm:text-5xl">
                {title}
            </h2>
            {text && <p className="mt-3 max-w-xl opacity-70">{text}</p>}
        </div>
    );
}

/** One bordered row of promises, each with an icon, a short title and a line. */
export function ServiceStrip({ items, label }: { items: { title: string; text: string }[]; label: string }) {
    return (
        <ul
            aria-label={label}
            className="grid gap-px overflow-hidden rounded-2xl border border-[var(--rule)] bg-[var(--rule)] sm:grid-cols-2 lg:grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]"
        >
            {items.map((i, n) => {
                const Icon = ICONS[n % ICONS.length];

                return (
                    <li key={`${i.title}-${n}`} className="bg-[var(--surface)] p-6">
                        <Icon aria-hidden className="size-6" strokeWidth={1.5} />
                        <p className="mt-4 font-semibold">{i.title}</p>
                        {i.text && <p className="mt-1 text-sm opacity-70">{i.text}</p>}
                    </li>
                );
            })}
        </ul>
    );
}

/** Questions that open in place. The first heading sits at the side on a wide screen. */
export function FaqList({ title, items }: { title: string | null; items: { title: string; text: string }[] }) {
    return (
        <section aria-label={title ?? undefined} className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-16">
            {title && (
                <h2 style={DISPLAY} className="text-4xl leading-none text-balance sm:text-5xl">
                    {title}
                </h2>
            )}
            <div className="border-t border-[var(--rule)]">
                {items.map((i, n) => (
                    <details key={`${i.title}-${n}`} className="group border-b border-[var(--rule)]">
                        <summary className="flex min-h-16 cursor-pointer list-none items-center justify-between gap-4 py-4 text-lg font-semibold outline-none focus-visible:ring-2 focus-visible:ring-current [&::-webkit-details-marker]:hidden">
                            {i.title}
                            <span aria-hidden className="text-2xl leading-none transition-transform duration-300 group-open:rotate-45">
                                +
                            </span>
                        </summary>
                        {i.text && <p className="max-w-prose pb-5 opacity-70">{i.text}</p>}
                    </details>
                ))}
            </div>
        </section>
    );
}

/** A real sequence, so the numbers mean something: big numerals on a rule, the step under each. */
export function StepsList({ title, items, accent }: { title: string | null; items: { title: string; text: string }[]; accent: string }) {
    const { num } = useI18n();

    return (
        <section aria-label={title ?? undefined}>
            <SectionHead title={title} />
            <ol className="mt-8 grid gap-8 sm:grid-cols-[repeat(auto-fit,minmax(14rem,1fr))]">
                {items.map((i, n) => (
                    <li key={`${i.title}-${n}`} className="border-t border-[var(--rule)] pt-5">
                        <span style={{ ...DISPLAY, color: accent }} className="tabular text-5xl leading-none">
                            {num(n + 1)}
                        </span>
                        <p className="mt-4 text-lg font-semibold">{i.title}</p>
                        {i.text && <p className="mt-1 opacity-70">{i.text}</p>}
                    </li>
                ))}
            </ol>
        </section>
    );
}
