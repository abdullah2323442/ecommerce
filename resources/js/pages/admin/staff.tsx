import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Member {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role_id: number | null;
    role: string | null;
    discount_limit: number | null;
    closed: boolean;
    two_factor: boolean;
}

interface RoleRow {
    id: number;
    name: string;
    permissions: string[];
    system: boolean;
    users: number;
}

interface Props {
    staff: Member[];
    roles: RoleRow[];
    catalog: Record<string, string>;
    lendable: string[];
    lent: { id: number; who: string; permissions: string[]; until: string; note: string | null; by: string | null }[];
    ended: { id: number; who: string; permissions: string[]; ended: string; taken_back: boolean }[];
}

/** Extra powers for a staff member for a while (spec ch 25): the owner is away and decisions would be stuck. They end by themselves. */
function Lend({ staff, catalog, lendable, lent, ended }: Pick<Props, 'staff' | 'catalog' | 'lendable' | 'lent' | 'ended'>) {
    const { t, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const open = staff.filter((m) => !m.closed);
    const [who, setWho] = useState(String(open[0]?.id ?? ''));
    const [picked, setPicked] = useState<string[]>([]);
    const [hours, setHours] = useState('24');
    const [note, setNote] = useState('');
    const names = (list: string[]) => list.map((p) => catalog[p] ?? p).join(', ');

    return (
        <section className="space-y-3 rounded-md border p-3">
            <div>
                <h2 className="font-medium">{t('Lend extra powers for a while')}</h2>
                <p className="text-muted-foreground text-sm">{t('Going away? Give a staff member more for a set time. It ends by itself, and you can take it back any time.')}</p>
            </div>
            {open.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t('No open staff account.')}</p>
            ) : (
                <>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <label className="text-sm font-medium">
                            {t('Who')}
                            <select value={who} onChange={(e) => setWho(e.target.value)} className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm">
                                {open.map((m) => (
                                    <option key={m.id} value={m.id}>
                                        {m.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-sm font-medium">
                            {t('For how many hours')}
                            <Input className="mt-1" inputMode="numeric" value={hours} onChange={(e) => setHours(e.target.value)} />
                        </label>
                        <label className="text-sm font-medium">
                            {t('Why (optional)')}
                            <Input className="mt-1" maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
                        </label>
                    </div>
                    <fieldset className="flex flex-wrap gap-3 text-sm">
                        <legend className="mb-1 font-medium">{t('What they may also do')}</legend>
                        {lendable.map((p) => (
                            <label key={p} className="flex items-center gap-1.5">
                                <input type="checkbox" checked={picked.includes(p)} onChange={(e) => setPicked(e.target.checked ? [...picked, p] : picked.filter((x) => x !== p))} />
                                {catalog[p] ?? p}
                            </label>
                        ))}
                    </fieldset>
                    <InputError message={errors.lend ?? errors.permissions ?? errors.hours} />
                    <Button size="sm" disabled={picked.length === 0 || who === ''} onClick={() => router.post(`/admin/staff/${who}/powers`, { permissions: picked, hours, note }, { preserveScroll: true, onSuccess: () => setPicked([]) })}>
                        {t('Lend')}
                    </Button>
                </>
            )}

            {lent.length > 0 && (
                <ul className="divide-y rounded-md border text-sm">
                    {lent.map((l) => (
                        <li key={l.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <span>
                                <strong>{l.who}</strong> · {names(l.permissions)}
                                {l.note && <span className="text-muted-foreground"> · {l.note}</span>}
                            </span>
                            <span className="text-muted-foreground text-xs">{t('until {when}', { when: dateTime(l.until) })}</span>
                            <Button size="sm" variant="outline" onClick={() => router.delete(`/admin/powers/${l.id}`, { preserveScroll: true })}>
                                {t('Take back')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {ended.length > 0 && (
                <ul className="text-muted-foreground space-y-0.5 text-xs">
                    {ended.map((l) => (
                        <li key={l.id}>
                            {l.who} · {names(l.permissions)} · {l.taken_back ? t('taken back') : t('ended')} {dateTime(l.ended)}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Staff', href: '/admin/staff' }];

function MemberRow({ m, roles }: { m: Member; roles: RoleRow[] }) {
    const { t, num } = useI18n();
    const [role, setRole] = useState(String(m.role_id ?? roles[0]?.id ?? ''));
    const [limit, setLimit] = useState(m.discount_limit === null ? '' : String(m.discount_limit));

    return (
        <li className={`flex flex-wrap items-center gap-3 px-4 py-3 ${m.closed ? 'opacity-60' : ''}`}>
            <div className="min-w-48 flex-1">
                <p className="font-medium">
                    {m.name} {m.closed && <span className="rounded bg-red-500/15 px-1.5 text-xs font-semibold">{t('Closed')}</span>}
                </p>
                <p className="text-muted-foreground text-sm">{[m.email, m.phone].filter(Boolean).join(' · ')}</p>
            </div>
            <select
                aria-label={t('Role')}
                value={role}
                onChange={(e) => setRole(e.target.value)}
                className="bg-background h-9 rounded-md border px-2 text-sm"
            >
                {roles.map((r) => (
                    <option key={r.id} value={r.id}>
                        {r.name}
                    </option>
                ))}
            </select>
            <Input
                aria-label={t('Discount limit %')}
                className="h-9 w-24"
                type="number"
                min="0"
                max="100"
                step="any"
                placeholder={t('Limit %')}
                value={limit}
                onChange={(e) => setLimit(e.target.value)}
            />
            <Button
                size="sm"
                variant="outline"
                onClick={() =>
                    router.put(`/admin/staff/${m.id}`, { role_id: role, discount_limit: limit === '' ? null : limit }, { preserveScroll: true })
                }
            >
                {t('Save')}
            </Button>
            {m.two_factor && (
                <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Switch off two-step sign-in for {name}? Do this only if they lost their phone.', { name: m.name })) && router.post(`/admin/users/${m.id}/two-factor/reset`, {}, { preserveScroll: true })}>
                    {t('Reset two-step sign-in')}
                </Button>
            )}
            {m.closed ? (
                <Button size="sm" variant="ghost" onClick={() => router.post(`/admin/staff/${m.id}/reopen`, {}, { preserveScroll: true })}>
                    {t('Reopen')}
                </Button>
            ) : (
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        confirm(t('Close this account? Their memos and records stay.')) &&
                        router.post(`/admin/staff/${m.id}/close`, {}, { preserveScroll: true })
                    }
                >
                    {t('Close account')}
                </Button>
            )}
            <span className="sr-only">{num(m.id)}</span>
        </li>
    );
}

function RoleCard({ r, catalog }: { r: RoleRow; catalog: Props['catalog'] }) {
    const { t, num } = useI18n();
    const [name, setName] = useState(r.name);
    const [perms, setPerms] = useState<string[]>(r.permissions);
    const toggle = (k: string) => setPerms((p) => (p.includes(k) ? p.filter((x) => x !== k) : [...p, k]));

    return (
        <li className="space-y-2 px-4 py-3">
            <div className="flex flex-wrap items-center gap-2">
                <Input aria-label={t('Role name')} className="h-9 w-48" value={name} onChange={(e) => setName(e.target.value)} />
                <span className="text-muted-foreground text-sm">{t('{n} people', { n: num(r.users) })}</span>
                <Button size="sm" onClick={() => router.put(`/admin/roles/${r.id}`, { name, permissions: perms }, { preserveScroll: true })}>
                    {t('Save')}
                </Button>
                {!r.system && r.users === 0 && (
                    <Button size="sm" variant="ghost" onClick={() => router.delete(`/admin/roles/${r.id}`, { preserveScroll: true })}>
                        {t('Delete')}
                    </Button>
                )}
            </div>
            <div className="grid gap-1 sm:grid-cols-2">
                {Object.entries(catalog).map(([k, label]) => (
                    <label key={k} className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={perms.includes(k)} onChange={() => toggle(k)} />
                        {t(label)}
                    </label>
                ))}
            </div>
        </li>
    );
}

export default function Staff({ staff, roles, catalog, lendable, lent, ended }: Props) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', email: '', phone: '', role_id: String(roles[0]?.id ?? ''), password: '' });
    const [roleName, setRoleName] = useState('');
    const [roleChecks, setRoleChecks] = useState<string[]>([]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Staff')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                <header>
                    <h1 className="font-display text-2xl font-extrabold">{t('Staff')}</h1>
                    <p className="text-muted-foreground text-sm">
                        {t('Every staff member has their own account. Changes here ask for your password again.')}
                    </p>
                </header>
                <InputError message={errors.role} />

                <section className="space-y-2">
                    <h2 className="font-medium">{t('People')}</h2>
                    <ul className="divide-y rounded-md border">
                        {staff.map((m) => (
                            <MemberRow key={m.id} m={m} roles={roles} />
                        ))}
                    </ul>
                </section>

                <Lend staff={staff} catalog={catalog} lendable={lendable} lent={lent} ended={ended} />

                <section className="space-y-2 rounded-md border p-3">
                    <h2 className="font-medium">{t('Open a staff account')}</h2>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <div>
                            <Input
                                aria-label={t('Name')}
                                placeholder={t('Name')}
                                value={f.name}
                                onChange={(e) => setF({ ...f, name: e.target.value })}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div>
                            <Input
                                aria-label={t('Email')}
                                placeholder={t('Email')}
                                value={f.email}
                                onChange={(e) => setF({ ...f, email: e.target.value })}
                            />
                            <InputError message={errors.email} />
                        </div>
                        <div>
                            <Input
                                aria-label={t('Phone')}
                                placeholder={t('Phone')}
                                value={f.phone}
                                onChange={(e) => setF({ ...f, phone: e.target.value })}
                            />
                            <InputError message={errors.phone} />
                        </div>
                        <div>
                            <Input
                                aria-label={t('Password (at least 10 characters)')}
                                type="password"
                                placeholder={t('Password (at least 10 characters)')}
                                value={f.password}
                                onChange={(e) => setF({ ...f, password: e.target.value })}
                            />
                            <InputError message={errors.password} />
                        </div>
                        <select
                            aria-label={t('Role')}
                            value={f.role_id}
                            onChange={(e) => setF({ ...f, role_id: e.target.value })}
                            className="bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            {roles.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <Button
                        disabled={!f.name || !f.email || f.password.length < 10}
                        onClick={() =>
                            router.post('/admin/staff', f, {
                                preserveScroll: true,
                                onSuccess: () => setF({ ...f, name: '', email: '', phone: '', password: '' }),
                            })
                        }
                    >
                        {t('Create account')}
                    </Button>
                </section>

                <section className="space-y-2">
                    <h2 className="font-medium">{t('Roles')}</h2>
                    <ul className="divide-y rounded-md border">
                        {roles.map((r) => (
                            <RoleCard key={r.id + r.name + r.permissions.join()} r={r} catalog={catalog} />
                        ))}
                    </ul>
                    <div className="space-y-2 rounded-md border p-3">
                        <h3 className="text-sm font-medium">{t('Make a new role')}</h3>
                        <Input
                            aria-label={t('Role name')}
                            className="w-64"
                            placeholder={t('Role name')}
                            value={roleName}
                            onChange={(e) => setRoleName(e.target.value)}
                        />
                        <InputError message={errors.name} />
                        <div className="grid gap-1 sm:grid-cols-2">
                            {Object.entries(catalog).map(([k, label]) => (
                                <label key={k} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={roleChecks.includes(k)}
                                        onChange={() => setRoleChecks((p) => (p.includes(k) ? p.filter((x) => x !== k) : [...p, k]))}
                                    />
                                    {t(label)}
                                </label>
                            ))}
                        </div>
                        <Button
                            disabled={!roleName}
                            onClick={() =>
                                router.post(
                                    '/admin/roles',
                                    { name: roleName, permissions: roleChecks },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setRoleName('');
                                            setRoleChecks([]);
                                        },
                                    },
                                )
                            }
                        >
                            {t('Create role')}
                        </Button>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
