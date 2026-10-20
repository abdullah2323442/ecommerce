import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Profile {
    id: number;
    name: string;
    kind: 'men' | 'women' | 'wood';
    unit: string;
    values: Record<string, number>;
    measured_on: string;
    stale: boolean;
    months: number;
    orders: { token: string; number: string; status: string; at: string }[];
}

interface Props {
    profiles: Profile[];
    fields: Record<string, Record<string, string>>;
    units: string[];
    staleMonths: number;
}

/** One profile's form: new, or an edit of a saved one. The numbers are the same fields the order asks for. */
function ProfileForm({ fields, units, profile, onDone }: { fields: Props['fields']; units: string[]; profile?: Profile; onDone?: () => void }) {
    const { t } = useI18n();
    const [name, setName] = useState(profile?.name ?? '');
    const [kind, setKind] = useState<Profile['kind']>(profile?.kind ?? 'men');
    const [unit, setUnit] = useState(profile?.unit ?? 'inch');
    const [values, setValues] = useState<Record<string, string>>(Object.fromEntries(Object.entries(profile?.values ?? {}).map(([k, v]) => [k, String(v)])));
    const errors = usePage().props.errors as Record<string, string>;

    const send = (e: React.FormEvent) => {
        e.preventDefault();
        const body = { name, kind, unit, values };
        const opts = { preserveScroll: true, onSuccess: () => onDone?.() };
        profile ? router.put(`/custom/profiles/${profile.id}`, body, opts) : router.post('/custom/profiles', body, opts);
    };

    return (
        <form onSubmit={send} className="space-y-3 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
            <div className="grid gap-3 sm:grid-cols-3">
                <label className="text-sm font-medium sm:col-span-1">
                    {t('Name')}
                    <Input className="mt-1" value={name} maxLength={60} placeholder={t('Mine, My son, Rahim...')} onChange={(e) => setName(e.target.value)} />
                </label>
                <label className="text-sm font-medium">
                    {t('For')}
                    <select value={kind} onChange={(e) => setKind(e.target.value as Profile['kind'])} className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm">
                        <option value="men">{t("A man's clothes")}</option>
                        <option value="women">{t("A woman's clothes")}</option>
                        <option value="wood">{t('A piece of wood work')}</option>
                    </select>
                </label>
                <label className="text-sm font-medium">
                    {t('Unit')}
                    <select value={unit} onChange={(e) => setUnit(e.target.value)} className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm">
                        {units.map((u) => (
                            <option key={u} value={u}>
                                {t(u === 'inch' ? 'Inch' : u === 'foot' ? 'Foot' : 'Centimetre')}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {Object.entries(fields[kind]).map(([key, label]) => (
                    <label key={key} className="text-sm">
                        {t(label)}
                        <Input className="mt-1" inputMode="decimal" value={values[key] ?? ''} onChange={(e) => setValues({ ...values, [key]: e.target.value })} />
                    </label>
                ))}
            </div>
            <InputError message={errors.profile ?? errors.name ?? errors.values} />
            <Button type="submit" size="sm">
                {t('Save')}
            </Button>
        </form>
    );
}

function Card({ p, fields, units }: { p: Profile; fields: Props['fields']; units: string[] }) {
    const { t, num, date, dateTime } = useI18n();
    const [editing, setEditing] = useState(false);

    return (
        <li className="space-y-2 rounded-[1.5rem] border bg-[var(--pt-surface)] p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-display text-xl font-bold">{p.name}</p>
                <p className="text-xs text-[var(--pt-muted)]">{t('Measured on {date}', { date: date(p.measured_on) })}</p>
            </div>
            {p.stale && (
                <div role="status" className="flex flex-wrap items-center gap-2 rounded-md bg-warning/10 p-2 text-sm text-warning">
                    <span className="flex-1">{t('These measurements are {n} months old. Are they still right?', { n: num(p.months) })}</span>
                    <Button size="sm" variant="outline" onClick={() => router.put(`/custom/profiles/${p.id}/still-correct`, {}, { preserveScroll: true })}>
                        {t('Yes, still right')}
                    </Button>
                </div>
            )}
            <p className="tabular text-sm">
                {Object.entries(p.values).map(([k, v]) => (
                    <span key={k} className="me-3 inline-block">
                        {t(fields[p.kind][k] ?? k)}: <strong>{num(v)}</strong>
                    </span>
                ))}
                <span className="text-[var(--pt-muted)]">({t(p.unit === 'inch' ? 'Inch' : p.unit === 'foot' ? 'Foot' : 'Centimetre')})</span>
            </p>
            {p.orders.length > 0 && (
                <p className="text-xs text-[var(--pt-muted)]">
                    {t('Used for')}:{' '}
                    {p.orders.map((o) => (
                        <Link key={o.token} href={`/custom/${o.token}`} className="me-2 underline underline-offset-4" title={dateTime(o.at)}>
                            {o.number}
                        </Link>
                    ))}
                </p>
            )}
            {editing ? (
                <ProfileForm fields={fields} units={units} profile={p} onDone={() => setEditing(false)} />
            ) : (
                <div className="flex gap-2">
                    <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                        {t('Edit')}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Remove this profile?')) && router.delete(`/custom/profiles/${p.id}`, { preserveScroll: true })}>
                        {t('Remove')}
                    </Button>
                </div>
            )}
        </li>
    );
}

/** Saved measurements, by name (spec ch 16.3): next time just pick the name. */
export default function Profiles({ profiles, fields, units, staleMonths }: Props) {
    const { t, num } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    return (
        <PublicLayout>
            <Head title={t('Your saved measurements')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Your saved measurements')}</h1>
                    <Link href="/custom" className="text-sm font-semibold underline underline-offset-4">
                        {t('Made to order')}
                    </Link>
                </div>
                <p className="text-sm text-[var(--pt-muted)]">{t('Save as many as you like, each under its own name. Next time you only pick the name. We ask again after {n} months.', { n: num(staleMonths) })}</p>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Add a person or a piece')}</h2>
                    <ProfileForm fields={fields} units={units} />
                </section>
                {profiles.length === 0 ? (
                    <p className="rounded-[1.5rem] border border-dashed p-8 text-center text-[var(--pt-muted)]">{t('Nothing saved yet.')}</p>
                ) : (
                    <ul className="space-y-3">
                        {profiles.map((p) => (
                            <Card key={p.id} p={p} fields={fields} units={units} />
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
