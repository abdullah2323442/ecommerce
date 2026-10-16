import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { router } from '@inertiajs/react';

interface Props {
    courierStatus?: string | null;
    sentAt: string | null;
    tracking: string | null;
    extra: number;
    delivered: boolean;
    notReceived: boolean;
    /** Where the buttons post: the order's own address, then /received or /not-received. */
    url: string;
}

/** "On the way" for the buyer, with the two honest answers: it arrived, or it did not (spec ch 20). */
export default function DeliveryStatus({ courierStatus, sentAt, tracking, extra, delivered, notReceived, url }: Props) {
    const { t, money, dateTime } = useI18n();

    if (!sentAt) return null;

    return (
        <section aria-labelledby="way-h" className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <h2 id="way-h" className="font-display text-lg font-bold">
                {delivered ? t('Delivered') : notReceived ? t('You said it did not arrive') : t('On the way')}
            </h2>
            <p className="text-sm text-[var(--pt-muted)]">
                {t('Sent')} {dateTime(sentAt)}
                {tracking && ` · ${t('Tracking')}: ${tracking}`}
            </p>
            {courierStatus && !delivered && <p className="text-sm font-semibold">{t(courierStatus)}</p>}
            {extra > 0 && (
                <p className="text-sm">
                    {t('Extra delivery charge')}: {money(extra)}
                </p>
            )}
            {notReceived && <p className="text-sm">{t('We are looking into it and will call you.')}</p>}
            {!delivered && !notReceived && (
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={() => router.post(`${url}/received`, {}, { preserveScroll: true })}>
                        {t('I received it')}
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => confirm(t('Tell us it did not arrive?')) && router.post(`${url}/not-received`, {}, { preserveScroll: true })}>
                        {t('I did not receive it')}
                    </Button>
                </div>
            )}
        </section>
    );
}
