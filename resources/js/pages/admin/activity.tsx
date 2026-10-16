import Pagination from '@/components/admin/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    action: string;
    actor: string | null;
    subject: string;
    when: string | null;
    changes: Record<string, unknown> | null;
}

interface Props {
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    q: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Activity', href: '/admin/activity' }];

export default function Activity({ rows, q }: Props) {
    const { t, dateTime } = useI18n();
    const [query, setQuery] = useState(q);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Activity')} />
            <div className="mx-auto w-full max-w-5xl space-y-4 p-4">
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Activity')}</h1>
                    <p className="text-muted-foreground text-sm">{t('Who did what, and who opened whose data. Nothing here can be changed.')}</p>
                </header>
                <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/activity', { q: query || undefined }, { preserveState: true });
                    }}
                >
                    <Input
                        aria-label={t('Search the log')}
                        className="max-w-xs"
                        placeholder={t('For example: viewed, approval, stock')}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        {t('Search')}
                    </Button>
                </form>
                <ul className="divide-y rounded-md border text-sm">
                    {rows.data.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-3 py-2">
                            <span className="min-w-44 font-mono text-xs">{r.action}</span>
                            <span className="min-w-28 font-medium">{r.actor ?? t('System')}</span>
                            <span className="text-muted-foreground">{r.subject}</span>
                            <span className="text-muted-foreground ml-auto text-xs">{r.when && dateTime(r.when)}</span>
                        </li>
                    ))}
                </ul>
                <Pagination links={rows.links} />
            </div>
        </AppLayout>
    );
}
