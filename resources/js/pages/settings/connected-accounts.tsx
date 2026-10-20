import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Props {
    linked: { provider: string; name: string; email: string | null }[];
    available: { key: string; name: string }[];
    hasPassword: boolean;
    allowed: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connected accounts', href: '/settings/connected-accounts' }];

export default function ConnectedAccounts({ linked, available, hasPassword, allowed }: Props) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Connected accounts')} />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title={t('Connected accounts')} description={t('Sign in with a Google or Facebook account you already have.')} />
                    {flash && <p role="status" className="rounded-md bg-success/10 p-3 text-sm">{flash}</p>}
                    <InputError message={errors.social} />

                    {!allowed ? (
                        <p className="rounded-md bg-muted p-3 text-sm">{t('This kind of account signs in with its email and password only.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {available.length === 0 && linked.length === 0 && <li className="p-4 text-sm text-muted-foreground">{t('No outside sign-in is switched on.')}</li>}
                            {linked.map((l) => (
                                <li key={l.provider} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                                    <span>
                                        <strong>{l.name}</strong> {l.email && <span className="text-muted-foreground">· {l.email}</span>}
                                    </span>
                                    <Button size="sm" variant="outline" onClick={() => router.delete(`/settings/connected-accounts/${l.provider}`, { preserveScroll: true })}>
                                        {t('Disconnect')}
                                    </Button>
                                </li>
                            ))}
                            {available
                                .filter((a) => !linked.some((l) => l.provider === a.key))
                                .map((a) => (
                                    <li key={a.key} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                                        <strong>{a.name}</strong>
                                        <a href={`/settings/connected-accounts/${a.key}/connect`} className="inline-flex h-8 items-center rounded-md border px-3 font-medium hover:bg-muted">
                                            {t('Connect')}
                                        </a>
                                    </li>
                                ))}
                        </ul>
                    )}

                    {!hasPassword && (
                        <p className="rounded-md border border-warning/40 bg-warning/10 p-3 text-sm">
                            {t('You have no password yet.')}{' '}
                            <Link href="/settings/password" className="font-semibold underline underline-offset-4">
                                {t('Choose one')}
                            </Link>
                        </p>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
