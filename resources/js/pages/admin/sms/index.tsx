import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Campaign {
    id: number;
    name: string;
    status: 'draft' | 'sending' | 'done' | 'cancelled';
    recipients: number;
    audience: string;
    at: string;
    progress: { queued: number; sent: number; failed: number };
}

interface Props {
    campaigns: Campaign[];
    tiers: { id: number; name: string }[];
    service: { on: boolean; live: boolean };
    limit: { daily: number; today: number };
    maxBody: number;
    footer: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bulk texts', href: '/admin/sms' }];
const TYPES = ['public', 'paikari', 'wholesaler'] as const;

/** Bulk texts: write one message, choose who it is for, see how many people and texts that is, then save it and test it on your own phone. */
export default function Sms({ campaigns, tiers, service, limit, maxBody, footer }: Props) {
    const { t, num, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [name, setName] = useState('');
    const [body, setBody] = useState('');
    const [types, setTypes] = useState<string[]>([]);
    const [tierIds, setTierIds] = useState<number[]>([]);
    const [area, setArea] = useState('');
    const [inactive, setInactive] = useState('');
    const [owes, setOwes] = useState(false);
    const [busy, setBusy] = useState(false);
    const [audience, setAudience] = useState<{ count: number; label: string; segments: number; letters: number } | null>(null);
    const typeLabel: Record<string, string> = { public: t('Public'), paikari: t('Paikari'), wholesaler: t('Wholesale') };
    const status = (s: Campaign['status']) => ({ draft: t('Not sent yet'), sending: t('Going out'), done: t('Sent'), cancelled: t('Stopped') })[s];

    const params = () => {
        const p = new URLSearchParams();
        types.forEach((x) => p.append('types[]', x));
        tierIds.forEach((x) => p.append('tier_ids[]', String(x)));
        if (area) p.set('area', area);
        if (inactive) p.set('inactive_days', inactive);
        if (owes) p.set('owes', '1');

        return p;
    };

    // How many people and how many texts, a moment after the owner stops changing things.
    useEffect(() => {
        const timer = window.setTimeout(async () => {
            const p = params();
            p.set('body', body);
            try {
                const res = await fetch(`/admin/sms/audience?${p.toString()}`, { headers: { Accept: 'application/json' } });
                if (res.ok) setAudience(await res.json());
            } catch {
                setAudience(null);
            }
        }, 400);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [types, tierIds, area, inactive, owes, body]);

    const toggle = <T,>(list: T[], v: T, set: (x: T[]) => void) => set(list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);
    const ready = name.trim().length >= 2 && body.trim().length >= 5 && body.length <= maxBody && (audience?.count ?? 0) > 0;
    const total = (audience?.count ?? 0) * (audience?.segments ?? 1);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Bulk texts')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <Flash />
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Bulk texts')}</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">{t('Write one message for many people, test it on your own phone, then send it. Everyone can stop these texts with the link at the end.')}</p>
                </header>

                {!service.on && (
                    <p role="alert" className="flex items-start gap-2 rounded-xl border border-warning/50 bg-warning/5 p-3 text-sm">
                        <CircleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
                        <span>
                            {t('No text service is switched on, so nothing can be sent for real. Add your service in')}{' '}
                            <Link href="/admin/connections" className="font-semibold underline underline-offset-4">
                                {t('Connections')}
                            </Link>
                            .
                        </span>
                    </p>
                )}
                {service.on && !service.live && (
                    <p role="status" className="flex items-start gap-2 rounded-xl border p-3 text-sm">
                        <CircleAlert aria-hidden className="mt-0.5 size-4 shrink-0" />
                        <span>{t('The text service is in test mode. Messages are only written to the log, nothing is sent or paid for. Switch it to Live in Connections to send for real.')}</span>
                    </p>
                )}

                <form
                    className="space-y-5 rounded-xl border bg-card p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!ready || busy) return;
                        router.post('/admin/sms', { name, body, types, tier_ids: tierIds, area, inactive_days: inactive || null, owes }, { onStart: () => setBusy(true), onFinish: () => setBusy(false) });
                    }}
                >
                    <h2 className="font-display text-xl font-bold">{t('A new campaign')}</h2>
                    <label className="block text-sm font-medium">
                        {t('Name (only you see it)')}
                        <Input className="mt-1" maxLength={80} value={name} onChange={(e) => setName(e.target.value)} />
                    </label>
                    <label className="block text-sm font-medium">
                        {t('The message')}
                        <textarea className="mt-1 min-h-28 w-full rounded-md border bg-background p-3 text-sm" maxLength={maxBody} value={body} onChange={(e) => setBody(e.target.value)} />
                    </label>
                    <p className="text-xs text-muted-foreground">
                        {t('Every message ends with this line, so people can stop:')} <span className="whitespace-pre-line">{footer}</span>
                    </p>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">{t('Who it is for')}</legend>
                        <div className="flex flex-wrap gap-4 text-sm">
                            {TYPES.map((x) => (
                                <label key={x} className="flex items-center gap-2">
                                    <input type="checkbox" className="size-4" checked={types.includes(x)} onChange={() => toggle(types, x, setTypes)} /> {typeLabel[x]}
                                </label>
                            ))}
                            <span className="text-xs text-muted-foreground">{t('Tick none for everyone.')}</span>
                        </div>
                        {tiers.length > 0 && (
                            <div className="flex flex-wrap gap-4 text-sm">
                                {tiers.map((x) => (
                                    <label key={x.id} className="flex items-center gap-2">
                                        <input type="checkbox" className="size-4" checked={tierIds.includes(x.id)} onChange={() => toggle(tierIds, x.id, setTierIds)} /> {t('Tier {name}', { name: x.name })}
                                    </label>
                                ))}
                            </div>
                        )}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <label className="text-sm">
                                {t('Area (a word in it)')}
                                <Input className="mt-1" value={area} maxLength={80} onChange={(e) => setArea(e.target.value)} />
                            </label>
                            <label className="text-sm">
                                {t('Has not ordered for this many days')}
                                <Input className="mt-1" inputMode="numeric" min={7} max={730} value={inactive} onChange={(e) => setInactive(e.target.value.replace(/\D/g, ''))} />
                            </label>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox" className="size-4" checked={owes} onChange={(e) => setOwes(e.target.checked)} /> {t('Only those who owe us money')}
                        </label>
                    </fieldset>

                    <div className="rounded-lg bg-muted/40 p-3" aria-live="polite">
                        <p className="tabular font-display text-4xl font-extrabold">{num(audience?.count ?? 0)}</p>
                        <p className="text-sm text-muted-foreground">
                            {t('people will get it')}
                            {audience && audience.count > 0 && ` · ${t('{n} letters, {s} text for each person, {total} in all', { n: num(audience.letters), s: num(audience.segments), total: num(total) })}`}
                        </p>
                        {limit.daily > 0 && (
                            <p className="text-xs text-muted-foreground">{t('Today {today} of {limit} texts are already used.', { today: num(limit.today), limit: num(limit.daily) })}</p>
                        )}
                    </div>

                    <InputError message={errors.campaign} />
                    <Button type="submit" disabled={!ready || busy} aria-busy={busy}>
                        {busy ? t('Saving…') : t('Save the campaign')}
                    </Button>
                </form>

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Your campaigns')}</h2>
                    {campaigns.length === 0 ? (
                        <p className="rounded-xl border p-6 text-sm text-muted-foreground">{t('No campaign yet. The first one you save shows here.')}</p>
                    ) : (
                        <ul className="divide-y rounded-xl border text-sm">
                            {campaigns.map((c) => (
                                <li key={c.id}>
                                    <Link href={`/admin/sms/${c.id}`} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3 hover:bg-muted focus-visible:bg-muted focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring">
                                        <span className="min-w-40 flex-1">
                                            <span className="block font-semibold">{c.name}</span>
                                            <span className="text-xs text-muted-foreground">{c.audience}</span>
                                        </span>
                                        <span className="tabular">{t('{n} people', { n: num(c.recipients) })}</span>
                                        <span className="font-medium">{status(c.status)}</span>
                                        <span className="text-xs text-muted-foreground">{dateTime(c.at)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
