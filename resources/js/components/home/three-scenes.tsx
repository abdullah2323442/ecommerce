import { lazy, Suspense, useEffect, useState } from 'react';

// Each scene is its own chunk: three.js loads after the page is up and never blocks first paint.
const DustField = lazy(() => import('./dust-field'));
const GemOrb = lazy(() => import('./gem-orb'));

/** True once the page has had a moment to paint, the device has WebGL and the visitor has not asked for less motion. */
function useScene(delay: number) {
    const [ok, setOk] = useState(false);

    useEffect(() => {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let webgl = false;
        try {
            webgl = !!document.createElement('canvas').getContext('webgl2');
        } catch {
            webgl = false;
        }
        const id = window.setTimeout(() => setOk(!reduce && webgl), delay);
        return () => window.clearTimeout(id);
    }, [delay]);

    return ok;
}

/** Drifting gold dust behind every public page. */
export function DustBackdrop() {
    const ok = useScene(900);

    return ok ? (
        <Suspense fallback={null}>
            <div className="animate-[ether-in_2s_ease-out_both]">
                <DustField />
            </div>
        </Suspense>
    ) : null;
}

/** A glass gem for the hero. */
export function HeroGem({ className = '' }: { className?: string }) {
    const ok = useScene(700);

    return ok ? (
        <Suspense fallback={null}>
            <div aria-hidden className={`pointer-events-none animate-[ether-in_1.6s_ease-out_both] ${className}`}>
                <GemOrb />
            </div>
        </Suspense>
    ) : null;
}
