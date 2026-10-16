import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';

interface CountRow {
    id: number;
    scope: string;
    company: string | null;
    shelf: string | null;
    status: string;
    started_at: string;
    difference_value: number;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock', href: '/admin/stock' },
    { title: 'Stock count', href: '/admin/stock/counts' },
];

export default function StockCounts({ counts, companies }: { counts: CountRow[]; companies: { id: number; name: string }[] }) {
    const { t, num, money, date } = useI18n();
    const form = useForm({ scope: 'all', company_id: '', shelf: '' });
    const errors = form.errors as Record<string, string>;

    const label = (c: CountRow) => (c.scope === 'company' ? (c.company ?? t('A company')) : c.scope === 'shelf' ? t('Shelf {name}', { name: c.shelf ?? '' }) : t('Whole shop'));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Stock count')} />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Stock count')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Count the shelves, compare with the system, and approve the differences. Sales can go on during a count.')}</p>
                </div>

                <Flash />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/stock/counts');
                    }}
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4"
                >
                    <div>
                        <label htmlFor="scope" className="text-sm font-medium">
                            {t('Count')}
                        </label>
                        <select id="scope" value={form.data.scope} onChange={(e) => form.setData('scope', e.target.value)} className="mt-1 h-10 rounded-md border bg-background px-3 text-sm">
                            <option value="all">{t('Whole shop')}</option>
                            <option value="company">{t('One company')}</option>
                            <option value="shelf">{t('One shelf')}</option>
                        </select>
                    </div>
                    {form.data.scope === 'company' && (
                        <div>
                            <label htmlFor="company" className="text-sm font-medium">
                                {t('Company')}
                            </label>
                            <select id="company" value={form.data.company_id} onChange={(e) => form.setData('company_id', e.target.value)} className="mt-1 h-10 rounded-md border bg-background px-3 text-sm">
                                <option value="">{t('Choose')}</option>
                                {companies.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.company_id} />
                        </div>
                    )}
                    {form.data.scope === 'shelf' && (
                        <div>
                            <label htmlFor="shelf" className="text-sm font-medium">
                                {t('Shelf')}
                            </label>
                            <Input id="shelf" value={form.data.shelf} maxLength={40} onChange={(e) => form.setData('shelf', e.target.value)} className="mt-1 w-32" />
                            <InputError message={errors.shelf} />
                        </div>
                    )}
                    <Button type="submit" loading={form.processing}>
                        {t('Start a count')}
                    </Button>
                </form>

                {counts.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-muted-foreground">{t('No counts yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card">
                        {counts.map((c) => (
                            <li key={c.id}>
                                <Link href={`/admin/stock/counts/${c.id}`} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-muted/40">
                                    <span>
                                        <span className="font-medium">{t('Count {n}', { n: num(c.id) })}</span> <span className="text-muted-foreground">· {label(c)}</span>
                                        <span className="block text-xs text-muted-foreground">{date(c.started_at)}</span>
                                    </span>
                                    <span className="tabular text-sm">
                                        <span className="rounded-full border px-2.5 py-0.5">{t(c.status)}</span>
                                        {c.status === 'approved' && <span className="ml-3 font-medium">{money(c.difference_value)}</span>}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
