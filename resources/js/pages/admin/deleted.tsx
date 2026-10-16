import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

interface Item {
    id: number;
    type: 'tier' | 'role' | 'courier' | 'expense' | 'customer_rate';
    label: string;
    by: string | null;
    at: string;
    days_left: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Deleted', href: '/admin/deleted' }];

const kind = (type: Item['type']) => (type === 'tier' ? 'Tier' : type === 'role' ? 'Staff role' : type === 'courier' ? 'Courier' : type === 'expense' ? 'Expense' : 'Manual rate');

export default function Deleted({ items, days }: { items: Item[]; days: number }) {
    const { t, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Deleted')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Deleted')}</h1>
                    <p className="text-sm text-muted-foreground">{t('What you delete stays here for {n} days. Put it back if it was a mistake.', { n: num(days) })}</p>
                </div>
                <Flash />
                <InputError message={errors.item} />

                {items.length === 0 ? (
                    <p className="rounded-md border border-dashed p-10 text-center text-sm text-muted-foreground">{t('Nothing has been deleted lately.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card text-sm">
                        {items.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <div className="min-w-48 flex-1">
                                    <p className="font-medium">{i.label}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {t(kind(i.type))} · {i.by ?? '—'} · {dateTime(i.at)}
                                    </p>
                                </div>
                                <span className="text-xs text-muted-foreground">{t('{n} days left', { n: num(i.days_left) })}</span>
                                <Button size="sm" variant="outline" onClick={() => router.post(`/admin/deleted/${i.id}/restore`, {}, { preserveScroll: true })}>
                                    {t('Put back')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
