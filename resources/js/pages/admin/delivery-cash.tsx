import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Delivery money', href: '/admin/delivery-cash' }];

/** How much each delivery person holds; settled at day end (spec ch 20). */
export default function DeliveryCash({ people }: { people: { id: number; name: string; holding: number; orders: number }[] }) {
    const { t, money, num } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Delivery money')} />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4">
                <Flash />
                <div>
                    <h1 className="text-2xl font-bold">{t('Delivery money')}</h1>
                    <p className="text-sm text-muted-foreground">{t('What each delivery person took at the door and has not handed in yet. Count it at the end of the day, then press Received.')}</p>
                </div>
                {people.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">{t('No delivery person yet. Give a staff member the delivery role.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card text-sm">
                        {people.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <span className="font-medium">{p.name}</span>
                                <span className="tabular text-muted-foreground">{t('{n} orders', { n: num(p.orders) })}</span>
                                <span className="tabular text-lg font-bold">{money(p.holding)}</span>
                                <Button size="sm" variant="outline" disabled={p.holding <= 0} onClick={() => window.confirm(t('Did you receive {amount}?', { amount: money(p.holding) })) && router.post(`/admin/delivery-cash/${p.id}`, {}, { preserveScroll: true })}>
                                    {t('Received')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
