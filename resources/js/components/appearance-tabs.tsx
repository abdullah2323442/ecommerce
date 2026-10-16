import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { useI18n } from '@/lib/i18n';
import { Monitor, Moon, Sun, type LucideIcon } from 'lucide-react';

/** Light, dark or follow the device. The choice is remembered on this device only. */
export default function AppearanceTabs() {
    const { appearance, updateAppearance } = useAppearance();
    const { t } = useI18n();

    const tabs: { value: Appearance; Icon: LucideIcon; label: string }[] = [
        { value: 'light', Icon: Sun, label: t('Light') },
        { value: 'dark', Icon: Moon, label: t('Dark') },
        { value: 'system', Icon: Monitor, label: t('System') },
    ];

    return (
        <div role="group" aria-label={t('Appearance')} className="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
            {tabs.map(({ value, Icon, label }) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={`flex min-h-10 items-center rounded-md px-3.5 text-sm transition-colors ${
                        appearance === value ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100' : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60'
                    }`}
                >
                    <Icon className="-ml-1 size-4" />
                    <span className="ml-1.5">{label}</span>
                </button>
            ))}
        </div>
    );
}
