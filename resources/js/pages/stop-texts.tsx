import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

/** The page behind the link at the end of a promotional text: one button to stop them. */
export default function StopTexts({ stopped, token, user }: { stopped: boolean; token: string; user: number }) {
    const { t } = useI18n();
    const [busy, setBusy] = useState(false);

    return (
        <PublicLayout>
            <Head title={t('Promotional texts')} />
            <div className="mx-auto max-w-md space-y-4 py-10">
                <h1 className="font-display text-3xl font-extrabold">{stopped ? t('You will not get these texts') : t('Stop promotional texts?')}</h1>
                <p className="text-[var(--pt-muted)]">
                    {stopped
                        ? t('We will not send you offers by text any more. Messages about your own orders still reach you.')
                        : t('We will stop sending you offers by text. Messages about your own orders will still reach you.')}
                </p>
                {!stopped && (
                    <Button disabled={busy} aria-busy={busy} onClick={() => router.post(`/u/${user}/${token}`, {}, { onStart: () => setBusy(true), onFinish: () => setBusy(false) })}>
                        {busy ? t('Saving…') : t('Stop the offers')}
                    </Button>
                )}
            </div>
        </PublicLayout>
    );
}
