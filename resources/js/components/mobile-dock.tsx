import { Link } from '@inertiajs/react';
import { Ellipsis, X, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

export interface DockLink {
    href: string;
    label: string;
    Icon: LucideIcon;
    active: boolean;
    badge?: number;
}

const BASE = 38;
const PEAK = 50;
const REACH = 90;

/**
 * The phone's bottom bar, as a dock. Rest a finger on it, or drag along it, and the icons under and beside the finger swell like a macOS dock, with the
 * name of the nearest one floating above. Every item is a real link, so it works without the effect (a plain tap, a keyboard, a screen reader).
 * The swell is plain CSS sizing with an overshoot easing, so it needs no animation library, and it is switched off for reduced motion.
 */
export interface MoreLink {
    href: string;
    label: string;
    on: boolean;
}

export default function MobileDock({ items, label, more }: { items: DockLink[]; label: string; more?: { label: string; links: MoreLink[] } }) {
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) return;
        const key = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
        window.addEventListener('keydown', key);

        return () => window.removeEventListener('keydown', key);
    }, [open]);

    const refs = useRef<(HTMLLIElement | null)[]>([]);
    const [sizes, setSizes] = useState<number[] | null>(null);
    const [near, setNear] = useState(-1);

    const touch = (x: number) => {
        const next = refs.current.map((el) => {
            if (!el) return BASE;
            const r = el.getBoundingClientRect();
            const d = Math.abs(x - (r.left + r.width / 2));

            return BASE + (PEAK - BASE) * Math.max(0, 1 - d / REACH) ** 2;
        });
        setSizes(next);
        setNear(next.indexOf(Math.max(...next)));
    };

    const rest = () => {
        setSizes(null);
        setNear(-1);
    };

    return (
        <nav
            aria-label={label}
            className="fixed inset-x-0 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-40 flex justify-center px-3 md:hidden"
        >
            <ul
                onPointerMove={(e) => touch(e.clientX)}
                onPointerDown={(e) => touch(e.clientX)}
                onPointerLeave={rest}
                onPointerUp={rest}
                onPointerCancel={rest}
                className="flex max-w-full touch-pan-y items-end gap-1 rounded-[1.75rem] border border-white/60 bg-[var(--pt-surface)]/85 px-3 pt-2 pb-2 shadow-[0_18px_40px_-12px_rgba(14,13,12,.45)] backdrop-blur-xl dark:border-white/10"
            >
                {items.map(({ href, label: name, Icon, active, badge }, i) => {
                    const size = sizes?.[i] ?? BASE;

                    return (
                        <li
                            key={href}
                            ref={(el) => {
                                refs.current[i] = el;
                            }}
                            className="relative"
                        >
                            {near === i && (
                                <span
                                    role="presentation"
                                    className="pointer-events-none absolute -top-10 left-1/2 -translate-x-1/2 rounded-md bg-[var(--pt-ink)] px-2.5 py-1 text-xs whitespace-nowrap text-[var(--pt-paper)]"
                                >
                                    {name}
                                </span>
                            )}
                            <Link
                                href={href}
                                aria-label={name}
                                aria-current={active ? 'page' : undefined}
                                style={{ width: size, height: size }}
                                className={`dock-item relative grid place-items-center rounded-full border outline-none focus-visible:ring-2 focus-visible:ring-[var(--pt-ink)] ${
                                    active
                                        ? 'border-[var(--pt-deep)] bg-[var(--pt-deep)] text-white'
                                        : 'border-[var(--pt-line)] bg-[var(--pt-paper)] text-[var(--pt-ink)]'
                                }`}
                            >
                                <Icon aria-hidden className="size-5" strokeWidth={active ? 2.2 : 1.7} />
                                {badge ? (
                                    <span className="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-[var(--art-gold-2)] px-1 text-[0.65rem] leading-5 font-bold text-[var(--art-night)]">
                                        {badge}
                                    </span>
                                ) : null}
                            </Link>
                            <span
                                aria-hidden
                                className={`mx-auto mt-1 block size-1 rounded-full ${active ? 'bg-[var(--art-gold-2)]' : 'bg-transparent'}`}
                            />
                        </li>
                    );
                })}
                {more && (
                    <li className="relative">
                        <button
                            type="button"
                            aria-label={more.label}
                            aria-haspopup="dialog"
                            aria-expanded={open}
                            onClick={() => setOpen(true)}
                            style={{ width: BASE, height: BASE }}
                            className="dock-item grid place-items-center rounded-full border border-[var(--pt-line)] bg-[var(--pt-paper)] text-[var(--pt-ink)] outline-none focus-visible:ring-2 focus-visible:ring-[var(--pt-ink)]"
                        >
                            <Ellipsis aria-hidden className="size-5" strokeWidth={1.7} />
                        </button>
                        <span aria-hidden className="mx-auto mt-1 block size-1" />
                    </li>
                )}
            </ul>
            {more && open && (
                <div className="fixed inset-0 z-50 flex items-end bg-black/50" onClick={() => setOpen(false)}>
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-label={more.label}
                        onClick={(e) => e.stopPropagation()}
                        className="max-h-[80vh] w-full overflow-y-auto rounded-t-[1.75rem] bg-[var(--pt-paper)] px-5 pt-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))] text-[var(--pt-ink)]"
                    >
                        <div className="mb-3 flex items-center justify-between">
                            <p className="text-xl" style={{ fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" }}>
                                {more.label}
                            </p>
                            <button
                                type="button"
                                autoFocus
                                aria-label="Close"
                                onClick={() => setOpen(false)}
                                className="grid size-11 place-items-center rounded-full border border-[var(--pt-line)]"
                            >
                                <X aria-hidden className="size-5" />
                            </button>
                        </div>
                        <ul className="divide-y divide-[var(--pt-line)]">
                            {more.links.map((l) => (
                                <li key={l.href + l.label}>
                                    <Link
                                        href={l.href}
                                        onClick={() => setOpen(false)}
                                        aria-current={l.on ? 'page' : undefined}
                                        className={`flex h-12 items-center ${l.on ? 'font-semibold' : ''}`}
                                    >
                                        {l.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </nav>
    );
}
