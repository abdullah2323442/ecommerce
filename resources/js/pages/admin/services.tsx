import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Row {
    id: number;
    title: string;
    provider: string;
    phone: string | null;
    price: number;
    place: string;
    active: boolean;
    hidden: boolean;
    open: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Services', href: '/admin/services' }];

/** Every service people give, with a switch to hide one that should not be shown to buyers. */
export default function Services({ offers }: { offers: Row[] }) {
    const { t, num, money } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Services')} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4">
                <Flash />
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Services')}</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">{t('Services that people give and others book. Hide one that should not be shown; its provider is not told, and bookings already made stay.')}</p>
                </header>
                {offers.length === 0 ? (
                    <p className="rounded-xl border p-6 text-sm text-muted-foreground">{t('Nobody gives a service yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-xl border text-sm">
                        {offers.map((o) => (
                            <li key={o.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                                <span className="min-w-44 flex-1">
                                    <span className="block font-semibold">{o.title}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {o.provider}
                                        {o.phone && ` · ${o.phone}`} · {o.price > 0 ? money(o.price) : t('Ask the provider')}
                                    </span>
                                </span>
                                <span className="tabular text-xs">{t('{n} open bookings', { n: num(o.open) })}</span>
                                <span className="text-xs font-medium">{o.hidden ? t('Hidden') : o.active ? t('Shown') : t('Paused by the provider')}</span>
                                <Button size="sm" variant="outline" onClick={() => router.put(`/admin/services/${o.id}/hide`, { hidden: !o.hidden }, { preserveScroll: true })}>
                                    {o.hidden ? t('Show again') : t('Hide')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
