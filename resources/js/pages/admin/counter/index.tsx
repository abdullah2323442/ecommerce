import Flash from '@/components/admin/flash';
import ReportMismatch from '@/components/admin/report-mismatch';
import { useOfflineReady } from '@/hooks/use-offline-ready';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Draft {
    id: number;
    kind: 'retail' | 'paikari' | 'wholesale';
    label: string | null;
    customer: string | null;
    phone: string | null;
    lines: number;
    channel: string;
    minutes_open: number;
    stale: boolean;
    staff: string | null;
    same_customer: number;
}

interface Found {
    id: number;
    name: string;
    phone: string | null;
    area?: string | null;
    kind: 'paikari' | 'wholesale';
    open_invoice: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Counter', href: '/admin/counter' }];

function NewShop() {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', phone: '', area: '' });
    const ok = f.name.trim() !== '' && f.phone.trim() !== '' && f.area.trim() !== '';

    return (
        <details className="rounded-md border p-3">
            <summary className="cursor-pointer text-sm font-medium">{t('A new shop with no profile? Add it now')}</summary>
            <div className="mt-3 flex flex-wrap items-start gap-2">
                <Input
                    aria-label={t('Shop name')}
                    className="w-48"
                    placeholder={t('Shop name')}
                    value={f.name}
                    onChange={(e) => setF({ ...f, name: e.target.value })}
                />
                <div>
                    <Input
                        aria-label={t('Phone')}
                        className="w-40"
                        placeholder={t('Phone')}
                        value={f.phone}
                        onChange={(e) => setF({ ...f, phone: e.target.value })}
                    />
                    <InputError message={errors.phone} />
                </div>
                <Input
                    aria-label={t('Area')}
                    className="w-40"
                    placeholder={t('Area')}
                    value={f.area}
                    onChange={(e) => setF({ ...f, area: e.target.value })}
                />
                <Button disabled={!ok} onClick={() => router.post('/admin/counter/shops', f)}>
                    {t('Add and start')}
                </Button>
            </div>
            <p className="text-muted-foreground mt-2 text-xs">{t('It gets the default rate. The admin sets its own rate later.')}</p>
        </details>
    );
}

function CustomerPicker() {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Found[]>([]);

    useEffect(() => {
        if (q.trim().length < 2) {
            setResults([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/admin/counter/customers?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => setResults(d.results))
                .catch(() => setResults([]));
        }, 200);
        return () => clearTimeout(timer);
    }, [q]);

    return (
        <div className="space-y-2">
            <label htmlFor="cust-q" className="text-sm font-medium">
                {t('Shop customer')}
            </label>
            <Input id="cust-q" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Name or phone')} autoComplete="off" />
            <InputError message={errors.customer_id} />
            <ul className="divide-y rounded-md border">
                {results.map((c) => (
                    <li key={c.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
                        <div className="min-w-40 flex-1">
                            <p className="font-medium">{c.name}</p>
                            <p className="text-muted-foreground text-sm">{[c.area, c.phone].filter(Boolean).join(' · ')}</p>
                            {c.open_invoice && <p className="text-xs text-warning">{t('An order is already open for this customer.')}</p>}
                        </div>
                        {c.open_invoice ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={`/admin/counter/${c.open_invoice}`}>{t('Open it')}</Link>
                            </Button>
                        ) : (
                            <Button size="sm" onClick={() => router.post('/admin/counter', { kind: c.kind, customer_id: c.id })}>
                                {t('Start')}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}

interface Note {
    id: number;
    note: string;
    customer: string | null;
    phone: string | null;
    minutes: number;
}

function Notes({ notes }: { notes: Note[] }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [q, setQ] = useState('');
    const [found, setFound] = useState<Found[]>([]);
    const [who, setWho] = useState<Found | null>(null);
    const [text, setText] = useState('');

    useEffect(() => {
        if (who || q.trim().length < 2) {
            setFound([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/admin/counter/customers?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((d) => setFound(d.results))
                .catch(() => setFound([]));
        }, 200);
        return () => clearTimeout(timer);
    }, [q, who]);

    return (
        <section aria-labelledby="notes-h" className="space-y-2">
            <h2 id="notes-h" className="font-medium">
                {t('Phone orders to enter')}
            </h2>
            <div className="space-y-2 rounded-md border p-3">
                {who ? (
                    <p className="text-sm">
                        <strong>{who.name}</strong>{' '}
                        <button type="button" className="underline" onClick={() => setWho(null)}>
                            {t('Change')}
                        </button>
                    </p>
                ) : (
                    <>
                        <Input
                            aria-label={t('Shop customer')}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('Shop name or phone')}
                            autoComplete="off"
                        />
                        <ul>
                            {found.map((c) => (
                                <li key={c.id}>
                                    <button type="button" className="hover:bg-muted w-full px-2 py-1 text-left text-sm" onClick={() => setWho(c)}>
                                        {c.name} · {c.phone}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                <div className="flex gap-2">
                    <Input
                        aria-label={t('Note')}
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        placeholder={t('What they ordered on the phone')}
                        maxLength={500}
                    />
                    <Button
                        disabled={!who || text.trim() === ''}
                        onClick={() =>
                            router.post(
                                '/admin/counter/notes',
                                { customer_id: who!.id, note: text },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setText('');
                                        setWho(null);
                                        setQ('');
                                    },
                                },
                            )
                        }
                    >
                        {t('Save note')}
                    </Button>
                </div>
                <InputError message={errors.customer_id} />
            </div>
            {notes.length > 0 && (
                <ul className="divide-y rounded-md border">
                    {notes.map((n) => (
                        <li key={n.id} className="flex flex-wrap items-center gap-3 px-4 py-2">
                            <div className="min-w-48 flex-1">
                                <p className="font-medium">{n.customer}</p>
                                <p className="text-muted-foreground text-sm">{n.note}</p>
                            </div>
                            <span className={`tabular text-sm ${n.minutes > 60 ? 'font-semibold text-warning' : 'text-muted-foreground'}`}>
                                {t('{n} min open', { n: num(n.minutes) })}
                            </span>
                            <Button size="sm" onClick={() => router.post(`/admin/counter/notes/${n.id}/draft`)}>
                                {t('Make the draft')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function CounterIndex({ drafts, notes, byStaff, warnings = [] }: { drafts: Draft[]; notes: Note[]; byStaff: { name: string; drafts: number }[]; warnings?: { id: number; memo: string; when: string }[] }) {
    const { t, num, dateTime } = useI18n();
    const offline = useOfflineReady();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Counter')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <Flash />
                {warnings.length > 0 && (
                    <section role="alert" aria-labelledby="warn-h" className="space-y-2 rounded-md border border-warning/50 bg-warning/10 p-3 text-sm">
                        <h2 id="warn-h" className="font-semibold">
                            {t('A warning for you')}
                        </h2>
                        <ul className="space-y-1">
                            {warnings.map((w) => (
                                <li key={w.id}>{t('A return on memo {memo} was counted as your mistake. Please check each sale carefully.', { memo: w.memo })}</li>
                            ))}
                        </ul>
                        <Button size="sm" variant="outline" onClick={() => router.post('/admin/counter-warnings/read', {}, { preserveScroll: true })}>
                            {t('Got it')}
                        </Button>
                    </section>
                )}
                <p className="text-xs text-muted-foreground">
                    {offline.ready ? t('If the internet goes down, sales can go on without it. Prices copied {when}.', { when: dateTime(offline.takenAt!) }) : t('Getting this device ready to sell without internet…')}{' '}
                    <a href="/admin/counter/offline" className="underline underline-offset-4">
                        {t('Counter without internet')}
                    </a>
                </p>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="font-display text-2xl font-extrabold">{t('Counter')}</h1>
                    <div className="flex flex-wrap items-start gap-2">
                        <ReportMismatch />
                        <Button onClick={() => router.post('/admin/counter', { kind: 'retail' })}>
                            <Plus className="size-4" />
                            {t('New retail sale')}
                        </Button>
                    </div>
                </div>

                <section aria-labelledby="open-h" className="space-y-2">
                    <h2 id="open-h" className="font-medium">
                        {t('Open drafts')} ({num(drafts.length)})
                    </h2>
                    {byStaff.length > 1 && (
                        <p className="text-muted-foreground text-xs">
                            {byStaff.map((s) => `${s.name}: ${num(s.drafts)}`).join(' · ')}
                        </p>
                    )}
                    {drafts.length === 0 ? (
                        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">
                            {t('No open drafts. Start a sale to begin.')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-md border">
                            {drafts.map((d) => (
                                <li key={d.id}>
                                    <Link
                                        href={`/admin/counter/${d.id}`}
                                        className="hover:bg-muted/50 flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3"
                                    >
                                        <div className="min-w-40 flex-1">
                                            <p className="font-medium">
                                                {d.customer ?? (d.label ? t(d.label.replace(/ \d+$/, '')) + d.label.match(/ \d+$/)?.[0] : t('Draft'))}
                                            </p>
                                            <p className="text-muted-foreground text-sm">
                                                {t(d.kind === 'retail' ? 'Retail' : d.kind === 'paikari' ? 'Shop' : 'Wholesale')}
                                                {d.staff && ` · ${d.staff}`}
                                            </p>
                                            {d.same_customer > 1 && <p className="text-xs font-semibold text-warning">{t('This customer has {n} drafts open', { n: num(d.same_customer) })}</p>}
                                        </div>
                                        <p className="tabular text-sm">{t('{n} items', { n: num(d.lines) })}</p>
                                        <p
                                            className={`tabular text-sm ${d.minutes_open > 60 ? 'font-semibold text-warning' : 'text-muted-foreground'}`}
                                        >
                                            {t('{n} min open', { n: num(d.minutes_open) })}
                                        </p>
                                    </Link>
                                    {d.stale && (
                                        <div role="note" className="flex flex-wrap items-center gap-3 bg-warning/10 px-4 py-2 text-sm text-warning">
                                            <p className="flex-1">{t('Nobody has touched this draft for a long time. Its goods are being held.')}</p>
                                            <Button size="sm" variant="outline" onClick={() => window.confirm(t('Put its goods back in stock and close this draft?')) && router.post(`/admin/counter/${d.id}/cancel`, {}, { preserveScroll: true })}>
                                                {t('Return to stock')}
                                            </Button>
                                            <Button size="sm" onClick={() => router.post(`/admin/counter/${d.id}/keep`, {}, { preserveScroll: true })}>
                                                {t('Keep')}
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <Notes notes={notes} />

                <section aria-labelledby="shop-h" className="space-y-2">
                    <h2 id="shop-h" className="font-medium">
                        {t('Sale to a shop')}
                    </h2>
                    <CustomerPicker />
                    <NewShop />
                </section>
            </div>
        </AppLayout>
    );
}
