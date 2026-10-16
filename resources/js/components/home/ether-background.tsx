import { lazy, Suspense, useEffect, useState } from 'react';

// three.js is heavy, so it loads on its own after the page is up and never blocks first paint.
const LiquidEther = lazy(() => import('./liquid-ether'));

// Module constants: the simulation is rebuilt whenever the colours array changes identity.
const HERO_COLORS = ['#4a3814', '#8a6a2b', '#e8c97a', '#fff3d6'];
const FOOTER_COLORS = ['#2a210c', '#8a6a2b', '#e8c97a', '#fff3d6'];

/**
 * A living fluid backdrop for a dark section. It sits behind the content, fades in once ready, and is skipped
 * for visitors who prefer reduced motion or whose device has no WebGL, so the plain colour underneath stays.
 */
export default function EtherBackground({ variant = 'hero', className = '' }: { variant?: 'hero' | 'footer'; className?: string }) {
    const [ready, setReady] = useState(false);

    useEffect(() => {
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let webgl = false;
        try {
            webgl = !!document.createElement('canvas').getContext('webgl2');
        } catch {
            webgl = false;
        }
        // Start a moment after load so the fluid never competes with the first paint.
        const start = () => setReady(!reduce && webgl);
        const id = window.setTimeout(start, 600);
        return () => window.clearTimeout(id);
    }, []);

    if (!ready) return null;

    const hero = variant === 'hero';

    return (
        <div aria-hidden className={`pointer-events-none absolute inset-0 -z-10 animate-[ether-in_1.6s_ease-out_both] ${className}`}>
            <Suspense fallback={null}>
                <LiquidEther
                    colors={hero ? HERO_COLORS : FOOTER_COLORS}
                    mouseForce={hero ? 22 : 16}
                    cursorSize={hero ? 130 : 110}
                    resolution={hero ? 0.5 : 0.35}
                    autoDemo
                    autoSpeed={hero ? 0.45 : 0.3}
                    autoIntensity={hero ? 2.4 : 1.8}
                    BFECC
                />
            </Suspense>
        </div>
    );
}
