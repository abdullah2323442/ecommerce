import { type ReactNode, useEffect, useRef, useState } from 'react';

/**
 * A line that draws itself down the page as the visitor scrolls. The path is stretched over the whole
 * wrapper, so it always runs from the top section to the last one. A small bead rides the tip.
 * Visitors who prefer reduced motion see the finished line and no bead.
 */
const PATH = 'M50 0 C92 60 92 130 50 190 S8 320 50 380 S92 510 50 570 S8 700 50 760 S92 890 50 950 L50 1000';

export default function JourneyLine({ children }: { children: ReactNode }) {
    const wrap = useRef<HTMLDivElement>(null);
    const path = useRef<SVGPathElement>(null);
    const [progress, setProgress] = useState(0);
    const [bead, setBead] = useState<{ x: number; y: number } | null>(null);
    const [still, setStill] = useState(false);

    useEffect(() => {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        setStill(reduce);
        if (reduce) {
            setProgress(1);
            return;
        }

        let frame = 0;
        const update = () => {
            frame = 0;
            const el = wrap.current;
            if (!el) return;
            const r = el.getBoundingClientRect();
            // The tip sits 60% of the way down the screen, so it leads what the visitor is reading.
            const p = Math.min(1, Math.max(0, (window.innerHeight * 0.6 - r.top) / r.height));
            setProgress(p);
            const len = path.current?.getTotalLength() ?? 0;
            if (len && path.current) {
                const pt = path.current.getPointAtLength(len * p);
                setBead({ x: pt.x, y: pt.y });
            }
        };
        const onScroll = () => {
            if (!frame) frame = requestAnimationFrame(update);
        };

        update();
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        return () => {
            window.removeEventListener('scroll', onScroll);
            window.removeEventListener('resize', onScroll);
            if (frame) cancelAnimationFrame(frame);
        };
    }, []);

    return (
        <div ref={wrap} className="relative isolate">
            <svg
                aria-hidden
                viewBox="0 0 100 1000"
                preserveAspectRatio="none"
                className="pointer-events-none absolute inset-0 -z-10 h-full w-full overflow-visible"
            >
                <defs>
                    <linearGradient id="journey-grad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stopColor="#c9a24b" />
                        <stop offset="1" stopColor="#b8864b" />
                    </linearGradient>
                </defs>
                {/* the faint track the line is drawn along */}
                <path
                    d={PATH}
                    fill="none"
                    stroke="#c9a24b"
                    strokeOpacity="0.12"
                    strokeWidth="2"
                    strokeDasharray="1 7"
                    strokeLinecap="round"
                    vectorEffect="non-scaling-stroke"
                />
                <path
                    ref={path}
                    d={PATH}
                    pathLength={1}
                    fill="none"
                    stroke="url(#journey-grad)"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeDasharray={1}
                    strokeDashoffset={1 - progress}
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
            {bead && !still && progress > 0 && progress < 1 && (
                <span
                    aria-hidden
                    className="pointer-events-none absolute -z-10 size-4 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#c9a24b] shadow-[0_0_0_6px_rgba(201,162,75,0.18)]"
                    style={{ left: `${bead.x}%`, top: `${bead.y / 10}%` }}
                />
            )}
            {children}
        </div>
    );
}
