import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Row {
    id: number;
    product: string;
    rating: number;
    body: string | null;
    hidden: boolean;
    at: string;
    photos: string[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Reviews', href: '/admin/reviews' }];

export default function Reviews({ reviews }: { reviews: Row[] }) {
    const { t, dateTime } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Reviews')} />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4">
                <Flash />
                <div>
                    <h1 className="text-2xl font-bold">{t('Reviews')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Only buyers whose order arrived can write one. Hide one that breaks the rules.')}</p>
                </div>
                {reviews.length === 0 ? (
                    <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No review yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card text-sm">
                        {reviews.map((r) => (
                            <li key={r.id} className={`space-y-1 px-4 py-3 ${r.hidden ? 'opacity-60' : ''}`}>
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-medium">
                                        {r.product} · {'★'.repeat(r.rating)}
                                    </p>
                                    <span className="text-xs text-muted-foreground">{dateTime(r.at)}</span>
                                </div>
                                {r.body && <p>{r.body}</p>}
                                {r.photos.length > 0 && (
                                    <div className="flex gap-2">
                                        {r.photos.map((u) => (
                                            <img key={u} src={u} alt="" className="h-16 w-16 rounded border object-cover" />
                                        ))}
                                    </div>
                                )}
                                <Button size="sm" variant="ghost" onClick={() => router.put(`/admin/reviews/${r.id}/hide`, { hidden: !r.hidden }, { preserveScroll: true })}>
                                    {r.hidden ? t('Show again') : t('Hide')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
