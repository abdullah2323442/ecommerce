import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    groups: { area: string; count: number; mpos: { id: number; number: number; name: string; phone: string | null; closed: boolean; products: number; submitted: number; live: number }[] }[];
    companies: { id: number; name: string }[];
    waiting: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'MPO', href: '/admin/mpo' }];

export default function MpoIndex({ groups, companies, waiting }: Props) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', phone: '', email: '', password: '', area: '', note: '', company_ids: [] as number[] });
    const toggle = (id: number) => setF({ ...f, company_ids: f.company_ids.includes(id) ? f.company_ids.filter((x) => x !== id) : [...f.company_ids, id] });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('MPO')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Flash />
                <header className="flex flex-wrap items-baseline justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('MPO')}</h1>
                        <p className="text-muted-foreground text-sm">{t('Only you can open an account. Buyers never see who an MPO is, and an MPO never sees a buyer.')}</p>
                    </div>
                    <Link href="/admin/mpo/listings" className="rounded-md border px-3 py-1.5 text-sm font-semibold">
                        {t('Products to look at')} ({num(waiting)})
                    </Link>
                </header>

                {groups.length === 0 ? (
                    <p className="text-muted-foreground rounded-md border border-dashed p-8 text-center text-sm">{t('No MPO yet.')}</p>
                ) : (
                    groups.map((g) => (
                        <section key={g.area} className="space-y-2">
                            <h2 className="font-medium">
                                {g.area || t('No area')} ({num(g.count)})
                            </h2>
                            <ul className="divide-y rounded-md border text-sm">
                                {g.mpos.map((m) => (
                                    <li key={m.id} className={`flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 ${m.closed ? 'opacity-60' : ''}`}>
                                        <Link href={`/admin/mpo/${m.id}`} className="min-w-44 flex-1 font-medium underline underline-offset-2">
                                            {m.name}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {t('Anonymous')} {num(m.number)}
                                        </span>
                                        <span className="text-muted-foreground">{m.phone}</span>
                                        <span>
                                            {num(m.products)} {t('products')} · {num(m.live)} {t('on the market')}
                                        </span>
                                        {m.closed && <span className="rounded bg-red-500/15 px-1.5 text-xs font-semibold">{t('Closed')}</span>}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))
                )}

                <section className="space-y-3 rounded-lg border bg-card p-4">
                    <h2 className="font-display text-lg font-extrabold">{t('Open an account')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-sm">
                            {t('Name')}
                            <Input className="mt-1 h-9" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} maxLength={120} />
                            <InputError message={errors.name} />
                        </label>
                        <label className="text-sm">
                            {t('Phone')}
                            <Input className="mt-1 h-9" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
                            <InputError message={errors.phone} />
                        </label>
                        <label className="text-sm">
                            {t('Email (optional)')}
                            <Input className="mt-1 h-9" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
                            <InputError message={errors.email} />
                        </label>
                        <label className="text-sm">
                            {t('Password (at least 10 characters)')}
                            <Input className="mt-1 h-9" type="password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
                            <InputError message={errors.password} />
                        </label>
                        <label className="text-sm">
                            {t('Area')}
                            <Input className="mt-1 h-9" value={f.area} onChange={(e) => setF({ ...f, area: e.target.value })} maxLength={80} />
                        </label>
                        <label className="text-sm">
                            {t('Your note')}
                            <Input className="mt-1 h-9" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} maxLength={500} />
                        </label>
                    </div>
                    <fieldset>
                        <legend className="text-sm font-medium">{t('Companies they can work with')}</legend>
                        <div className="mt-2 flex max-h-40 flex-wrap gap-x-4 gap-y-1 overflow-auto text-sm">
                            {companies.map((c) => (
                                <label key={c.id} className="flex items-center gap-2">
                                    <input type="checkbox" checked={f.company_ids.includes(c.id)} onChange={() => toggle(c.id)} />
                                    {c.name}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.company_ids} />
                    </fieldset>
                    <Button disabled={!f.name || f.password.length < 10 || f.company_ids.length === 0} onClick={() => router.post('/admin/mpo', f, { preserveScroll: true })}>
                        {t('Open the account')}
                    </Button>
                </section>
            </div>
        </AppLayout>
    );
}
