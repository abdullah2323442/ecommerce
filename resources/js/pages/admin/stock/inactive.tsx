import Flash from '@/components/admin/flash';
import Pagination from '@/components/admin/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    strength: string | null;
    company: string | null;
}

interface Props {
    rows: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    q: string;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock', href: '/admin/stock' },
    { title: 'Switched-off medicines', href: '/admin/stock/inactive' },
];

/** The medicines that are off (discontinued or renamed), so one can be found and switched on again (spec ch 11). */
export default function Inactive({ rows, q }: Props) {
    const { t, num } = useI18n();
    const [search, setSearch] = useState(q);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Switched-off medicines')} />
            <div className="mx-auto w-full max-w-3xl space-y-5 p-4 md:p-6">
                <header>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Switched-off medicines')}</h1>
                    <p className="text-sm text-muted-foreground">{t('These are not offered for new orders. Old memos and reports still show them.')}</p>
                </header>
                <Flash />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/stock/inactive', { q: search || undefined }, { preserveState: true });
                    }}
                    className="flex gap-2"
                >
                    <label htmlFor="inactive-q" className="sr-only">
                        {t('Search by name')}
                    </label>
                    <Input id="inactive-q" value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search by name')} autoComplete="off" className="max-w-xs" />
                    <Button type="submit" variant="outline">
                        {t('Search')}
                    </Button>
                </form>

                {rows.data.length === 0 ? (
                    <p className="rounded-xl border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
                        {q === '' ? t('Nothing is switched off. A medicine that is discontinued or renamed can be switched off from its own page.') : t('Nothing switched off matches that name.')}
                    </p>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">{t('{n} switched off', { n: num(rows.total) })}</p>
                        <ul className="divide-y rounded-xl border bg-card">
                            {rows.data.map((m) => (
                                <li key={m.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div className="min-w-0">
                                        <Link href={`/admin/stock/medicines/${m.id}/edit`} className="font-medium underline-offset-4 hover:underline">
                                            {m.name} <strong>{m.strength}</strong>
                                        </Link>
                                        {m.company && <p className="text-sm text-muted-foreground">{m.company}</p>}
                                    </div>
                                    <Button variant="outline" size="sm" onClick={() => router.put(`/admin/stock/medicines/${m.id}/active`, { active: true }, { preserveScroll: true })}>
                                        {t('Switch on')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <Pagination links={rows.links} />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
