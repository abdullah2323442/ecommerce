import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Clock } from 'lucide-react';

interface Props {
    booking: {
        token: string;
        status: 'pending' | 'confirmed' | 'declined' | 'cancelled' | 'done';
        name: string;
        when: string;
        price: number;
        reason: string | null;
        note: string | null;
        service: { id: number; title: string; minutes: number; provider: string; place: string; area: string | null };
        phone: string | null;
        can_cancel: boolean;
        cancel_hours: number;
    };
}

/** One booking, by its private link: where it stands, and a way to cancel while that is still allowed. */
export default function Booking({ booking: b }: Props) {
    const { t, num, money, dateTime } = useI18n();
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const flash = (page.props.flash as { status?: string } | undefined)?.status;
    const state = {
        pending: { icon: Clock, text: t('Waiting for the provider to confirm') },
        confirmed: { icon: CircleCheck, text: t('Confirmed') },
        declined: { icon: CircleAlert, text: t('Declined') },
        cancelled: { icon: CircleAlert, text: t('Cancelled') },
        done: { icon: CircleCheck, text: t('Done') },
    }[b.status];
    const Icon = state.icon;

    return (
        <PublicLayout>
            <Head title={t('Your booking')} />
            <div className="mx-auto max-w-xl space-y-5">
                {flash && (
                    <p role="status" className="rounded-md border border-[var(--pt-ink)] bg-[var(--pt-surface)] px-3 py-2 text-sm">
                        {flash}
                    </p>
                )}
                <section className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-5">
                    <p className="inline-flex items-center gap-2 font-display text-2xl font-extrabold">
                        <Icon aria-hidden className="size-6" />
                        {state.text}
                    </p>
                    <h1 className="font-display text-xl font-bold">{b.service.title}</h1>
                    <p className="text-sm text-[var(--pt-muted)]">
                        {b.service.provider} · {t('{n} minutes', { n: num(b.service.minutes) })}
                    </p>
                    <p className="text-lg">{dateTime(b.when)}</p>
                    {b.price > 0 && <p className="tabular text-sm">{t('{amount}, paid to the provider when you meet', { amount: money(b.price) })}</p>}
                    {b.reason && <p className="text-sm">{b.reason}</p>}
                    {b.phone && (
                        <p className="text-sm">
                            {t('Provider phone')}: <a href={`tel:${b.phone}`} className="underline">{b.phone}</a>
                        </p>
                    )}
                    <p className="text-xs text-[var(--pt-muted)]">{t('Keep this page. It is your booking.')}</p>
                </section>

                {(b.status === 'pending' || b.status === 'confirmed') &&
                    (b.can_cancel ? (
                        <div className="space-y-2">
                            <InputError message={errors.cancel} />
                            <Button variant="outline" onClick={() => window.confirm(t('Cancel this booking?')) && router.post(`/services/booking/${b.token}/cancel`, {}, { preserveScroll: true })}>
                                {t('Cancel the booking')}
                            </Button>
                        </div>
                    ) : (
                        <p className="text-sm text-[var(--pt-muted)]">{t('It is too late to cancel here. Call the provider, or cancel at least {n} hours before.', { n: num(b.cancel_hours) })}</p>
                    ))}

                <Link href="/services" className="text-sm underline underline-offset-4">
                    {t('All services')}
                </Link>
            </div>
        </PublicLayout>
    );
}
