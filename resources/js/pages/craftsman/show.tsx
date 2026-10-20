import CustomChat, { type ChatData } from '@/components/custom-chat';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Item {
    label: string;
    garment?: string;
    quantity: number;
    unit: string;
    values: Record<string, number>;
}

interface Job {
    id: number;
    number: string;
    kind: 'wood' | 'clothing';
    status: string;
    material: string | null;
    fabric_by: string | null;
    items: Item[];
    details: Record<string, string | boolean> | null;
    note: string | null;
    urgent: boolean;
    has_photo: boolean;
    due_on: string | null;
    days: number | null;
    is_main: boolean;
    done: number;
    waiting: string | null;
    wage_asked: boolean;
    wage_proposed: number | null;
    wage_total: number | null;
    my_wage: number;
    team: { id: number; name: string; role: string }[];
    entries: { id: number; who: string; percent: number; note: string | null; at: string }[];
    moves: { kind: string; from: string | null; to: string | null; at: string }[];
}

const label = (k: string) => k.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

/** One job for a craftsman. Measurements and description, photos, the time, their own wage. Never the price. */
export default function CraftsmanJob({ job, others, chat }: { job: Job; others: { id: number; name: string }[]; chat: ChatData | null }) {
    const { t, money, num, date, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [wage, setWage] = useState(job.wage_proposed !== null ? String(job.wage_proposed) : '');
    const [wageNote, setWageNote] = useState('');
    const [who, setWho] = useState(String(job.team[0]?.id ?? ''));
    const [percent, setPercent] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const [note, setNote] = useState('');
    const [other, setOther] = useState(String(others[0]?.id ?? ''));
    const [need, setNeed] = useState('');
    const base = `/craftsman/jobs/${job.id}`;
    const working = job.status === 'making' || job.status === 'trial';

    return (
        <PublicLayout>
            <Head title={job.number} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href="/craftsman" className="text-sm underline underline-offset-4">
                    {t('Your jobs')}
                </Link>
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h1 className="font-display text-3xl font-extrabold">
                        {job.number} · {job.kind === 'wood' ? t('Wood') : t('Clothing')}
                    </h1>
                    {job.urgent && <span className="rounded bg-danger/15 px-2 py-1 text-sm font-semibold text-danger">{t('Urgent')}</span>}
                </div>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <InputError message={errors.job} />

                <section className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                    {job.material && (
                        <p>
                            {job.kind === 'wood' ? t('Wood') : t('Fabric')}: <strong>{job.material}</strong>
                        </p>
                    )}
                    {job.fabric_by === 'buyer' && <p>{t('The buyer brings the fabric.')}</p>}
                    {job.items.map((it, i) => (
                        <div key={i} className="rounded-xl bg-muted/50 p-3">
                            <p className="font-semibold">
                                {it.label || (job.kind === 'wood' ? t('Size') : t('Person {n}', { n: num(i + 1) }))}
                                {it.garment && ` · ${it.garment} × ${num(it.quantity)}`}
                            </p>
                            <p className="tabular">
                                {Object.entries(it.values).map(([k, v]) => (
                                    <span key={k} className="me-3 inline-block">
                                        {t(label(k))}: <strong>{num(v)}</strong>
                                    </span>
                                ))}
                                ({t(it.unit === 'inch' ? 'Inch' : it.unit === 'foot' ? 'Foot' : 'Centimetre')})
                            </p>
                        </div>
                    ))}
                    {job.details && Object.keys(job.details).length > 0 && (
                        <p>
                            {Object.entries(job.details).map(([k, v]) => (
                                <span key={k} className="me-3 inline-block">
                                    {t(label(k))}
                                    {typeof v === 'boolean' ? '' : `: ${v}`}
                                </span>
                            ))}
                        </p>
                    )}
                    {job.note && <p>{job.note}</p>}
                    {job.has_photo && <img src={`${base}/photo`} alt="" className="max-h-64 rounded-xl border object-cover" />}
                    {job.due_on && (
                        <p className="font-semibold">
                            {t('Due on {date}.', { date: date(job.due_on) })}
                        </p>
                    )}
                </section>

                {job.wage_asked && (
                    <section className="space-y-2 rounded-2xl border border-[var(--pt-ink)] p-4">
                        <p className="font-semibold">{t('Look at the job and tell us what the wage should be.')}</p>
                        <div className="flex flex-wrap items-center gap-2">
                            <Input aria-label={t('Wage')} className="h-9 w-32" inputMode="decimal" placeholder={t('Wage')} value={wage} onChange={(e) => setWage(e.target.value)} />
                            <Input aria-label={t('Note')} className="h-9 w-64" placeholder={t('Note (optional)')} value={wageNote} onChange={(e) => setWageNote(e.target.value)} />
                            <Button disabled={Number(wage) <= 0} onClick={() => router.post(`${base}/wage`, { amount: wage, note: wageNote }, { preserveScroll: true })}>
                                {t('Send')}
                            </Button>
                        </div>
                        {job.wage_proposed !== null && <p className="text-sm text-[var(--pt-muted)]">{t('You said {amount}. We will confirm.', { amount: money(job.wage_proposed) })}</p>}
                    </section>
                )}

                {!job.wage_asked && (
                    <section className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 className="font-display text-xl font-bold">{t('How far')}</h2>
                            <p className="tabular font-display text-2xl font-extrabold">{num(job.done)}%</p>
                        </div>
                        <div className="h-3 rounded-full bg-muted">
                            <div className="h-3 rounded-full bg-[var(--pt-ink)]" style={{ width: `${Math.min(100, job.done)}%` }} />
                        </div>
                        <p className="text-sm">
                            {job.wage_total !== null && (
                                <>
                                    {t('The wage for the whole job')}: <strong className="tabular">{money(job.wage_total)}</strong> ·{' '}
                                </>
                            )}
                            {t('Your wage so far')}: <strong className="tabular">{money(job.my_wage)}</strong>
                        </p>
                        {job.waiting && <p className="rounded-md bg-warning/10 p-2 text-sm text-warning">{t('Waiting for materials')}: {job.waiting}</p>}

                        {job.is_main && working && (
                            <div className="space-y-2 rounded-xl border p-3">
                                <p className="text-sm font-semibold">{t('Enter what was done. A photo is needed each time.')}</p>
                                <div className="flex flex-wrap items-center gap-2">
                                    <select aria-label={t('Who did it')} value={who} onChange={(e) => setWho(e.target.value)} className="h-9 rounded-md border bg-background px-2 text-sm">
                                        {job.team.map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {m.name}
                                            </option>
                                        ))}
                                    </select>
                                    <Input aria-label={t('Percent')} className="h-9 w-24" inputMode="decimal" placeholder="%" value={percent} onChange={(e) => setPercent(e.target.value)} />
                                    <Input aria-label={t('Note')} className="h-9 w-48" placeholder={t('Note (optional)')} value={note} onChange={(e) => setNote(e.target.value)} />
                                    <input type="file" accept="image/*" aria-label={t('Photo of the work')} onChange={(e) => setPhoto(e.target.files?.[0] ?? null)} className="text-sm" />
                                    <Button disabled={Number(percent) <= 0 || !photo} onClick={() => router.post(`${base}/progress`, { craftsman_id: who, percent, note, photo }, { forceFormData: true, preserveScroll: true, onSuccess: () => (setPercent(''), setNote(''), setPhoto(null)) })}>
                                        {t('Save')}
                                    </Button>
                                </div>
                                <InputError message={errors.photo ?? errors.percent} />
                            </div>
                        )}

                        {working && (
                            <div className="flex flex-wrap items-center gap-2">
                                <Input aria-label={t('What is needed')} className="h-9 w-64" placeholder={t('Materials needed: what?')} value={need} onChange={(e) => setNeed(e.target.value)} />
                                <Button variant="outline" disabled={need.trim().length < 3} onClick={() => router.post(`${base}/waiting`, { note: need }, { preserveScroll: true, onSuccess: () => setNeed('') })}>
                                    {t('Send: materials needed')}
                                </Button>
                            </div>
                        )}

                        {job.is_main && others.length > 0 && (
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <select aria-label={t('Another person')} value={other} onChange={(e) => setOther(e.target.value)} className="h-9 rounded-md border bg-background px-2">
                                    {others.map((o) => (
                                        <option key={o.id} value={o.id}>
                                            {o.name}
                                        </option>
                                    ))}
                                </select>
                                <Button size="sm" variant="outline" onClick={() => router.post(`${base}/share`, { to: other }, { preserveScroll: true })}>
                                    {t('Take them on as a share')}
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => window.confirm(t('Hand this job to them? It will no longer be yours.')) && router.post(`${base}/shift`, { to: other })}>
                                    {t('Hand the job on')}
                                </Button>
                            </div>
                        )}
                    </section>
                )}

                {chat && <CustomChat chat={chat} postUrl={`${base}/chat`} fileUrl={(id) => `${base}/chat/${id}/file`} />}

                {job.team.length > 0 && !job.wage_asked && (
                    <p className="text-sm text-[var(--pt-muted)]">
                        {t('On this job')}: {job.team.map((m) => m.name).join(', ')}
                    </p>
                )}

                {job.entries.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="font-display text-xl font-bold">{t('What was entered')}</h2>
                        <ul className="space-y-2 text-sm">
                            {job.entries.map((e) => (
                                <li key={e.id} className="flex flex-wrap items-center gap-3">
                                    <a href={`/craftsman/progress/${e.id}/photo`} target="_blank" rel="noreferrer">
                                        <img src={`/craftsman/progress/${e.id}/photo`} alt="" className="h-12 w-12 rounded-md border object-cover" />
                                    </a>
                                    <span className="tabular font-semibold">{num(e.percent)}%</span>
                                    <span>{e.who}</span>
                                    <span className="text-[var(--pt-muted)]">{dateTime(e.at)}</span>
                                    {e.note && <span>{e.note}</span>}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {job.moves.length > 0 && (
                    <ul className="text-xs text-[var(--pt-muted)]">
                        {job.moves.map((m, i) => (
                            <li key={i}>
                                {dateTime(m.at)} · {m.kind === 'assign' ? t('Given to {name}', { name: m.to ?? '' }) : m.kind === 'shift' ? t('{from} gave it to {to}', { from: m.from ?? '', to: m.to ?? '' }) : t('{to} was taken on', { to: m.to ?? '' })}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
