import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface CatalogRow {
    id: number;
    name: string;
    name_bn: string | null;
    group: string;
    ticked: boolean;
    ri_id: number | null;
    price: number | null;
    available: boolean;
    sold_out_today: boolean;
}

interface Base {
    id: number;
    kind: string;
    name: string;
    price: number;
    cook_minutes: number;
    available: boolean;
    sold_out_today: boolean;
}

interface Props {
    restaurant: { name: string; max_ingredients: number; custom_extra_minutes: number };
    groups: string[];
    kinds: string[];
    catalog: CatalogRow[];
    bases: Base[];
    rules: { id: number; a: string; b: string }[];
    ticked: { id: number; name: string }[];
    requests: { id: number; name: string; status: string; note: string | null }[];
}

const GROUP: Record<string, string> = { meat: 'Meat', cheese: 'Cheese', vegetables: 'Vegetables', sauces: 'Sauces', extras: 'Extras', spices: 'Spices' };
const KIND: Record<string, string> = { burger: 'Burger', pizza: 'Pizza', sandwich: 'Sandwich', roll: 'Roll' };

function Row({ c }: { c: CatalogRow }) {
    const { t, money } = useI18n();
    const [price, setPrice] = useState(c.price !== null ? String(c.price) : '');

    return (
        <li className="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm">
            <span className="min-w-40 flex-1">
                {c.name} {c.name_bn && <span className="text-xs text-[var(--pt-muted)]">{c.name_bn}</span>}
            </span>
            <Input aria-label={t('Your price')} className="h-8 w-24" inputMode="decimal" placeholder={t('Your price')} value={price} onChange={(e) => setPrice(e.target.value)} />
            <Button size="sm" variant={c.ticked ? 'outline' : 'default'} disabled={price === '' || Number(price) < 0} onClick={() => router.post('/food/vendor/custom/tick', { ingredient_id: c.id, price }, { preserveScroll: true })}>
                {c.ticked ? t('Change the price') : t('We have it')}
            </Button>
            {c.ticked && c.ri_id !== null && (
                <>
                    <label className="flex items-center gap-1 text-xs">
                        <input type="checkbox" checked={c.available} onChange={(e) => router.put(`/food/vendor/custom/ri/${c.ri_id}/available`, { on: e.target.checked ? 1 : 0 }, { preserveScroll: true })} /> {t('On')}
                    </label>
                    <label className="flex items-center gap-1 text-xs">
                        <input type="checkbox" checked={c.sold_out_today} disabled={!c.available} onChange={(e) => router.put(`/food/vendor/custom/ri/${c.ri_id}/sold-out`, { on: e.target.checked ? 1 : 0 }, { preserveScroll: true })} /> {t('Sold out today')}
                    </label>
                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/food/vendor/custom/ingredients/${c.id}`, { preserveScroll: true })}>
                        {t('Remove')}
                    </Button>
                    <span className="tabular text-xs text-[var(--pt-muted)]">{money(c.price ?? 0)}</span>
                </>
            )}
        </li>
    );
}

function BaseRow({ b }: { b: Base }) {
    const { t } = useI18n();
    const [f, setF] = useState({ kind: b.kind, name: b.name, price: String(b.price), cook_minutes: String(b.cook_minutes) });

    return (
        <li className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
            <select aria-label={t('Kind')} value={f.kind} onChange={(e) => setF({ ...f, kind: e.target.value })} className="h-8 rounded-md border bg-background px-2 text-xs">
                {Object.entries(KIND).map(([k, l]) => (
                    <option key={k} value={k}>
                        {t(l)}
                    </option>
                ))}
            </select>
            <Input aria-label={t('Name')} className="h-8 w-36" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
            <Input aria-label={t('Price')} className="h-8 w-24" inputMode="decimal" value={f.price} onChange={(e) => setF({ ...f, price: e.target.value })} />
            <Input aria-label={t('Minutes to cook')} className="h-8 w-20" inputMode="numeric" value={f.cook_minutes} onChange={(e) => setF({ ...f, cook_minutes: e.target.value })} />
            <Button size="sm" variant="outline" onClick={() => router.post(`/food/vendor/custom/bases/${b.id}`, f, { preserveScroll: true })}>
                {t('Save')}
            </Button>
            <label className="flex items-center gap-1 text-xs">
                <input type="checkbox" checked={b.available} onChange={(e) => router.put(`/food/vendor/custom/bases/${b.id}/available`, { on: e.target.checked ? 1 : 0 }, { preserveScroll: true })} /> {t('On')}
            </label>
            <label className="flex items-center gap-1 text-xs">
                <input type="checkbox" checked={b.sold_out_today} disabled={!b.available} onChange={(e) => router.put(`/food/vendor/custom/bases/${b.id}/sold-out`, { on: e.target.checked ? 1 : 0 }, { preserveScroll: true })} /> {t('Sold out today')}
            </label>
            <Button size="sm" variant="ghost" onClick={() => router.delete(`/food/vendor/custom/bases/${b.id}`, { preserveScroll: true })}>
                {t('Remove')}
            </Button>
        </li>
    );
}

/** Custom food for one restaurant (spec ch 17). The ingredient list is ours; they tick what they have and set their own price. They cannot type a new name: they ask. */
export default function VendorCustom({ restaurant, groups, catalog, bases, rules, ticked, requests }: Props) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [search, setSearch] = useState('');
    const [only, setOnly] = useState(false);
    const [limits, setLimits] = useState({ max: String(restaurant.max_ingredients), extra: String(restaurant.custom_extra_minutes) });
    const [base, setBase] = useState({ kind: 'burger', name: '', price: '', cook_minutes: '12' });
    const [rule, setRule] = useState({ a: '', b: '' });
    const [ask, setAsk] = useState({ name: '', group: '' });
    const shown = catalog.filter((c) => (!only || c.ticked) && (search === '' || `${c.name} ${c.name_bn ?? ''}`.toLowerCase().includes(search.toLowerCase())));

    return (
        <PublicLayout>
            <Head title={t('Custom food')} />
            <div className="mx-auto max-w-4xl space-y-8">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Custom food')}</h1>
                    <Link href="/food/vendor" className="text-sm font-semibold underline underline-offset-4">
                        {restaurant.name}
                    </Link>
                </div>
                {flash && <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">{flash}</p>}
                <InputError message={errors.vendor ?? errors.kind ?? errors.ingredient_id} />

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Bases')}</h2>
                    <p className="text-sm text-[var(--pt-muted)]">{t('What a buyer builds on: a burger, a pizza, a sandwich, a roll.')}</p>
                    {bases.length > 0 && <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">{bases.map((b) => <BaseRow key={`${b.id}-${b.price}-${b.name}`} b={b} />)}</ul>}
                    <div className="flex flex-wrap items-center gap-2 rounded-2xl border p-3 text-sm">
                        <select aria-label={t('Kind')} value={base.kind} onChange={(e) => setBase({ ...base, kind: e.target.value })} className="h-8 rounded-md border bg-background px-2 text-xs">
                            {Object.entries(KIND).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {t(l)}
                                </option>
                            ))}
                        </select>
                        <Input aria-label={t('Name')} className="h-8 w-36" placeholder={t('Name')} value={base.name} onChange={(e) => setBase({ ...base, name: e.target.value })} />
                        <Input aria-label={t('Price')} className="h-8 w-24" inputMode="decimal" placeholder={t('Price')} value={base.price} onChange={(e) => setBase({ ...base, price: e.target.value })} />
                        <Input aria-label={t('Minutes to cook')} className="h-8 w-20" inputMode="numeric" value={base.cook_minutes} onChange={(e) => setBase({ ...base, cook_minutes: e.target.value })} />
                        <Button size="sm" disabled={Number(base.price) <= 0} onClick={() => router.post('/food/vendor/custom/bases', base, { preserveScroll: true, onSuccess: () => setBase({ ...base, name: '', price: '' }) })}>
                            {t('Add a base')}
                        </Button>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Ingredients')}</h2>
                    <p className="text-sm text-[var(--pt-muted)]">{t('Tick what you have and set your own price. The list is ours, so the same cheese is the same everywhere. Not in the list? Ask us below.')}</p>
                    <div className="flex flex-wrap items-center gap-3">
                        <Input aria-label={t('Search ingredients')} className="h-9 max-w-64" placeholder={t('Search ingredients')} value={search} onChange={(e) => setSearch(e.target.value)} />
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={only} onChange={(e) => setOnly(e.target.checked)} /> {t('Only what I have')}
                        </label>
                    </div>
                    {groups.map((g) => {
                        const rows = shown.filter((c) => c.group === g);

                        return rows.length === 0 ? null : (
                            <div key={g} className="space-y-1">
                                <h3 className="text-sm font-semibold">{t(GROUP[g])}</h3>
                                <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">{rows.map((c) => <Row key={`${c.id}-${c.price}-${c.available}-${c.sold_out_today}`} c={c} />)}</ul>
                            </div>
                        );
                    })}
                </section>

                <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <h2 className="font-display text-xl font-bold">{t('Limit and time')}</h2>
                    <div className="flex flex-wrap items-end gap-3 text-sm">
                        <label>
                            {t('Most ingredients in one dish')}
                            <Input className="mt-1 h-9 w-24" inputMode="numeric" value={limits.max} onChange={(e) => setLimits({ ...limits, max: e.target.value })} />
                        </label>
                        <label>
                            {t('Extra minutes for custom food')}
                            <Input className="mt-1 h-9 w-24" inputMode="numeric" value={limits.extra} onChange={(e) => setLimits({ ...limits, extra: e.target.value })} />
                        </label>
                        <Button onClick={() => router.put('/food/vendor/custom/limits', limits, { preserveScroll: true })}>{t('Save')}</Button>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('These do not go together')}</h2>
                    {rules.length > 0 && (
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)] text-sm">
                            {rules.map((r) => (
                                <li key={r.id} className="flex items-center justify-between px-3 py-2">
                                    <span>{t('{a} does not go with {b}.', { a: r.a, b: r.b })}</span>
                                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/food/vendor/custom/rules/${r.id}`, { preserveScroll: true })}>
                                        {t('Remove')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        {(['a', 'b'] as const).map((k) => (
                            <select key={k} aria-label={k === 'a' ? t('First ingredient') : t('Second ingredient')} value={rule[k]} onChange={(e) => setRule({ ...rule, [k]: e.target.value })} className="h-9 rounded-md border bg-background px-2">
                                <option value="">{k === 'a' ? t('First ingredient') : t('Second ingredient')}</option>
                                {ticked.map((x) => (
                                    <option key={x.id} value={x.id}>
                                        {x.name}
                                    </option>
                                ))}
                            </select>
                        ))}
                        <Button size="sm" variant="outline" disabled={rule.a === '' || rule.b === '' || rule.a === rule.b} onClick={() => router.post('/food/vendor/custom/rules', rule, { preserveScroll: true, onSuccess: () => setRule({ a: '', b: '' }) })}>
                            {t('Add')}
                        </Button>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Ask us to add an ingredient')}</h2>
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <Input aria-label={t('Name')} className="h-9 w-56" placeholder={t('Name')} value={ask.name} onChange={(e) => setAsk({ ...ask, name: e.target.value })} />
                        <select aria-label={t('Group')} value={ask.group} onChange={(e) => setAsk({ ...ask, group: e.target.value })} className="h-9 rounded-md border bg-background px-2">
                            <option value="">{t('Which group?')}</option>
                            {groups.map((g) => (
                                <option key={g} value={g}>
                                    {t(GROUP[g])}
                                </option>
                            ))}
                        </select>
                        <Button size="sm" disabled={ask.name.trim().length < 2} onClick={() => router.post('/food/vendor/custom/requests', ask, { preserveScroll: true, onSuccess: () => setAsk({ name: '', group: '' }) })}>
                            {t('Ask')}
                        </Button>
                    </div>
                    {requests.length > 0 && (
                        <ul className="text-sm text-[var(--pt-muted)]">
                            {requests.map((r) => (
                                <li key={r.id}>
                                    {r.name} · {t(r.status === 'pending' ? 'Waiting for us' : r.status === 'added' ? 'Added to the list' : 'Not added')}
                                    {r.note && ` · ${r.note}`}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </PublicLayout>
    );
}
