import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Quote {
    id: number;
    request: string;
    quantity: string | null;
    status: 'requested' | 'quoted' | 'accepted' | 'declined' | 'cancelled';
    total: number | null;
    note: string | null;
    valid_until: string | null;
    can_accept: boolean;
    expired: boolean;
    at: string;
}

/** A bulk buyer asks for a price on something, and answers ours (spec ch 14: custom quotation). */
export default function Quotes({ quotes }: { quotes: Quote[] }) {
    const { t, money, date, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [what, setWhat] = useState('');
    const [quantity, setQuantity] = useState('');
    const state = (q: Quote) => t(q.expired ? 'The price has run out' : q.status === 'requested' ? 'Waiting for our price' : q.status === 'quoted' ? 'We have a price' : q.status === 'accepted' ? 'You accepted' : q.status === 'declined' ? 'You declined' : 'Cancelled');

    return (
        <PublicLayout>
            <Head title={t('Ask for a price')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Ask for a price')}</h1>
                    <Link href="/wholesale" className="text-sm font-semibold underline underline-offset-4">
                        {t('Order')}
                    </Link>
                </div>
                <p className="text-sm text-[var(--pt-muted)]">{t('Tell us what you want and how much. We answer with one price and the last day it holds.')}</p>
                {flash && <p role="status" className="rounded-md border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">{flash}</p>}
                <InputError message={errors.quote} />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post('/wholesale/quotes', { request: what, quantity }, { preserveScroll: true, onSuccess: () => (setWhat(''), setQuantity('')) });
                    }}
                    className="space-y-3 rounded-2xl border bg-[var(--pt-surface)] p-4"
                >
                    <label className="block text-sm font-medium">
                        {t('What do you want?')}
                        <Input className="mt-1" value={what} maxLength={500} onChange={(e) => setWhat(e.target.value)} />
                    </label>
                    <InputError message={errors.request} />
                    <label className="block text-sm font-medium">
                        {t('How much?')}
                        <Input className="mt-1" value={quantity} maxLength={60} placeholder={t('For example: 200 boxes')} onChange={(e) => setQuantity(e.target.value)} />
                    </label>
                    <Button type="submit" disabled={what.trim().length < 3}>
                        {t('Ask')}
                    </Button>
                </form>

                {quotes.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('Nothing asked yet.')}</p>
                ) : (
                    <ul className="space-y-3">
                        {quotes.map((q) => (
                            <li key={q.id} className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-semibold">
                                        {q.request}
                                        {q.quantity && ` · ${q.quantity}`}
                                    </p>
                                    <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold">{state(q)}</span>
                                </div>
                                <p className="text-xs text-[var(--pt-muted)]">{dateTime(q.at)}</p>
                                {q.total !== null && (
                                    <p className="tabular">
                                        {t('Our price')}: <strong className="font-display text-xl">{money(q.total)}</strong>
                                        {q.valid_until && <span className="text-[var(--pt-muted)]"> · {t('holds until {date}', { date: date(q.valid_until) })}</span>}
                                    </p>
                                )}
                                {q.note && <p>{q.note}</p>}
                                <div className="flex flex-wrap gap-2">
                                    {q.can_accept && (
                                        <>
                                            <Button size="sm" onClick={() => router.post(`/wholesale/quotes/${q.id}/respond`, { answer: 'accept' }, { preserveScroll: true })}>
                                                {t('Accept')}
                                            </Button>
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/wholesale/quotes/${q.id}/respond`, { answer: 'decline' }, { preserveScroll: true })}>
                                                {t('Decline')}
                                            </Button>
                                        </>
                                    )}
                                    {(q.status === 'requested' || q.status === 'quoted') && (
                                        <Button size="sm" variant="ghost" onClick={() => router.post(`/wholesale/quotes/${q.id}/cancel`, {}, { preserveScroll: true })}>
                                            {t('Cancel')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
