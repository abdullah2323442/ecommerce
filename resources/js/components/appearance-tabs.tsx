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
        <div role="group" aria-label={t('Appearance')} className="inline-flex gap-1 rounded-lg bg-sunken p-1">
            {tabs.map(({ value, Icon, label }) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={appearance === value}
                    onClick={() => updateAppearance(value)}
                    className={`flex min-h-10 items-center rounded-md px-3.5 text-sm transition-colors ${
                        appearance === value ? 'bg-raised text-ink shadow-xs' : 'text-ink-muted hover:bg-line/60 hover:text-ink'
                    }`}
                >
                    <Icon className="-ml-1 size-4" />
                    <span className="ml-1.5">{label}</span>
                </button>
            ))}
        </div>
    );
}
