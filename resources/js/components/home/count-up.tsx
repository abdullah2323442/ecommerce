import { useI18n } from '@/lib/i18n';
import { useEffect, useRef, useState } from 'react';

/**
 * A figure that counts up to its value once, when it scrolls into view. The server draws the final number, so nothing is missing without
 * script, and anyone who asked for less motion just sees the number.
 */
export default function CountUp({ value, className = '' }: { value: number; className?: string }) {
    const { num } = useI18n();
    const ref = useRef<HTMLSpanElement>(null);
    const [shown, setShown] = useState(value);

    useEffect(() => {
        const el = ref.current;

        if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches || el.getBoundingClientRect().top < window.innerHeight) return;

        setShown(0);
        let frame = 0;
        const io = new IntersectionObserver(([entry]) => {
            if (!entry.isIntersecting) return;
            io.disconnect();
            const start = performance.now();
            const tick = (now: number) => {
                const p = Math.min(1, (now - start) / 1400);
                setShown(Math.round(value * (1 - Math.pow(1 - p, 3))));
                if (p < 1) frame = requestAnimationFrame(tick);
            };
            frame = requestAnimationFrame(tick);
        });
        io.observe(el);

        return () => {
            io.disconnect();
            cancelAnimationFrame(frame);
        };
    }, [value]);

    return (
        <span ref={ref} className={className}>
            {num(shown)}
        </span>
    );
}
