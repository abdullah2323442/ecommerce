import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const applyTheme = (appearance: Appearance) => {
    // The public storefront is always light; dark mode only applies to the signed-in admin and settings screens.
    const staffArea = /^\/(admin|settings|dashboard)(\/|$)/.test(window.location.pathname);
    const isDark = staffArea && (appearance === 'dark' || (appearance === 'system' && prefersDark()));

    document.documentElement.classList.toggle('dark', isDark);
};

const read = (): Appearance => {
    try {
        return (localStorage.getItem('appearance') as Appearance) || 'light';
    } catch {
        return 'light';
    }
};

/** The first paint is already themed by an inline script in app.blade.php; this keeps it in step afterwards. */
export function initializeTheme() {
    applyTheme(read());
    document.addEventListener('inertia:navigate', () => applyTheme(read()));
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme(read()));
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('light');

    useEffect(() => setAppearance(read()), []);

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        try {
            localStorage.setItem('appearance', mode);
        } catch {
            /* private mode: applies to this page only */
        }
        applyTheme(mode);
    };

    return { appearance, updateAppearance };
}
