import { useI18n } from '@/lib/i18n';
import { useEffect, useState } from 'react';

const STEPS = [
    { key: 'normal', scale: 100, label: 'Normal text size', size: 'text-xs' },
    { key: 'large', scale: 115, label: 'Large text', size: 'text-sm' },
    { key: 'larger', scale: 130, label: 'Largest text', size: 'text-base' },
] as const;

const STORE = 'text-size';

/** So older people can read (spec ch 27): three sizes, remembered on this device. Everything is in rem, so the whole page grows. */
export default function TextSize({ force = false }: { force?: boolean }) {
    const { t } = useI18n();
    const [scale, setScale] = useState(100);

    useEffect(() => {
        try {
            const saved = Number(localStorage.getItem(STORE));
            if (STEPS.some((s) => s.scale === saved)) setScale(saved);
        } catch {
            /* storage can be blocked; the default size still works */
        }
    }, []);

    // The header and the footer each have one; keep them agreeing.
    useEffect(() => {
        const sync = (e: Event) => setScale((e as CustomEvent<number>).detail);
        window.addEventListener('text-size', sync);
        return () => window.removeEventListener('text-size', sync);
    }, []);

    useEffect(() => {
        document.documentElement.style.fontSize = scale === 100 ? '' : `${scale}%`;
    }, [scale]);

    const choose = (value: number) => {
        setScale(value);
        window.dispatchEvent(new CustomEvent('text-size', { detail: value }));
        try {
            localStorage.setItem(STORE, String(value));
        } catch {
            /* ignore */
        }
    };

    return (
        <div role="group" aria-label={t('Text size')} className={`${force ? 'flex' : 'hidden sm:flex'} w-fit items-center rounded-full border border-[var(--pt-line)] bg-[var(--pt-surface)] p-0.5`}>
            {STEPS.map((s) => (
                <button
                    key={s.key}
                    type="button"
                    aria-label={t(s.label)}
                    aria-pressed={scale === s.scale}
                    onClick={() => choose(s.scale)}
                    className={`flex size-8 items-center justify-center rounded-full font-semibold transition-colors ${s.size} ${
                        scale === s.scale ? 'bg-[var(--pt-deep)] text-white' : 'text-[var(--pt-muted)] hover:text-[var(--pt-ink)]'
                    }`}
                >
                    A
                </button>
            ))}
        </div>
    );
}
