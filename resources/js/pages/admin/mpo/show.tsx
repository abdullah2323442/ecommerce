import Flash from '@/components/admin/flash';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import InputError from '@/components/input-error';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    mpo: { photo: string | null; id: number; number: number; name: string; phone: string | null; email: string; area: string | null; note: string | null; closed: boolean; failed: number; submitted: number; approved: number; sold: number; since: string | null; company_ids: number[]; products: { id: number; name: string; company: string | null }[] };
    companies: { id: number; name: string }[];
    listings: { id: number; name: string; status: string; stock_status: string }[];
}

/** The MPO's photo: the owner sets it, and only the owner ever sees it. */
function Photo({ mpoId, photo, name }: { mpoId: number; photo: string | null; name: string }) {
    const { t } = useI18n();
    const form = useForm<{ photo: File | null }>({ photo: null });

    return (
        <section aria-labelledby="mpo-photo-h" className="flex flex-wrap items-center gap-4 rounded-lg border bg-card p-4">
            {photo ? (
                <img src={photo} alt={t('Photo of {name}', { name })} className="size-20 rounded-full border object-cover" />
            ) : (
                <div aria-hidden className="grid size-20 place-items-center rounded-full border bg-muted text-2xl font-bold text-muted-foreground">
                    {name.slice(0, 1).toUpperCase()}
                </div>
            )}
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/admin/mpo/${mpoId}/photo`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
                }}
                className="space-y-2"
            >
                <h2 id="mpo-photo-h" className="text-sm font-medium">
                    {t('Photo')}
                </h2>
                <div className="flex flex-wrap items-center gap-2">
                    <label htmlFor="mpo-photo" className="sr-only">
                        {t('Choose a photo')}
                    </label>
                    <Input id="mpo-photo" type="file" accept="image/png,image/jpeg,image/webp" onChange={(e) => form.setData('photo', e.target.files?.[0] ?? null)} className="max-w-xs" />
                    <Button type="submit" variant="outline" disabled={form.processing || form.data.photo === null}>
                        {t('Save photo')}
                    </Button>
                    {photo && (
                        <Button type="button" variant="ghost" onClick={() => router.post(`/admin/mpo/${mpoId}/photo`, { remove: true }, { preserveScroll: true })}>
                            {t('Remove photo')}
                        </Button>
                    )}
                </div>
                <InputError message={form.errors.photo} />
            </form>
        </section>
    );
}

/** Everything about one MPO, for the owner only: who they are, their companies, the products they work with and what they sent. */
export default function MpoShow({ mpo, companies, listings }: Props) {
    const { t, num, dateTime } = useI18n();
    const [f, setF] = useState({ name: mpo.name, phone: mpo.phone ?? '', area: mpo.area ?? '', note: mpo.note ?? '' });
    const [ids, setIds] = useState<number[]>(mpo.company_ids);
    const crumbs: BreadcrumbItem[] = [
        { title: 'MPO', href: '/admin/mpo' },
        { title: mpo.name, href: `/admin/mpo/${mpo.id}` },
    ];

    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title={mpo.name} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">
                        {mpo.name} {mpo.closed && <span className="rounded bg-danger/15 px-1.5 text-sm font-semibold">{t('Closed')}</span>}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        {t('Buyers see')} “{t('Anonymous')} {num(mpo.number)}” · {mpo.email} · {mpo.since && dateTime(mpo.since)}
                    </p>
                </header>

                <Photo mpoId={mpo.id} photo={mpo.photo} name={mpo.name} />

                <dl className="grid grid-cols-3 gap-3 text-center text-sm">
                    {(
                        [
                            ['Products submitted', mpo.submitted],
                            ['Products approved', mpo.approved],
                            ['Products sold', mpo.sold],
                        ] as const
                    ).map(([label, n]) => (
                        <div key={label} className="rounded-lg border bg-card p-3">
                            <dd className="tabular text-xl font-bold">{num(n)}</dd>
                            <dt className="text-muted-foreground">{t(label)}</dt>
                        </div>
                    ))}
                </dl>

                {mpo.failed > 0 && <p className="text-sm font-semibold text-danger">{t('Orders not delivered in time: {n}', { n: num(mpo.failed) })}</p>}

                <section className="space-y-3 rounded-lg border bg-card p-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        {(
                            [
                                ['name', 'Name'],
                                ['phone', 'Phone'],
                                ['area', 'Area'],
                                ['note', 'Your note'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="text-sm">
                                {t(label)}
                                <Input className="mt-1 h-9" value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
                            </label>
                        ))}
                    </div>
                    <Button onClick={() => router.put(`/admin/mpo/${mpo.id}`, f, { preserveScroll: true })}>{t('Save')}</Button>
                </section>

                <section className="space-y-2 rounded-lg border bg-card p-4">
                    <h2 className="font-display text-lg font-extrabold">{t('Companies')}</h2>
                    <div className="flex max-h-40 flex-wrap gap-x-4 gap-y-1 overflow-auto text-sm">
                        {companies.map((c) => (
                            <label key={c.id} className="flex items-center gap-2">
                                <input type="checkbox" checked={ids.includes(c.id)} onChange={() => setIds(ids.includes(c.id) ? ids.filter((x) => x !== c.id) : [...ids, c.id])} />
                                {c.name}
                            </label>
                        ))}
                    </div>
                    <p className="text-muted-foreground text-xs">{t('Taking a company away also takes away the products chosen from it.')}</p>
                    <Button variant="outline" disabled={ids.length === 0} onClick={() => router.put(`/admin/mpo/${mpo.id}/companies`, { company_ids: ids }, { preserveScroll: true })}>
                        {t('Save companies')}
                    </Button>
                </section>

                <section className="space-y-2">
                    <h2 className="font-medium">
                        {t('Products they work with')} ({num(mpo.products.length)})
                    </h2>
                    <ul className="flex flex-wrap gap-2 text-sm">
                        {mpo.products.map((p) => (
                            <li key={p.id} className="rounded-full border px-3 py-1">
                                {p.name} <span className="text-muted-foreground">{p.company}</span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="space-y-2">
                    <h2 className="font-medium">{t('What they sent')}</h2>
                    <ul className="divide-y rounded-md border text-sm">
                        {listings.map((l) => (
                            <li key={l.id} className="flex gap-4 px-3 py-2">
                                <span className="flex-1">{l.name}</span>
                                <span>{t(l.stock_status === 'pre_order' ? 'Pre-order' : 'In stock')}</span>
                                <span className="font-semibold">{t(l.status === 'submitted' ? 'Waiting for us' : l.status === 'approved' ? 'On the market' : l.status === 'rejected' ? 'Not accepted' : 'Taken off')}</span>
                            </li>
                        ))}
                    </ul>
                </section>

                {!mpo.closed && (
                    <Button variant="destructive" onClick={() => confirm(t('Close this account? Its products come off the market. Orders already made must still be completed.')) && router.post(`/admin/mpo/${mpo.id}/close`)}>
                        {t('Close the account')}
                    </Button>
                )}
            </div>
        </AppLayout>
    );
}
