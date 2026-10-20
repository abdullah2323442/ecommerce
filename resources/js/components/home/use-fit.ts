import { useLayoutEffect, useState } from 'react';

/**
 * Below the design width the home page is a miniature of its desktop self: it lays out at the design width and is scaled down to the window,
 * rather than rearranging itself. Above the design width it is left alone and fills the window.
 */
export function useFit(designWidth: number): number {
    const [scale, setScale] = useState(1);

    useLayoutEffect(() => {
        const measure = () => setScale(Math.min(1, document.documentElement.clientWidth / designWidth));
        measure();
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    }, [designWidth]);

    return scale;
}
