import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

type Value = number | string | boolean | number[] | string[] | null;

interface Item {
    key: string;
    type: 'number' | 'text' | 'days' | 'dates' | 'toggle' | 'select';
    options: Record<string, string> | null;
    label: string;
    min: number | null;
    max: number | null;
    step: number | null;
    value: Value;
}

interface Group {
    key: string;
    title: string;
    hint: string | null;
    items: Item[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Settings', href: '/admin/settings' }];
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const field = (key: string) => key.replace(/\./g, '_');

function GroupCard({ group }: { group: Group }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [values, setValues] = useState<Record<string, Value>>(() => Object.fromEntries(group.items.map((i) => [field(i.key), i.value ?? (i.type === 'days' || i.type === 'dates' ? [] : '')])));
    const set = (k: string, v: Value) => setValues((prev) => ({ ...prev, [k]: v }));

    return (
        <section className="space-y-3 rounded-lg border bg-card p-4">
            <div>
                <h2 className="font-display text-lg font-extrabold">{t(group.title)}</h2>
                {group.hint && <p className="text-muted-foreground text-sm">{t(group.hint)}</p>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                {group.items.map((i) => {
                    const k = field(i.key);
                    const error = Object.entries(errors).find(([name]) => name === k || name.startsWith(`${k}.`))?.[1];

                    if (i.type === 'days') {
                        const picked = (values[k] as number[]) ?? [];

                        return (
                            <fieldset key={i.key} className="sm:col-span-2">
                                <legend className="text-sm font-medium">{t(i.label)}</legend>
                                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-sm">
                                    {DAYS.map((d, n) => (
                                        <label key={d} className="flex items-center gap-2">
                                            <input type="checkbox" checked={picked.includes(n)} onChange={() => set(k, picked.includes(n) ? picked.filter((x) => x !== n) : [...picked, n])} />
                                            {t(d)}
                                        </label>
                                    ))}
                                </div>
                                <InputError message={error} />
                            </fieldset>
                        );
                    }

                    if (i.type === 'dates') {
                        const dates = (values[k] as string[]) ?? [];

                        return (
                            <fieldset key={i.key} className="sm:col-span-2">
                                <legend className="text-sm font-medium">{t(i.label)}</legend>
                                <ul className="mt-2 flex flex-wrap gap-2">
                                    {dates.map((d, n) => (
                                        <li key={n} className="flex items-center gap-1">
                                            <Input aria-label={t(i.label)} className="h-9 w-40" type="date" value={d} onChange={(e) => set(k, dates.map((x, m) => (m === n ? e.target.value : x)))} />
                                            <Button type="button" size="icon" variant="ghost" aria-label={t('Remove')} onClick={() => set(k, dates.filter((_, m) => m !== n))}>
                                                <X className="size-4" />
                                            </Button>
                                        </li>
                                    ))}
                                    <li>
                                        <Button type="button" size="sm" variant="outline" className="h-9" onClick={() => set(k, [...dates, ''])}>
                                            <Plus className="size-4" /> {t('Add a day')}
                                        </Button>
                                    </li>
                                </ul>
                                <InputError message={error} />
                            </fieldset>
                        );
                    }

                    if (i.type === 'select' && i.options) {
                        return (
                            <label key={i.key} className="text-sm">
                                <span className="font-medium">{t(i.label)}</span>
                                <select value={String(values[k] ?? '')} onChange={(e) => set(k, e.target.value)} className="bg-background mt-1 block h-9 w-full rounded-md border px-2 text-sm">
                                    {Object.entries(i.options).map(([v, label]) => (
                                        <option key={v} value={v}>
                                            {t(label)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        );
                    }

                    if (i.type === 'toggle') {
                        return (
                            <label key={i.key} className="flex items-center gap-2 text-sm sm:col-span-2">
                                <input type="checkbox" checked={Boolean(values[k])} onChange={(e) => set(k, e.target.checked)} />
                                <span className="font-medium">{t(i.label)}</span>
                            </label>
                        );
                    }

                    return (
                        <label key={i.key} className="text-sm">
                            <span className="font-medium">{t(i.label)}</span>
                            <Input
                                className="mt-1 h-9"
                                type={i.type === 'number' ? 'number' : 'text'}
                                min={i.min ?? undefined}
                                max={i.max ?? undefined}
                                step={i.step ?? undefined}
                                value={(values[k] as string | number) ?? ''}
                                onChange={(e) => set(k, e.target.value)}
                            />
                            <InputError message={error} />
                        </label>
                    );
                })}
            </div>

            <Button
                onClick={() =>
                    router.put(
                        `/admin/settings/${group.key}`,
                        { ...values, ...Object.fromEntries(group.items.filter((i) => i.type === 'dates').map((i) => [field(i.key), ((values[field(i.key)] as string[]) ?? []).filter(Boolean)])) },
                        { preserveScroll: true },
                    )
                }
            >
                {t('Save')}
            </Button>
        </section>
    );
}

export default function Settings({ groups }: { groups: Group[] }) {
    const { t } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Settings')} />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Settings')}</h1>
                    <p className="text-muted-foreground text-sm">{t('Your rules in one place. Every change is logged.')}</p>
                </header>
                {groups.map((g) => (
                    <GroupCard key={g.key} group={g} />
                ))}
            </div>
        </AppLayout>
    );
}
