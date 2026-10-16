import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Order {
    id: number;
    number: string;
    name: string | null;
    phone: string | null;
    area: string;
    address: string | null;
    how_to_find: string | null;
    collect: number;
    arrived: boolean;
    note: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My deliveries', href: '/admin/deliveries' }];

function Card({ o }: { o: Order }) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [code, setCode] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const [taken, setTaken] = useState(String(o.collect));
    const post = (path: string, body: Record<string, string | number | File | null> = {}) => router.post(`/admin/deliveries/${o.id}/${path}`, body, { preserveScroll: true, forceFormData: photo !== null });

    return (
        <li className="space-y-3 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold">
                    {o.number} · {o.name}
                </p>
                <p className="tabular text-lg font-bold">
                    {t('Collect')} {money(o.collect)}
                </p>
            </div>
            <p className="text-sm">
                {o.address}
                {o.how_to_find && ` · ${o.how_to_find}`}
            </p>
            {o.phone && (
                <a href={`tel:${o.phone}`} className="inline-block text-sm font-semibold underline underline-offset-4">
                    {o.phone}
                </a>
            )}
            {o.note && <p className="text-muted-foreground text-xs">{o.note}</p>}

            {!o.arrived ? (
                <Button size="sm" onClick={() => post('arrived')}>
                    {t('I have arrived')}
                </Button>
            ) : (
                <p className="text-xs font-semibold text-emerald-700">{t('The buyer knows you are at the door')}</p>
            )}

            <div className="grid gap-2 sm:grid-cols-3">
                <label className="text-sm font-medium">
                    {t('Buyer\'s code')}
                    <Input className="mt-1" inputMode="numeric" maxLength={6} value={code} onChange={(e) => setCode(e.target.value)} />
                </label>
                <label className="text-sm font-medium">
                    {t('Money taken')}
                    <Input className="mt-1" inputMode="decimal" value={taken} onChange={(e) => setTaken(e.target.value)} />
                </label>
                <label className="text-sm font-medium">
                    {t('Or a photo of the parcel')}
                    <input type="file" accept="image/*" capture="environment" className="mt-1 block text-sm" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                </label>
            </div>
            <InputError message={errors.delivery ?? errors.code} />
            <Button disabled={code.length < 6 && photo === null} onClick={() => post('confirm', { code: photo ? null : code, photo, collected: taken })}>
                {t('Delivered')}
            </Button>
        </li>
    );
}

export default function Deliveries({ groups, holding }: { groups: { area: string; orders: Order[] }[]; holding: number }) {
    const { t, money } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('My deliveries')} />
            <div className="mx-auto w-full max-w-3xl space-y-4 p-4">
                <Flash />
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="text-2xl font-bold">{t('My deliveries')}</h1>
                    <p className="tabular text-sm">
                        {t('Money you hold')}: <strong>{money(holding)}</strong>
                    </p>
                </div>
                {groups.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">{t('Nothing to deliver now.')}</p>
                ) : (
                    groups.map((g) => (
                        <section key={g.area} className="space-y-1">
                            <h2 className="font-medium">{g.area}</h2>
                            <ul className="divide-y rounded-md border bg-card">
                                {g.orders.map((o) => (
                                    <Card key={o.id} o={o} />
                                ))}
                            </ul>
                        </section>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
