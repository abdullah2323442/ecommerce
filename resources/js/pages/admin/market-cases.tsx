import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    kind: 'complaint' | 'return';
    reason: string;
    status: string;
    seller_note: string | null;
    our_note: string | null;
    at: string;
    number: string;
    title: string;
    seller: string;
    seller_id: number;
}

interface SellerRow {
    seller_id: number;
    seller: string;
    total: number;
    complaints: number;
    returns: number;
    serious: number;
    waiting: number;
    rank: number;
    proven: boolean;
}

interface Standing {
    seller_id: number;
    seller: string;
    rank: number;
    proven: boolean;
    trust: { trusted: boolean; clean: number; needed: number; limit: number | null };
    missing: number;
    slow: number;
    watch: boolean;
    next: string;
    suspended: boolean;
    withheld: boolean;
    listings: { id: number; title: string }[];
    history: { step: string; reason: string; at: string; lifted: boolean }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Market', href: '/admin/market' },
    { title: 'Complaints', href: '/admin/market/cases' },
];

function StandingCard({ s, steps }: { s: Standing; steps: string[] }) {
    const { t, num, date } = useI18n();
    const [step, setStep] = useState(s.next);
    const [reason, setReason] = useState('');
    const [listing, setListing] = useState('');
    const label = (x: string) => t(x === 'warning' ? 'Warning' : x === 'listing_removed' ? 'Take a listing down' : x === 'suspended' ? 'Switch selling off' : 'Hold payouts');
    const base = `/admin/market/sellers/${s.seller_id}`;

    return (
        <li className="space-y-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {s.seller} {s.watch && <span className="ms-2 rounded bg-danger/15 px-1.5 py-0.5 text-xs font-semibold text-danger">{t('Look at this seller')}</span>}
                </p>
                <p className="tabular text-xs text-muted-foreground">
                    {t('Rank')}: {s.proven ? num(s.rank) : t('New')} · {t('Not received')}: {num(s.missing)} · {t('Questions left too long')}: {num(s.slow)}
                </p>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="tabular">{s.trust.trusted ? t('Trusted') : t('Clean orders {n} of {m}', { n: num(s.trust.clean), m: num(s.trust.needed) })}</span>
                <Button size="sm" variant="outline" onClick={() => router.put(`${base}/trust`, { trusted: !s.trust.trusted }, { preserveScroll: true })}>
                    {s.trust.trusted ? t('Take trust back') : t('Trust now')}
                </Button>
                {s.suspended && <span className="rounded bg-danger/15 px-1.5 py-0.5 font-semibold text-danger">{t('Selling is switched off')}</span>}
                {s.withheld && <span className="rounded bg-warning/15 px-1.5 py-0.5 font-semibold text-warning">{t('Payouts on hold')}</span>}
                {(s.suspended || s.withheld) && (
                    <Button size="sm" variant="outline" onClick={() => router.put(`${base}/penalty/lift`, {}, { preserveScroll: true })}>
                        {t('Lift it')}
                    </Button>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <select aria-label={t('Step')} value={step} onChange={(e) => setStep(e.target.value)} className="h-8 rounded-md border bg-background px-2 text-sm">
                    {steps.map((x) => (
                        <option key={x} value={x}>
                            {label(x)}
                        </option>
                    ))}
                </select>
                {step === 'listing_removed' && (
                    <select aria-label={t('Listing')} value={listing} onChange={(e) => setListing(e.target.value)} className="h-8 max-w-48 rounded-md border bg-background px-2 text-sm">
                        <option value="">{t('Which listing?')}</option>
                        {s.listings.map((l) => (
                            <option key={l.id} value={l.id}>
                                {l.title}
                            </option>
                        ))}
                    </select>
                )}
                <Input aria-label={t('Reason')} className="h-8 w-64" placeholder={t('The reason (the seller is told)')} value={reason} onChange={(e) => setReason(e.target.value)} />
                <Button size="sm" variant="destructive" disabled={reason.trim().length < 3 || (step === 'listing_removed' && listing === '')} onClick={() => router.post(`${base}/penalty`, { step, reason, listing_id: listing || null }, { preserveScroll: true, onSuccess: () => setReason('') })}>
                    {t('Do it')}
                </Button>
            </div>
            {s.history.length > 0 && (
                <ul className="text-xs text-muted-foreground">
                    {s.history.map((h, i) => (
                        <li key={i}>
                            {date(h.at)} · {label(h.step)} · {h.reason}
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

function Card({ r, decide }: { r: Row; decide?: boolean }) {
    const { t, dateTime } = useI18n();
    const [note, setNote] = useState('');
    const go = (decision: 'return' | 'close') => router.post(`/admin/market/cases/${r.id}/decide`, { decision, note }, { preserveScroll: true });

    return (
        <li className="space-y-1 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {r.seller} · {r.title} · {r.number}
                </p>
                <span className="text-xs text-muted-foreground">
                    {r.kind === 'return' ? t('Return') : t('Complaint')} · {dateTime(r.at)}
                </span>
            </div>
            <p className="text-sm">{r.reason}</p>
            {r.seller_note && <p className="text-sm text-muted-foreground">{t('The seller')}: {r.seller_note}</p>}
            {r.our_note && <p className="text-sm">{t('Our note')}: {r.our_note}</p>}
            {decide && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('Our note')} className="h-8 w-72" placeholder={t('What we decided, and why')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button size="sm" disabled={note.trim().length < 3} onClick={() => go('return')}>
                        {t('Send it back to the seller')}
                    </Button>
                    <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => go('close')}>
                        {t('Close the case')}
                    </Button>
                </div>
            )}
        </li>
    );
}

export default function MarketCases({ serious, open, settled, sellers, standing, steps, returnDays }: { serious: Row[]; open: Row[]; settled: Row[]; sellers: SellerRow[]; standing: Standing[]; steps: string[]; returnDays: number }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const section = (title: string, items: Row[], empty: string, decide?: boolean) => (
        <section className="space-y-2">
            <h2 className="font-medium">{title}</h2>
            {items.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{empty}</p> : <ul className="divide-y rounded-md border bg-card">{items.map((r) => <Card key={r.id} r={r} decide={decide} />)}</ul>}
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Market complaints')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Market complaints')}</h1>
                    <p className="text-sm text-muted-foreground">{t('The seller sees every complaint and solves it. We get a copy. What they cannot solve, or leave unanswered, comes here. A buyer may send an order back within {n} days.', { n: num(returnDays) })}</p>
                </div>
                <Flash />
                <InputError message={errors.case ?? errors.penalty} />
                {section(t('Serious: brought to us'), serious, t('Nothing brought to us.'), true)}
                {section(t('With the seller'), open, t('Nothing waiting for a seller.'), true)}
                <section className="space-y-2">
                    <h2 className="font-medium">{t('Against each seller')}</h2>
                    {sellers.length === 0 ? (
                        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No complaints yet.')}</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="py-1 pe-2 font-medium">{t('Seller')}</th>
                                    <th className="px-2 font-medium">{t('Complaints')}</th>
                                    <th className="px-2 font-medium">{t('Returns')}</th>
                                    <th className="px-2 font-medium">{t('Serious')}</th>
                                    <th className="px-2 font-medium">{t('Waiting')}</th>
                                    <th className="px-2 font-medium">{t('Rank')}</th>
                                </tr>
                            </thead>
                            <tbody className="tabular">
                                {sellers.map((s) => (
                                    <tr key={s.seller_id} className="border-b">
                                        <td className="py-1 pe-2">{s.seller}</td>
                                        <td className="px-2">{num(s.complaints)}</td>
                                        <td className="px-2">{num(s.returns)}</td>
                                        <td className="px-2 font-semibold">{num(s.serious)}</td>
                                        <td className="px-2">{num(s.waiting)}</td>
                                        <td className="px-2">{s.proven ? num(s.rank) : t('New')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
                <section className="space-y-2">
                    <h2 className="font-medium">{t('Sellers: trust and steps')}</h2>
                    <p className="text-xs text-muted-foreground">{t('A warning, then a listing taken down, then selling switched off, then payouts held. You choose each step. The seller is told why.')}</p>
                    {standing.length === 0 ? (
                        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No sellers yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card">
                            {standing.map((s) => (
                                <StandingCard key={s.seller_id} s={s} steps={steps} />
                            ))}
                        </ul>
                    )}
                </section>
                {section(t('Settled'), settled, t('Nothing settled yet.'))}
            </div>
        </AppLayout>
    );
}
