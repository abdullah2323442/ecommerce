import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Material {
    id: number;
    name: string;
    rate: number;
}

interface Profile {
    id: number;
    name: string;
    kind: 'men' | 'women' | 'wood';
    unit: string;
    values: Record<string, number>;
    measured_on: string;
    stale: boolean;
    months: number;
}

interface Props {
    kind: 'wood' | 'clothing';
    materials: Material[];
    profiles: Profile[];
    fields: Record<string, Record<string, string>>;
    woodOptions: Record<string, string>;
    clothingOptions: Record<string, string>;
    units: string[];
    urgentPercent: number;
}

export interface Person {
    profile_id: string; // '' = new measurements
    kind: 'men' | 'women';
    garment: string;
    quantity: string;
    unit: string;
    values: Record<string, string>;
}

export const blankPerson = (): Person => ({ profile_id: '', kind: 'men', garment: '', quantity: '1', unit: 'inch', values: {} });

/** Measurements for one person or one piece. A saved profile fills itself in; the order keeps a copy of the numbers. */
export function PersonBox({ person, index, profiles, fields, units, onChange, onRemove }: { person: Person; index: number; profiles: Profile[]; fields: Props['fields']; units: string[]; onChange: (p: Person) => void; onRemove?: () => void }) {
    const { t, num, date } = useI18n();
    const saved = profiles.find((p) => String(p.id) === person.profile_id);
    const set = (patch: Partial<Person>) => onChange({ ...person, ...patch });

    return (
        <fieldset className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <legend className="px-1 text-sm font-semibold">{t('Person {n}', { n: num(index + 1) })}</legend>
            <div className="grid gap-3 sm:grid-cols-3">
                <label className="text-sm font-medium">
                    {t('Measurements')}
                    <select value={person.profile_id} onChange={(e) => set({ profile_id: e.target.value })} className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm">
                        <option value="">{t('New measurements')}</option>
                        {profiles.filter((p) => p.kind !== 'wood').map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="text-sm font-medium">
                    {t('What to make')}
                    <Input className="mt-1" value={person.garment} maxLength={80} placeholder={t('Panjabi, salwar kameez...')} onChange={(e) => set({ garment: e.target.value })} />
                </label>
                <label className="text-sm font-medium">
                    {t('How many')}
                    <Input className="mt-1" inputMode="numeric" value={person.quantity} onChange={(e) => set({ quantity: e.target.value })} />
                </label>
            </div>

            {saved ? (
                <div className="space-y-2 text-sm">
                    <p className="tabular">
                        {Object.entries(saved.values).map(([k, v]) => (
                            <span key={k} className="me-3 inline-block">
                                {t(fields[saved.kind][k] ?? k)}: <strong>{num(v)}</strong>
                            </span>
                        ))}
                    </p>
                    <p className="text-xs text-[var(--pt-muted)]">{t('Measured on {date}', { date: date(saved.measured_on) })}</p>
                    {saved.stale && (
                        <div role="status" className="flex flex-wrap items-center gap-2 rounded-md bg-amber-50 p-2 text-amber-900">
                            <span className="flex-1">{t('These measurements are {n} months old. Are they still right?', { n: num(saved.months) })}</span>
                            <Button type="button" size="sm" variant="outline" onClick={() => router.put(`/custom/profiles/${saved.id}/still-correct`, {}, { preserveScroll: true, preserveState: true })}>
                                {t('Yes, still right')}
                            </Button>
                        </div>
                    )}
                </div>
            ) : (
                <>
                    <div className="flex flex-wrap gap-4 text-sm">
                        <label className="flex items-center gap-2">
                            <input type="radio" checked={person.kind === 'men'} onChange={() => set({ kind: 'men', values: {} })} /> {t("A man's clothes")}
                        </label>
                        <label className="flex items-center gap-2">
                            <input type="radio" checked={person.kind === 'women'} onChange={() => set({ kind: 'women', values: {} })} /> {t("A woman's clothes")}
                        </label>
                        <label className="flex items-center gap-2">
                            {t('Unit')}
                            <select value={person.unit} onChange={(e) => set({ unit: e.target.value })} className="h-8 rounded-md border bg-background px-2">
                                {units.map((u) => (
                                    <option key={u} value={u}>
                                        {t(u === 'inch' ? 'Inch' : u === 'foot' ? 'Foot' : 'Centimetre')}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {Object.entries(fields[person.kind]).map(([key, label]) => (
                            <label key={key} className="text-sm">
                                {t(label)}
                                <Input className="mt-1" inputMode="decimal" value={person.values[key] ?? ''} onChange={(e) => set({ values: { ...person.values, [key]: e.target.value } })} />
                            </label>
                        ))}
                    </div>
                </>
            )}
            {onRemove && (
                <Button type="button" size="sm" variant="ghost" onClick={onRemove}>
                    {t('Remove this person')}
                </Button>
            )}
        </fieldset>
    );
}

/** Ask for a piece to be made (spec ch 16). Wood: pick the wood and give the size. Clothing: measurements for one person or many. */
export default function CustomNew({ kind, materials, profiles, fields, woodOptions, clothingOptions, units, urgentPercent }: Props) {
    const { t, money, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [material, setMaterial] = useState(materials[0] ? String(materials[0].id) : '');
    const [fabricBy, setFabricBy] = useState<'shop' | 'buyer'>('shop');
    const [unit, setUnit] = useState('inch');
    const [dims, setDims] = useState<Record<string, string>>({});
    const [details, setDetails] = useState<Record<string, string | boolean>>({});
    const [people, setPeople] = useState<Person[]>([blankPerson()]);
    const [note, setNote] = useState('');
    const [urgent, setUrgent] = useState(false);
    const [photo, setPhoto] = useState<File | null>(null);
    const chosen = materials.find((m) => String(m.id) === material);
    const options = kind === 'wood' ? woodOptions : clothingOptions;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const items =
            kind === 'wood'
                ? [{ unit, values: dims }]
                : people.map((p) => (p.profile_id ? { profile_id: p.profile_id, garment: p.garment, quantity: p.quantity } : { kind: p.kind, unit: p.unit, garment: p.garment, quantity: p.quantity, values: p.values }));
        const clean = Object.fromEntries(Object.entries(details).filter(([, v]) => v !== '' && v !== false));
        router.post('/custom', { kind, material_id: kind === 'wood' || fabricBy === 'shop' ? material : null, fabric_by: kind === 'clothing' ? fabricBy : null, items, details: clean, note, urgent: urgent ? 1 : 0, photo }, { forceFormData: true });
    };

    return (
        <PublicLayout>
            <Head title={kind === 'wood' ? t('Wood made to order') : t('Clothing made to order')} />
            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-6">
                <Link href="/custom" className="text-sm underline underline-offset-4">
                    {t('Made to order')}
                </Link>
                <h1 className="font-display text-3xl font-extrabold">{kind === 'wood' ? t('Wood made to order') : t('Clothing made to order')}</h1>
                <InputError message={errors.order} />

                {(kind === 'wood' || fabricBy === 'shop') && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{kind === 'wood' ? t('Which wood?') : t('Which fabric?')}</h2>
                        <div className="grid gap-2 sm:grid-cols-3">
                            {materials.map((m) => (
                                <label key={m.id} className={`cursor-pointer rounded-xl border p-3 text-sm ${material === String(m.id) ? 'border-[var(--pt-ink)] bg-[var(--pt-sage)]' : ''}`}>
                                    <input type="radio" className="sr-only" checked={material === String(m.id)} onChange={() => setMaterial(String(m.id))} />
                                    <span className="font-semibold">{m.name}</span>
                                    <span className="tabular block text-[var(--pt-muted)]">{kind === 'wood' ? t('{amount} for each cubic foot', { amount: money(m.rate) }) : t('{amount} for each metre', { amount: money(m.rate) })}</span>
                                </label>
                            ))}
                        </div>
                        {chosen && kind === 'wood' && <p className="text-xs text-[var(--pt-muted)]">{t('Each wood has its own rate. We check the size and send you one price.')}</p>}
                    </section>
                )}

                {kind === 'clothing' && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('The fabric')}</h2>
                        <div className="flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <input type="radio" checked={fabricBy === 'shop'} onChange={() => setFabricBy('shop')} /> {t('You supply the fabric')}
                            </label>
                            <label className="flex items-center gap-2">
                                <input type="radio" checked={fabricBy === 'buyer'} onChange={() => setFabricBy('buyer')} /> {t('I bring my own fabric')}
                            </label>
                        </div>
                    </section>
                )}

                {kind === 'wood' ? (
                    <section className="space-y-3">
                        <h2 className="font-display text-xl font-bold">{t('The size')}</h2>
                        <label className="text-sm font-medium">
                            {t('Unit')}
                            <select value={unit} onChange={(e) => setUnit(e.target.value)} className="ms-2 h-9 rounded-md border bg-background px-2 text-sm">
                                {units.map((u) => (
                                    <option key={u} value={u}>
                                        {t(u === 'inch' ? 'Inch' : u === 'foot' ? 'Foot' : 'Centimetre')}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            {Object.entries(fields.wood).map(([key, label]) => (
                                <label key={key} className="text-sm">
                                    {t(label)}
                                    <Input className="mt-1" inputMode="decimal" value={dims[key] ?? ''} onChange={(e) => setDims({ ...dims, [key]: e.target.value })} />
                                </label>
                            ))}
                        </div>
                    </section>
                ) : (
                    <section className="space-y-3">
                        <h2 className="font-display text-xl font-bold">{t('The measurements')}</h2>
                        {people.map((p, i) => (
                            <PersonBox key={i} person={p} index={i} profiles={profiles} fields={fields} units={units} onChange={(np) => setPeople(people.map((x, j) => (j === i ? np : x)))} onRemove={people.length > 1 ? () => setPeople(people.filter((_, j) => j !== i)) : undefined} />
                        ))}
                        {people.length < 20 && (
                            <Button type="button" variant="outline" size="sm" onClick={() => setPeople([...people, blankPerson()])}>
                                {t('Add another person')}
                            </Button>
                        )}
                        <p className="text-xs text-[var(--pt-muted)]">
                            <Link href="/custom/profiles" className="underline underline-offset-4">
                                {t('Your saved measurements')}
                            </Link>
                        </p>
                    </section>
                )}

                <section className="space-y-3">
                    <h2 className="font-display text-xl font-bold">{t('Details')}</h2>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {Object.entries(options).map(([key, label]) =>
                            key === 'handles' || key === 'glass' ? (
                                <label key={key} className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={details[key] === true} onChange={(e) => setDetails({ ...details, [key]: e.target.checked })} /> {t(label)}
                                </label>
                            ) : (
                                <label key={key} className="text-sm">
                                    {t(label)}
                                    <Input className="mt-1" value={String(details[key] ?? '')} maxLength={80} onChange={(e) => setDetails({ ...details, [key]: e.target.value })} />
                                </label>
                            ),
                        )}
                    </div>
                    <label className="block text-sm font-medium">
                        {kind === 'wood' ? t('A photo of the place') : t('A photo of the design')}
                        <input type="file" accept="image/*" className="mt-1 block text-sm" onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} />
                    </label>
                    <label className="block text-sm font-medium">
                        {t('Anything else we should know?')}
                        <textarea value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)} rows={3} className="mt-1 block w-full rounded-md border bg-background p-2 text-sm" />
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={urgent} onChange={(e) => setUrgent(e.target.checked)} /> {t('I need it urgently (extra {n}%)', { n: num(urgentPercent) })}
                    </label>
                </section>

                <p className="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm">{t('Returns do not apply to made-to-order work. Check the measurements carefully before you confirm. Delivery is by arrangement.')}</p>
                <Button type="submit" className="h-12 w-full text-base">
                    {t('Send for a price')}
                </Button>
            </form>
        </PublicLayout>
    );
}
