import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { applyUpdate, startPwa, usePwa } from '@/lib/pwa';
import { useEffect } from 'react';

/** "A new version is ready." Shown on any page once the installed app has fetched a newer build; the person decides when to switch (spec 588). */
export default function PwaBanner() {
    const { t } = useI18n();
    const { updateReady } = usePwa();

    useEffect(() => startPwa(), []);

    if (!updateReady) return null;

    return (
        <div role="status" className="fixed inset-x-3 bottom-3 z-50 mx-auto flex max-w-md items-center gap-3 rounded-xl border bg-background p-3 text-sm shadow-lg">
            <p className="flex-1">{t('A new version is ready.')}</p>
            <Button size="sm" onClick={applyUpdate}>
                {t('Update now')}
            </Button>
        </div>
    );
}
