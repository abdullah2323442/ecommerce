import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Field {
    name: string;
    label: string;
    type: 'text' | 'url' | 'select' | 'textarea';
    secret: boolean;
    required: boolean;
    help: string | null;
    options: string[] | null;
    value: string | null;
    set: boolean | null;
    hint: string | null;
}

interface Provider {
    key: string;
    name: string;
    help: string | null;
    ready: boolean;
    redirect_uri: string | null;
    enabled: boolean;
    mode: 'test' | 'live';
    tested_at: string | null;
    test_ok: boolean | null;
    test_note: string | null;
    fields: Field[];
}

interface Kind {
    type: string;
    label: string;
    hint: string;
    single: boolean;
    providers: Provider[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connections', href: '/admin/connections' }];

function Card({ p }: { p: Provider }) {
    const { t, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [vals, setVals] = useState<Record<string, string>>(Object.fromEntries(p.fields.map((f) => [f.name, f.secret ? '' : (f.value ?? '')])));
    const [clear, setClear] = useState<string[]>([]);
    const [enabled, setEnabled] = useState(p.enabled);
    const [mode, setMode] = useState(p.mode);
    const [to, setTo] = useState('');
    const label = (s: string) => t(s);

    return (
        <li className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-semibold">{p.name}</h3>
                <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${p.enabled ? 'bg-success/15 text-success' : 'bg-muted'}`}>
                    {p.enabled ? (p.mode === 'live' ? t('On, live') : t('On, testing')) : p.ready ? t('Off') : t('Not built into the site yet')}
                </span>
            </div>
            {p.help && <p className="text-sm text-muted-foreground">{label(p.help)}</p>}
            {p.redirect_uri && (
                <p className="text-sm">
                    {t('Register this exact address with them')}: <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs break-all">{p.redirect_uri}</code>
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                {p.fields.map((f) => (
                    <label key={f.name} className={`block text-sm font-medium ${f.type === 'textarea' ? 'sm:col-span-2' : ''}`}>
                        {label(f.label)}
                        {f.required && <span aria-hidden> *</span>}
                        {f.type === 'select' ? (
                            <select value={vals[f.name]} onChange={(e) => setVals({ ...vals, [f.name]: e.target.value })} className="mt-1 block h-9 w-full rounded-md border bg-background px-2 text-sm">
                                {f.options?.map((o) => (
                                    <option key={o}>{o}</option>
                                ))}
                            </select>
                        ) : f.type === 'textarea' ? (
                            <textarea rows={3} autoComplete="off" value={vals[f.name]} placeholder={f.set ? `${f.hint} · ${t('saved. Write a new one to change it')}` : ''} onChange={(e) => setVals({ ...vals, [f.name]: e.target.value })} className="mt-1 w-full rounded-md border bg-background p-2 font-mono text-xs" />
                        ) : (
                            <Input
                                className="mt-1"
                                type={f.secret ? 'password' : 'text'}
                                autoComplete="off"
                                value={vals[f.name]}
                                placeholder={f.secret && f.set ? `${f.hint} · ${t('saved. Write a new one to change it')}` : ''}
                                onChange={(e) => setVals({ ...vals, [f.name]: e.target.value })}
                            />
                        )}
                        {f.help && <span className="mt-0.5 block text-xs font-normal text-muted-foreground">{label(f.help)}</span>}
                        {f.secret && f.set && (
                            <span className="mt-0.5 flex items-center gap-1.5 text-xs font-normal">
                                <input type="checkbox" checked={clear.includes(f.name)} onChange={(e) => setClear(e.target.checked ? [...clear, f.name] : clear.filter((x) => x !== f.name))} />
                                {t('Remove the saved value')}
                            </span>
                        )}
                    </label>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-4 text-sm">
                <label className="flex items-center gap-1.5">
                    <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
                    {t('Switch on')}
                </label>
                {!p.key.startsWith('social:') && (
                <label className="flex items-center gap-1.5">
                    {t('Mode')}
                    <select value={mode} onChange={(e) => setMode(e.target.value as 'test' | 'live')} className="h-8 rounded-md border bg-background px-2">
                        <option value="test">{t('Testing (nothing real is sent or charged)')}</option>
                        <option value="live">{t('Live')}</option>
                    </select>
                </label>
                )}
                <Button size="sm" onClick={() => router.put(`/admin/connections/${p.key}`, { fields: vals, clear, enabled: enabled ? 1 : 0, mode }, { preserveScroll: true, onSuccess: () => setClear([]) })}>
                    {t('Save')}
                </Button>
            </div>
            <InputError message={errors.connection} />

            {p.ready && !p.key.startsWith('social:') && (
                <div className="flex flex-wrap items-center gap-2 border-t pt-3 text-sm">
                    {p.key === 'sms:http' && <Input aria-label={t('Send a test to this number')} className="h-8 w-52" placeholder="01712345678" value={to} onChange={(e) => setTo(e.target.value)} />}
                    <Button size="sm" variant="outline" onClick={() => router.post(`/admin/connections/${p.key}/test`, { to }, { preserveScroll: true })}>
                        {t('Try it')}
                    </Button>
                    {p.tested_at && (
                        <span className={p.test_ok ? 'text-success' : 'text-danger'}>
                            {p.test_ok ? t('Worked') : t('Did not work')} · {dateTime(p.tested_at)} {p.test_note && `· ${label(p.test_note)}`}
                        </span>
                    )}
                </div>
            )}
        </li>
    );
}

export default function Connections({ types }: { types: Kind[]; redirectBase: string }) {
    const { t } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Connections')} />
            <div className="mx-auto w-full max-w-4xl space-y-8 p-4">
                <Flash />
                <div>
                    <h1 className="text-2xl font-bold">{t('Connections')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Keys for the outside services. They are stored encrypted and are never shown again after you save them. A service marked "not built into the site yet" can have its keys saved ahead of time, but cannot be switched on.')}</p>
                </div>
                {types.map((k) => (
                    <section key={k.type} className="space-y-3">
                        <div>
                            <h2 className="text-lg font-semibold">{t(k.label)}</h2>
                            <p className="text-sm text-muted-foreground">
                                {t(k.hint)} {k.single && t('Only one can be on at a time.')}
                            </p>
                        </div>
                        <ul className="space-y-3">
                            {k.providers.map((p) => (
                                <Card key={p.key} p={p} />
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </AppLayout>
    );
}
