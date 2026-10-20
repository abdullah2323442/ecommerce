import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Craftsman {
    id: number;
    name: string;
    phone: string | null;
    closed: boolean;
    active_jobs: number;
    ledger: { earned: number; paid: number; owed: number; month: number; delays: number };
    payments: { kind: string; amount: number; note: string | null; on: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Craftsmen', href: '/admin/craftsmen' }];

function Row({ c, canPay }: { c: Craftsman; canPay: boolean }) {
    const { t, money, num, date } = useI18n();
    const [amount, setAmount] = useState('');
    const [note, setNote] = useState('');

    return (
        <li className="space-y-2 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">
                    {c.name} {c.phone && <span className="text-xs text-muted-foreground">{c.phone}</span>}
                    {c.closed && <span className="ms-2 rounded bg-danger/15 px-1.5 py-0.5 text-xs font-semibold text-danger">{t('Closed')}</span>}
                </p>
                <p className="tabular text-xs">
                    {t('Active jobs')}: <strong>{num(c.active_jobs)}</strong> · {t('Late jobs')}: {num(c.ledger.delays)}
                </p>
            </div>
            <dl className="tabular grid grid-cols-2 gap-2 sm:grid-cols-4">
                {(
                    [
                        ['Earned this month', c.ledger.month],
                        ['Earned in all', c.ledger.earned],
                        ['Paid', c.ledger.paid],
                        ['Owed', c.ledger.owed],
                    ] as const
                ).map(([label, v]) => (
                    <div key={label} className={label === 'Owed' && v > 0 ? 'rounded-md bg-warning/10 p-1' : ''}>
                        <dt className="text-xs text-muted-foreground">{t(label)}</dt>
                        <dd className="font-semibold">{money(v)}</dd>
                    </div>
                ))}
            </dl>
            {c.payments.length > 0 && (
                <ul className="text-xs text-muted-foreground">
                    {c.payments.map((p, i) => (
                        <li key={i}>
                            {date(p.on)} · {p.kind === 'advance' ? t('Advance') : t('Wages')} · {money(p.amount)} {p.note && `· ${p.note}`}
                        </li>
                    ))}
                </ul>
            )}
            {canPay && (
                <div className="flex flex-wrap items-center gap-2">
                    <Input aria-label={t('Amount')} className="h-8 w-28" inputMode="decimal" placeholder={t('Amount')} value={amount} onChange={(e) => setAmount(e.target.value)} />
                    <Input aria-label={t('Note')} className="h-8 w-48" placeholder={t('Note (optional)')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button size="sm" variant="outline" disabled={Number(amount) <= 0} onClick={() => router.post(`/admin/craftsmen/${c.id}/pay`, { amount, note }, { preserveScroll: true, onSuccess: () => (setAmount(''), setNote('')) })}>
                        {t('Paid wages')}
                    </Button>
                </div>
            )}
        </li>
    );
}

export default function Craftsmen({ craftsmen, month, canOpen }: { craftsmen: Craftsman[]; month: string; canOpen: boolean }) {
    const { t, money } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({ name: '', phone: '', password: '' });
    const owed = craftsmen.reduce((sum, c) => sum + Math.max(0, c.ledger.owed), 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Craftsmen')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('Craftsmen')}</h1>
                        <p className="text-sm text-muted-foreground">{t('Our own people. They see only their own jobs and wages. Check how many jobs one has before you give another.')}</p>
                    </div>
                    <label className="text-sm">
                        {t('Month')}
                        <input type="month" value={month} onChange={(e) => router.get('/admin/craftsmen', { month: e.target.value }, { preserveState: true })} className="ms-2 h-9 rounded-md border bg-background px-2" />
                    </label>
                </div>
                <Flash />
                <InputError message={errors.craftsman ?? errors.name ?? errors.password ?? errors.phone} />
                <p className="tabular text-sm">
                    {t('Owed to all craftsmen')}: <strong>{money(owed)}</strong>
                </p>

                {craftsmen.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No craftsmen yet.')}</p> : <ul className="divide-y rounded-md border bg-card">{craftsmen.map((c) => <Row key={c.id} c={c} canPay={canOpen} />)}</ul>}

                {canOpen && (
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('Open an account')}</h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <Input aria-label={t('Name')} className="h-8 w-48" placeholder={t('Name')} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} />
                            <Input aria-label={t('Phone')} className="h-8 w-40" placeholder={t('Phone')} value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
                            <Input aria-label={t('Password')} type="password" className="h-8 w-48" placeholder={t('Password (10 or more)')} value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
                            <Button size="sm" disabled={f.name.trim() === '' || f.password.length < 10} onClick={() => router.post('/admin/craftsmen', f, { preserveScroll: true, onSuccess: () => setF({ name: '', phone: '', password: '' }) })}>
                                {t('Open')}
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
