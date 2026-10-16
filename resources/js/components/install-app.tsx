import { useI18n } from '@/lib/i18n';
import { installApp, isIos, usePwa } from '@/lib/pwa';
import { useState } from 'react';

const KEY = 'aqua-install-hint-closed';

/** "Put the shop on your phone." A button where the browser can install it, and a two-line hint on an iPhone; nothing once it is installed. */
export default function InstallApp({ className = '' }: { className?: string }) {
    const { t } = useI18n();
    const { canInstall, installed } = usePwa();
    const [closed, setClosed] = useState(() => {
        try {
            return localStorage.getItem(KEY) === '1';
        } catch {
            return false;
        }
    });

    if (installed) return null;

    if (canInstall) {
        return (
            <button type="button" onClick={() => void installApp()} className={`inline-flex h-10 items-center rounded-md border border-white/40 px-4 text-sm font-semibold ${className}`}>
                {t('Install the app')}
            </button>
        );
    }

    if (isIos() && !closed) {
        return (
            <p className={`text-sm ${className}`}>
                {t('To install: tap Share, then Add to Home Screen.')}{' '}
                <button
                    type="button"
                    className="underline"
                    onClick={() => {
                        try {
                            localStorage.setItem(KEY, '1');
                        } catch {
                            /* kept for this visit only */
                        }
                        setClosed(true);
                    }}
                >
                    {t('Close')}
                </button>
            </p>
        );
    }

    return null;
}
