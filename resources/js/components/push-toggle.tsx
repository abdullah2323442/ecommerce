import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { disablePush, enablePush, pushState, type PushResult } from '@/lib/push';
import { usePage } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { useEffect, useState } from 'react';

/** "Notifications on this phone": one button that asks once. Shown only where the owner switched notifications on and this browser can get them. */
export default function PushToggle() {
    const { t } = useI18n();
    const key = (usePage().props as { push?: { key: string | null } | null }).push?.key ?? null;
    const [state, setState] = useState<PushResult | 'loading'>('loading');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (key) pushState().then(setState);
    }, [key]);

    if (!key || state === 'unsupported' || state === 'loading') return null;

    const toggle = async () => {
        setBusy(true);
        setState(state === 'on' ? await disablePush() : await enablePush(key));
        setBusy(false);
    };

    return (
        <section className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <div className="min-w-48 flex-1">
                <h2 className="font-display text-lg font-bold">{t('Notifications on this phone')}</h2>
                <p className="text-sm text-[var(--pt-muted)]">
                    {state === 'on'
                        ? t('On. We will tell you here and on this device when something happens to your order.')
                        : state === 'denied'
                          ? t('This browser has notifications blocked. Allow them for this site in its settings, then come back.')
                          : state === 'error'
                            ? t('Something went wrong. Please try again.')
                            : t('Get a message on this device when your order moves, even when the site is closed.')}
                </p>
            </div>
            {state !== 'denied' && (
                <Button variant={state === 'on' ? 'outline' : 'default'} size="sm" disabled={busy} aria-busy={busy} onClick={toggle}>
                    {state === 'on' ? <BellOff aria-hidden className="size-4" /> : <Bell aria-hidden className="size-4" />}
                    {state === 'on' ? t('Turn off') : t('Turn on')}
                </Button>
            )}
        </section>
    );
}
