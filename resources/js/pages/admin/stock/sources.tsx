import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Source {
    id: number;
    name: string;
    type: string;
    default_percent: string | null;
    is_active: boolean;
}

interface Props {
    sources: Source[];
    types: string[];
    companyRates: { company_id: number; name: string; percent: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Stock', href: '/admin/stock' },
    { title: 'Sources and rates', href: '/admin/stock/sources' },
];

function SourceRow({ source, types }: { source: Source; types: string[] }) {
    const { t, num } = useI18n();
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: source.name, type: source.type, default_percent: source.default_percent ?? '', is_active: source.is_active });
    const errors = form.errors as Record<string, string>;

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/admin/stock/sources/${source.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    if (!editing) {
        return (
            <li className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <div className="min-w-40 flex-1">
                    <p className={`font-medium ${source.is_active ? '' : 'text-muted-foreground line-through'}`}>{source.name}</p>
                    <p className="text-sm text-muted-foreground">{t(source.type)}</p>
                </div>
                <p className="tabular w-24 text-right font-display text-2xl font-extrabold">{source.default_percent !== null ? `${num(Number(source.default_percent))}%` : '—'}</p>
                <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                    {t('Edit')}
                </Button>
            </li>
        );
    }

    return (
        <li className="px-4 py-3">
            <form onSubmit={save} className="flex flex-wrap items-start gap-2">
                <div>
                    <label htmlFor={`n-${source.id}`} className="sr-only">
                        {t('Source name')}
                    </label>
                    <Input id={`n-${source.id}`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="w-48" />
                    <InputError message={errors.name} />
                </div>
                <select aria-label={t('Type')} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} className="h-10 rounded-md border bg-background px-3 text-sm">
                    {types.map((ty) => (
                        <option key={ty} value={ty}>
                            {t(ty)}
                        </option>
                    ))}
                </select>
                <div>
                    <label htmlFor={`p-${source.id}`} className="sr-only">
                        {t('Default % below MRP')}
                    </label>
                    <Input id={`p-${source.id}`} type="number" min="0" max="99.999" step="0.001" value={form.data.default_percent} onChange={(e) => form.setData('default_percent', e.target.value)} className="w-28" />
                    <InputError message={errors.default_percent} />
                </div>
                <label className="flex h-10 items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                    {t('Active')}
                </label>
                <Button type="submit" loading={form.processing}>
                    {t('Save')}
                </Button>
                <Button type="button" variant="ghost" onClick={() => setEditing(false)}>
                    {t('Cancel')}
                </Button>
            </form>
        </li>
    );
}

export default function StockSources({ sources, types, companyRates }: Props) {
    const { t, num } = useI18n();
    const create = useForm({ name: '', type: 'company', default_percent: '' });
    const errors = create.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Sources and rates')} />

            <div className="mx-auto w-full max-w-3xl space-y-8 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Sources and rates')}</h1>
                    <p className="text-sm text-muted-foreground">{t('A purchase % is found in this order: typed for the medicine, then the company rate, then the source default.')}</p>
                </div>

                <Flash />

                <section aria-labelledby="src-h" className="space-y-3">
                    <h2 id="src-h" className="font-display text-xl font-bold">
                        {t('Sources')}
                    </h2>
                    {sources.length === 0 ? (
                        <p className="rounded-md border border-dashed p-8 text-center text-muted-foreground">{t('No sources yet. Add the first one below.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card">
                            {sources.map((s) => (
                                <SourceRow key={s.id} source={s} types={types} />
                            ))}
                        </ul>
                    )}

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            create.post('/admin/stock/sources', { preserveScroll: true, onSuccess: () => create.reset() });
                        }}
                        className="flex flex-wrap items-start gap-2 rounded-md border bg-card p-4"
                    >
                        <div>
                            <label htmlFor="new-name" className="sr-only">
                                {t('Source name')}
                            </label>
                            <Input id="new-name" placeholder={t('Source name')} value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} className="w-52" />
                            <InputError message={errors.name} />
                        </div>
                        <select aria-label={t('Type')} value={create.data.type} onChange={(e) => create.setData('type', e.target.value)} className="h-10 rounded-md border bg-background px-3 text-sm">
                            {types.map((ty) => (
                                <option key={ty} value={ty}>
                                    {t(ty)}
                                </option>
                            ))}
                        </select>
                        <div>
                            <label htmlFor="new-pct" className="sr-only">
                                {t('Default % below MRP')}
                            </label>
                            <Input id="new-pct" type="number" min="0" max="99.999" step="0.001" placeholder={t('Default %')} value={create.data.default_percent} onChange={(e) => create.setData('default_percent', e.target.value)} className="w-32" />
                            <InputError message={errors.default_percent} />
                        </div>
                        <Button type="submit" loading={create.processing}>
                            {t('Add source')}
                        </Button>
                    </form>
                </section>

                <section aria-labelledby="co-h" className="space-y-3">
                    <h2 id="co-h" className="font-display text-xl font-bold">
                        {t('Company rates')}
                    </h2>
                    <p className="text-sm text-muted-foreground">{t("A company's rate covers all its medicines. Set one from any of its medicines in Stock.")}</p>
                    {companyRates.length === 0 ? (
                        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No company rates set.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card">
                            {companyRates.map((c) => (
                                <li key={c.company_id} className="flex items-center justify-between gap-4 px-4 py-3">
                                    <span className="font-medium">{c.name}</span>
                                    <span className="flex items-center gap-4">
                                        <span className="tabular font-display text-xl font-bold">{num(Number(c.percent))}%</span>
                                        <Button variant="ghost" size="sm" onClick={() => router.put(`/admin/stock/rates/company/${c.company_id}`, { percent: null }, { preserveScroll: true })}>
                                            {t('Remove')}
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
