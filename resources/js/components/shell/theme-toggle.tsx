import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { useI18n } from '@/lib/i18n';
import { Monitor, Moon, Sun, type LucideIcon } from 'lucide-react';

/** Light / dark / system, as a labelled segmented control. */
export default function ThemeToggle({ className = '' }: { className?: string }) {
    const { t } = useI18n();
    const { appearance, updateAppearance } = useAppearance();

    const options: { value: Appearance; Icon: LucideIcon; label: string }[] = [
        { value: 'light', Icon: Sun, label: t('Light') },
        { value: 'dark', Icon: Moon, label: t('Dark') },
        { value: 'system', Icon: Monitor, label: t('System') },
    ];

    return (
        <div role="group" aria-label={t('Theme')} className={`inline-flex rounded-control border border-line bg-raised p-0.5 text-sm ${className}`}>
            {options.map(({ value, Icon, label }) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={`inline-flex min-h-9 items-center gap-1.5 rounded-[calc(var(--radius-control)-2px)] px-2.5 transition-colors ${appearance === value ? 'bg-ink text-surface' : 'text-ink-muted hover:text-ink'}`}
                >
                    <Icon aria-hidden className="size-4" />
                    {label}
                </button>
            ))}
        </div>
    );
}

/** Normal / large text (spec T13). Every size is in rem, so one root switch scales the whole UI. */
export function TextSizeToggle({ className = '' }: { className?: string }) {
    const { t } = useI18n();
    const read = () => (typeof document !== 'undefined' && document.documentElement.dataset.textSize === 'large' ? 'large' : 'normal');

    const choose = (size: 'normal' | 'large') => {
        if (size === 'large') document.documentElement.dataset.textSize = 'large';
        else delete document.documentElement.dataset.textSize;
        try {
            localStorage.setItem('text-size', size);
        } catch {
            /* private mode: the choice lasts for this page only */
        }
        // Re-render the two buttons' pressed state without a store.
        document.querySelectorAll<HTMLButtonElement>('[data-text-size-option]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.textSizeOption === size)));
    };

    return (
        <div role="group" aria-label={t('Text size')} className={`inline-flex rounded-control border border-line bg-raised p-0.5 text-sm ${className}`}>
            {(
                [
                    ['normal', t('Normal text')],
                    ['large', t('Large text')],
                ] as const
            ).map(([size, label]) => (
                <button
                    key={size}
                    type="button"
                    data-text-size-option={size}
                    aria-pressed={read() === size}
                    onClick={() => choose(size)}
                    className="min-h-9 rounded-[calc(var(--radius-control)-2px)] px-2.5 text-ink-muted transition-colors hover:text-ink aria-pressed:bg-ink aria-pressed:text-surface"
                >
                    {label}
                </button>
            ))}
        </div>
    );
}
