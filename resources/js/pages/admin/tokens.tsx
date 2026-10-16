import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    q: string;
    found: { id: number; name: string; phone: string | null }[];
    recent: { id: number; who: string; phone: string | null; amount: number; note: string | null; status: 'ready' | 'used' | 'expired' | 'taken_back'; expires_at: string | null; at: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Aqua Tokens', href: '/admin/tokens' }];

export default function Tokens({ q, found, recent }: Props) {
    const { t, money, date, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [search, setSearch] = useState(q);
    const [amount, setAmount] = useState('100');
    const [note, setNote] = useState('');
    const [ends, setEnds] = useState('');
    const state = (s: Props['recent'][number]['status']) => t(s === 'ready' ? 'Ready to use' : s === 'used' ? 'Used' : s === 'expired' ? 'Ended' : 'Taken back');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Aqua Tokens')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <Flash />
                <div>
                    <h1 className="text-2xl font-bold">{t('Aqua Tokens')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Only we give these. Each one works in every sector, once, and comes back if the order is cancelled. Find the person, then give.')}</p>
                </div>

                <form className="flex gap-2" onSubmit={(e) => (e.preventDefault(), router.get('/admin/tokens', { q: search }, { preserveState: true }))}>
                    <Input aria-label={t('Find a customer')} placeholder={t('Name or phone')} value={search} onChange={(e) => setSearch(e.target.value)} />
                    <Button type="submit" variant="outline">
                        {t('Find')}
                    </Button>
                </form>

                {found.length > 0 && (
                    <section className="space-y-2 rounded-lg border p-4">
                        <div className="grid gap-2 sm:grid-cols-3">
                            <label className="text-sm font-medium">
                                {t('Amount')}
                                <Input className="mt-1" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
                            </label>
                            <label className="text-sm font-medium">
                                {t('Why (optional)')}
                                <Input className="mt-1" value={note} maxLength={160} onChange={(e) => setNote(e.target.value)} />
                            </label>
                            <label className="text-sm font-medium">
                                {t('Last day (optional)')}
                                <Input className="mt-1" type="date" value={ends} onChange={(e) => setEnds(e.target.value)} />
                            </label>
                        </div>
                        <InputError message={errors.amount ?? errors.expires_at} />
                        <ul className="divide-y text-sm">
                            {found.map((u) => (
                                <li key={u.id} className="flex items-center justify-between gap-2 py-2">
                                    <span>
                                        {u.name} {u.phone && <span className="text-muted-foreground">· {u.phone}</span>}
                                    </span>
                                    <Button size="sm" disabled={amount.trim() === ''} onClick={() => router.post('/admin/tokens', { user_id: u.id, amount, note, expires_at: ends || null }, { preserveScroll: true })}>
                                        {t('Give a token')}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {q !== '' && found.length === 0 && <p className="text-sm text-muted-foreground">{t('Nobody found.')}</p>}

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">{t('Given lately')}</h2>
                    {recent.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('Nothing given yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {recent.map((r) => (
                                <li key={r.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2">
                                    <span className="min-w-40 flex-1">
                                        {r.who} {r.phone && <span className="text-muted-foreground">· {r.phone}</span>}
                                        {r.note && <span className="block text-xs text-muted-foreground">{r.note}</span>}
                                    </span>
                                    <span className="text-xs text-muted-foreground">{r.expires_at ? t('until {date}', { date: date(r.expires_at) }) : dateTime(r.at)}</span>
                                    <span className="text-xs font-semibold">{state(r.status)}</span>
                                    <span className="tabular w-20 text-right">{money(r.amount)}</span>
                                    {r.status === 'ready' && (
                                        <Button size="sm" variant="outline" onClick={() => window.confirm(t('Take this token back?')) && router.post(`/admin/tokens/${r.id}/take-back`, {}, { preserveScroll: true })}>
                                            {t('Take back')}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
