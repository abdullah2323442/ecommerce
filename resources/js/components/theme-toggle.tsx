import { useAppearance } from '@/hooks/use-appearance';
import { useI18n } from '@/lib/i18n';
import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * One button that switches the office between light and dark. It shows the mode you will get (a moon in light mode, a sun in dark mode), remembers the
 * choice on this device, and starts from the device's own setting the first time.
 */
export default function ThemeToggle({ className = '' }: { className?: string }) {
    const { t } = useI18n();
    const { appearance, updateAppearance } = useAppearance();
    const [dark, setDark] = useState(false);

    // What is actually showing (the saved choice may be "system").
    useEffect(() => setDark(document.documentElement.classList.contains('dark')), [appearance]);

    return (
        <button
            type="button"
            role="switch"
            aria-checked={dark}
            aria-label={dark ? t('Switch to light mode') : t('Switch to dark mode')}
            title={dark ? t('Light mode') : t('Dark mode')}
            onClick={() => updateAppearance(dark ? 'light' : 'dark')}
            className={`relative inline-flex h-9 w-[4.25rem] shrink-0 items-center rounded-full border border-border bg-muted/60 p-1 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${className}`}
        >
            <Sun aria-hidden className="absolute left-2.5 size-4 text-warning" />
            <Moon aria-hidden className="absolute right-2.5 size-4 text-info" />
            <span aria-hidden className={`relative z-10 size-7 rounded-full bg-background shadow-sm ring-1 ring-border transition-transform duration-200 motion-reduce:transition-none ${dark ? 'translate-x-[1.9rem]' : 'translate-x-0'}`} />
        </button>
    );
}
