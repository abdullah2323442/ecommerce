import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Ing {
    id: number;
    name: string;
    name_bn: string | null;
    group: string;
    is_active: boolean;
}

interface Req {
    id: number;
    name: string;
    group: string | null;
    note: string | null;
    restaurant: string;
}

const GROUP: Record<string, string> = { meat: 'Meat', cheese: 'Cheese', vegetables: 'Vegetables', sauces: 'Sauces', extras: 'Extras', spices: 'Spices' };
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Ingredient list', href: '/admin/food-ingredients' }];

function GroupSelect({ value, onChange, label }: { value: string; onChange: (v: string) => void; label: string }) {
    const { t } = useI18n();

    return (
        <select aria-label={label} value={value} onChange={(e) => onChange(e.target.value)} className="h-8 rounded-md border bg-background px-2 text-xs">
            <option value="">{t('Which group?')}</option>
            {Object.entries(GROUP).map(([k, l]) => (
                <option key={k} value={k}>
                    {t(l)}
                </option>
            ))}
        </select>
    );
}

function Request({ r }: { r: Req }) {
    const { t } = useI18n();
    const [group, setGroup] = useState(r.group ?? '');
    const [bn, setBn] = useState('');
    const decide = (decision: string) => router.post(`/admin/food-ingredient-requests/${r.id}`, { decision, group, name_bn: bn }, { preserveScroll: true });

    return (
        <li className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
            <span className="min-w-48 flex-1">
                <strong>{r.name}</strong> · {r.restaurant} {r.note && <span className="text-xs text-muted-foreground">{r.note}</span>}
            </span>
            <GroupSelect value={group} onChange={setGroup} label={t('Group')} />
            <Input aria-label={t('Name in Bangla')} className="h-8 w-36" placeholder={t('Name in Bangla')} value={bn} onChange={(e) => setBn(e.target.value)} />
            <Button size="sm" disabled={group === ''} onClick={() => decide('add')}>
                {t('Add to the list')}
            </Button>
            <Button size="sm" variant="ghost" onClick={() => decide('decline')}>
                {t('Not now')}
            </Button>
        </li>
    );
}

function Row({ i }: { i: Ing }) {
    const { t } = useI18n();
    const [f, setF] = useState({ group: i.group, name_bn: i.name_bn ?? '', is_active: i.is_active });

    return (
        <li className="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm">
            <span className="min-w-40 flex-1">{i.name}</span>
            <Input aria-label={t('Name in Bangla')} className="h-8 w-36" value={f.name_bn} onChange={(e) => setF({ ...f, name_bn: e.target.value })} />
            <GroupSelect value={f.group} onChange={(v) => setF({ ...f, group: v })} label={t('Group')} />
            <label className="flex items-center gap-1 text-xs">
                <input type="checkbox" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} /> {t('In the list')}
            </label>
            <Button size="sm" variant="outline" onClick={() => router.put(`/admin/food-ingredients/${i.id}`, f, { preserveScroll: true })}>
                {t('Save')}
            </Button>
        </li>
    );
}

export default function FoodIngredients({ ingredients, requests, top, groups }: { ingredients: Ing[]; requests: Req[]; top: { name: string; count: number }[]; groups: string[] }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', name_bn: '', group: '' });
    const [q, setQ] = useState('');
    const shown = ingredients.filter((i) => q === '' || `${i.name} ${i.name_bn ?? ''}`.toLowerCase().includes(q.toLowerCase()));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Ingredient list')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Ingredient list')}</h1>
                    <p className="text-sm text-muted-foreground">{t('The one list for all restaurants. They tick from it and set their own prices; they cannot type a new name, so cheese is always cheese.')}</p>
                </div>
                <Flash />
                <InputError message={errors.food} />

                {requests.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="font-medium">{t('Restaurants ask for')}</h2>
                        <ul className="divide-y rounded-md border border-warning/40 bg-card">{requests.map((r) => <Request key={r.id} r={r} />)}</ul>
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Add an ingredient')}</h2>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input aria-label={t('Name')} className="h-8 w-48" placeholder={t('Name')} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                        <Input aria-label={t('Name in Bangla')} className="h-8 w-40" placeholder={t('Name in Bangla')} value={f.name_bn} onChange={(e) => setF({ ...f, name_bn: e.target.value })} />
                        <GroupSelect value={f.group} onChange={(v) => setF({ ...f, group: v })} label={t('Group')} />
                        <Button size="sm" disabled={f.name.trim().length < 2 || f.group === ''} onClick={() => router.post('/admin/food-ingredients', f, { preserveScroll: true, onSuccess: () => setF({ name: '', name_bn: '', group: f.group }) })}>
                            {t('Add')}
                        </Button>
                    </div>
                </section>

                {top.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="font-medium">{t('What sells most')}</h2>
                        <ol className="tabular list-decimal ps-5 text-sm">
                            {top.map((x) => (
                                <li key={x.name}>
                                    {x.name} · {num(x.count)}
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                <section className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-medium">
                            {t('The list')} ({num(ingredients.length)})
                        </h2>
                        <Input aria-label={t('Search ingredients')} className="h-8 max-w-56" placeholder={t('Search ingredients')} value={q} onChange={(e) => setQ(e.target.value)} />
                    </div>
                    {groups.map((g) => {
                        const rows = shown.filter((i) => i.group === g);

                        return rows.length === 0 ? null : (
                            <div key={g} className="space-y-1">
                                <h3 className="text-sm font-semibold">{t(GROUP[g])}</h3>
                                <ul className="divide-y rounded-md border bg-card">{rows.map((i) => <Row key={`${i.id}-${i.is_active}-${i.group}`} i={i} />)}</ul>
                            </div>
                        );
                    })}
                </section>
            </div>
        </AppLayout>
    );
}
