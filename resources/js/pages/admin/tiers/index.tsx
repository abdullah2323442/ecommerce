import Flash from '@/components/admin/flash';
import SellingRates, { type SellingRatesProps } from '@/components/admin/selling-rates';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

interface Tier {
    id: number;
    name: string;
    discount_percent: string;
    upgrade_target: string | null;
    users_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tiers', href: '/admin/tiers' }];

function TierRow({ tier }: { tier: Tier }) {
    const { t, num, money } = useI18n();
    const [editing, setEditing] = useState(false);
    const form = useForm({ name: tier.name, discount_percent: tier.discount_percent, upgrade_target: tier.upgrade_target ?? '' });
    const customers = tier.users_count === 1 ? t('1 customer') : t('{n} customers', { n: num(tier.users_count) });

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/admin/tiers/${tier.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const remove = () => {
        if (window.confirm(t('Delete tier {name}? This cannot be undone.', { name: tier.name }))) {
            router.delete(`/admin/tiers/${tier.id}`, { preserveScroll: true });
        }
    };

    if (!editing) {
        return (
            <li className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <div className="min-w-32 flex-1">
                    <p className="font-medium">{tier.name}</p>
                    <p className="text-sm text-muted-foreground">{customers}</p>
                    {tier.upgrade_target && <p className="text-xs text-muted-foreground">{t('Earned at {amount} a month', { amount: money(tier.upgrade_target) })}</p>}
                </div>
                <p className="w-20 text-right font-display text-2xl font-extrabold tabular-nums">{num(Number(tier.discount_percent))}%</p>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                        {t('Edit')}
                    </Button>
                    <Button variant="ghost" size="sm" onClick={remove}>
                        {t('Delete')}
                    </Button>
                </div>
            </li>
        );
    }

    return (
        <li className="px-4 py-3">
            <form onSubmit={save} className="flex flex-wrap items-start gap-2">
                <div>
                    <label htmlFor={`name-${tier.id}`} className="sr-only">
                        {t('Tier name')}
                    </label>
                    <Input id={`name-${tier.id}`} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className="w-40" />
                    <InputError message={form.errors.name} />
                </div>
                <div>
                    <label htmlFor={`pct-${tier.id}`} className="sr-only">
                        {t('Discount percent')}
                    </label>
                    <Input id={`pct-${tier.id}`} type="number" step="0.01" min="0" max="100" value={form.data.discount_percent} onChange={(e) => form.setData('discount_percent', e.target.value)} className="w-28" />
                    <InputError message={form.errors.discount_percent} />
                </div>
                <div>
                    <label htmlFor={`tg-${tier.id}`} className="sr-only">
                        {t('Monthly business that earns this tier')}
                    </label>
                    <Input id={`tg-${tier.id}`} type="number" step="1" min="1" placeholder={t('Monthly target')} value={form.data.upgrade_target} onChange={(e) => form.setData('upgrade_target', e.target.value)} className="w-36" />
                    <InputError message={form.errors.upgrade_target} />
                </div>
                <Button type="submit" disabled={form.processing}>
                    {t('Save')}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => {
                        form.reset();
                        setEditing(false);
                    }}
                >
                    {t('Cancel')}
                </Button>
            </form>
            {tier.users_count > 0 && <p className="mt-2 text-sm text-muted-foreground">{t('Saving reprices all {n} customers in this tier. Manual rates are not changed.', { n: num(tier.users_count) })}</p>}
        </li>
    );
}

export default function TiersIndex({ tiers, companies, companyRates, medicineRates }: { tiers: Tier[] } & SellingRatesProps) {
    const { t } = useI18n();
    const create = useForm({ name: '', discount_percent: '', upgrade_target: '' });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        create.post('/admin/tiers', { preserveScroll: true, onSuccess: () => create.reset() });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Tiers')} />

            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Tiers')}</h1>
                    <p className="text-sm text-muted-foreground">{t('The default discount for paikari and wholesale customers. Company, medicine and manual rates override it.')}</p>
                </div>

                <Flash />
                <InputError message={(create.errors as Record<string, string>).tier} />

                {tiers.length === 0 ? (
                    <p className="rounded-md border border-dashed p-8 text-center text-muted-foreground">{t('No tiers yet. Add the first one below.')}</p>
                ) : (
                    <ul className="divide-y rounded-md border bg-card">
                        {tiers.map((tier) => (
                            <TierRow key={tier.id} tier={tier} />
                        ))}
                    </ul>
                )}

                <form onSubmit={submit} className="space-y-3 rounded-md border bg-card p-4">
                    <h2 className="font-display text-lg font-extrabold">{t('Add a tier')}</h2>
                    <div className="flex flex-wrap items-start gap-2">
                        <div>
                            <label htmlFor="new-name" className="sr-only">
                                {t('Tier name')}
                            </label>
                            <Input id="new-name" placeholder={t('Name, for example A')} value={create.data.name} onChange={(e) => create.setData('name', e.target.value)} className="w-48" />
                            <InputError message={create.errors.name} />
                        </div>
                        <div>
                            <label htmlFor="new-pct" className="sr-only">
                                {t('Discount percent')}
                            </label>
                            <Input id="new-pct" type="number" step="0.01" min="0" max="100" placeholder={t('Discount %')} value={create.data.discount_percent} onChange={(e) => create.setData('discount_percent', e.target.value)} className="w-32" />
                            <InputError message={create.errors.discount_percent} />
                        </div>
                        <div>
                            <label htmlFor="new-target" className="sr-only">
                                {t('Monthly business that earns this tier')}
                            </label>
                            <Input id="new-target" type="number" step="1" min="1" placeholder={t('Monthly target')} value={create.data.upgrade_target} onChange={(e) => create.setData('upgrade_target', e.target.value)} className="w-36" />
                        </div>
                        <Button type="submit" disabled={create.processing}>
                            {t('Add tier')}
                        </Button>
                    </div>
                </form>

                <SellingRates companies={companies} companyRates={companyRates} medicineRates={medicineRates} />
            </div>
        </AppLayout>
    );
}
