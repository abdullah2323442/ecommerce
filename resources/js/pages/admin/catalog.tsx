import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    name: string;
    name_bn: string | null;
    sector: string;
    status: string;
    asked_by: string | null;
    at: string;
    sellers: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Product catalog', href: '/admin/catalog' }];

function Request({ r }: { r: Row }) {
    const { t, dateTime } = useI18n();
    const [name, setName] = useState(r.name);
    const [note, setNote] = useState('');

    return (
        <li className="space-y-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {r.sector} · {r.asked_by}
                </p>
                <span className="text-xs text-muted-foreground">{dateTime(r.at)}</span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <Input aria-label={t('Name')} className="h-8 w-72" value={name} onChange={(e) => setName(e.target.value)} />
                <Button size="sm" onClick={() => router.post(`/admin/catalog/${r.id}/approve`, { name }, { preserveScroll: true })}>
                    {t('Add to the catalog')}
                </Button>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <Input aria-label={t('Why not')} className="h-8 w-72" placeholder={t('Why not (the seller is told)')} value={note} onChange={(e) => setNote(e.target.value)} />
                <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => router.post(`/admin/catalog/${r.id}/reject`, { note }, { preserveScroll: true })}>
                    {t('Turn down')}
                </Button>
            </div>
        </li>
    );
}

export default function Catalog({ waiting, products, sectors }: { waiting: Row[]; products: Row[]; sectors: { id: number; name: string }[] }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [sector, setSector] = useState(String(sectors[0]?.id ?? ''));
    const [name, setName] = useState('');
    const [bn, setBn] = useState('');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Product catalog')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />
                <div>
                    <h1 className="text-2xl font-bold">{t('Product catalog')}</h1>
                    <p className="text-sm text-muted-foreground">{t('One name for one product, so the same product from many sellers is one page with many prices. Where a sector asks for it, sellers must pick from here.')}</p>
                </div>

                {waiting.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Sellers asked for these')}</h2>
                        <ul className="divide-y rounded-md border border-warning/50 bg-card">
                            {waiting.map((r) => (
                                <Request key={r.id} r={r} />
                            ))}
                        </ul>
                    </section>
                )}

                <section className="space-y-2 rounded-md border p-4">
                    <h2 className="font-medium">{t('Add a product')}</h2>
                    <div className="grid gap-2 sm:grid-cols-4">
                        <select aria-label={t('Sector')} value={sector} onChange={(e) => setSector(e.target.value)} className="h-9 rounded-md border bg-background px-2 text-sm">
                            {sectors.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                        <Input aria-label={t('Name')} placeholder={t('Name')} value={name} onChange={(e) => setName(e.target.value)} />
                        <Input aria-label={t('Spelled in Bangla (only to find it)')} placeholder={t('Spelled in Bangla (only to find it)')} value={bn} onChange={(e) => setBn(e.target.value)} />
                        <Button disabled={name.trim().length < 2} onClick={() => router.post('/admin/catalog', { sector_id: sector, name, name_bn: bn }, { preserveScroll: true, onSuccess: () => (setName(''), setBn('')) })}>
                            {t('Add')}
                        </Button>
                    </div>
                    <InputError message={errors.name} />
                </section>

                <section className="space-y-2">
                    <h2 className="font-medium">{t('In the catalog')}</h2>
                    {products.length === 0 ? (
                        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('Nothing in the catalog yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {products.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                                    <span>
                                        {p.name} {p.name_bn && <span className="text-muted-foreground">· {p.name_bn}</span>}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {p.sector} · {t('{n} sellers', { n: num(p.sellers) })}
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
