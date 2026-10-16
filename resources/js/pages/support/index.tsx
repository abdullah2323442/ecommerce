import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { STATUS_LABEL } from './show';

interface Row {
    token: string;
    number: string;
    subject: string;
    status: string;
    at: string;
}

export default function SupportIndex({ tickets }: { tickets: Row[] }) {
    const { t, dateTime } = useI18n();

    return (
        <PublicLayout>
            <Head title={t('My requests')} />
            <div className="mx-auto max-w-2xl space-y-5">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('My requests')}</h1>
                    <Link href="/support/new" className="text-sm font-semibold underline underline-offset-4">
                        {t('Contact us')}
                    </Link>
                </div>
                {tickets.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-[var(--pt-muted)]">{t('You have not written to us yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {tickets.map((x) => (
                            <li key={x.token}>
                                <Link href={`/support/t/${x.token}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <span className="min-w-40 flex-1 font-medium">{x.subject}</span>
                                    <span className="text-xs text-[var(--pt-muted)]">{x.number}</span>
                                    <span className="text-xs font-semibold">{t(STATUS_LABEL[x.status])}</span>
                                    <span className="text-xs text-[var(--pt-muted)]">{dateTime(x.at)}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
