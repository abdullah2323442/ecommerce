import SellerBrand from '@/components/seller-brand';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Statement {
    id: number;
    month: string;
    orders: number;
    sales: number;
    commission: number;
    status: 'sent' | 'paid' | 'problem' | 'verified' | 'suspended';
    seller_note: string | null;
    our_note: string | null;
    sent_at: string;
}

function Card({ s }: { s: Statement }) {
    const { t, money, num, date } = useI18n();
    const [problem, setProblem] = useState(false);
    const [note, setNote] = useState('');
    const open = s.status === 'sent' || s.status === 'suspended' || s.status === 'problem';
    const label = (x: Statement['status']) => t(x === 'sent' ? 'Waiting for your answer' : x === 'paid' ? 'You said it is paid. We will check.' : x === 'problem' ? 'You told us there is a problem. We will look at it.' : x === 'verified' ? 'Checked. Thank you.' : 'Not answered in time');

    return (
        <li className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-display text-xl font-bold">{date(`${s.month}-15T12:00:00`, { month: 'long', year: 'numeric' })}</p>
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">{label(s.status)}</span>
            </div>
            <p className="tabular text-sm">
                {t('Sales were {sales} from {n} orders. Our commission is {commission}.', { sales: money(s.sales), n: num(s.orders), commission: money(s.commission) })}
            </p>
            {s.our_note && <p className="rounded-md bg-amber-50 p-2 text-sm">{s.our_note}</p>}
            {s.seller_note && <p className="text-sm text-[var(--pt-muted)]">{s.seller_note}</p>}
            {(s.status === 'sent' || s.status === 'suspended') && (
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" onClick={() => router.post(`/wholesale/sell/statements/${s.id}/reply`, { answer: 'paid' }, { preserveScroll: true })}>
                        {t('Paid')}
                    </Button>
                    {problem ? (
                        <>
                            <Input aria-label={t('What is the problem?')} className="h-8 w-64" placeholder={t('What is the problem?')} value={note} onChange={(e) => setNote(e.target.value)} />
                            <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => router.post(`/wholesale/sell/statements/${s.id}/reply`, { answer: 'problem', note }, { preserveScroll: true })}>
                                {t('Send')}
                            </Button>
                        </>
                    ) : (
                        <Button size="sm" variant="outline" onClick={() => setProblem(true)}>
                            {t('Problem')}
                        </Button>
                    )}
                </div>
            )}
            {open && s.status === 'problem' && <p className="text-xs text-[var(--pt-muted)]">{t('We will answer you here.')}</p>}
        </li>
    );
}

export default function Statements({ statements, stopped, replyDays, penalties }: { statements: Statement[]; stopped: boolean; replyDays: number; penalties: { step: string; reason: string; at: string }[] }) {
    const { t, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    return (
        <PublicLayout>
            <Head title={t('Commission statements')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <SellerBrand />
                    <h1 className="font-display text-3xl font-extrabold">{t('Commission statements')}</h1>
                    <Link href="/wholesale/sell" className="text-sm font-semibold underline underline-offset-4">
                        {t('Sell')}
                    </Link>
                </div>
                <p className="text-sm text-[var(--pt-muted)]">{t('Buyers pay you directly. At the end of the month we send what our commission was. Answer Paid, or Problem with the reason. If there is no answer in {n} days, selling is switched off until you answer.', { n: num(replyDays) })}</p>
                {stopped && <p role="alert" className="rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm font-semibold">{t('Selling is switched off. Answer the statement below and it comes back.')}</p>}
                {penalties.map((p, i) => (
                    <p key={i} role="alert" className="rounded-2xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm font-semibold">
                        {p.step === 'withheld' ? t('Your payouts are on hold: {note}', { note: p.reason }) : t('Your selling is switched off: {note}', { note: p.reason })}
                    </p>
                ))}
                {flash && <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">{flash}</p>}
                <InputError message={errors.statement ?? errors.answer} />
                {statements.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('No statement yet.')}</p>
                ) : (
                    <ul className="space-y-3">
                        {statements.map((s) => (
                            <Card key={s.id} s={s} />
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
