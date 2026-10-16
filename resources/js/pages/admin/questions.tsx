import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Question {
    id: number;
    medicine: { id: number; name: string };
    asker: string | null;
    question: string;
    answer: string | null;
    at: string;
    answered_at: string | null;
    hidden: boolean;
}

interface FromSeller {
    id: number;
    listing: { id: number; name: string };
    seller: string;
    asker: string | null;
    question: string;
    answer: string | null;
    at: string;
}

function SellerCard({ q }: { q: FromSeller }) {
    const { t, dateTime } = useI18n();
    const [text, setText] = useState('');
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <li className="space-y-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Link href={`/market/${q.listing.id}`} className="font-medium hover:underline">
                    {q.listing.name}
                </Link>
                <span className="text-xs text-muted-foreground">
                    {q.seller} · {q.asker} · {dateTime(q.at)}
                </span>
            </div>
            <p className="text-sm">{q.question}</p>
            <textarea aria-label={t('Answer')} rows={2} maxLength={1500} value={text} onChange={(e) => setText(e.target.value)} className="w-full rounded-md border bg-background p-2 text-sm" placeholder={t('Write the answer')} />
            <InputError message={errors.answer} />
            <div className="flex flex-wrap gap-2">
                <Button size="sm" disabled={text.trim().length < 2} onClick={() => router.put(`/admin/seller-questions/${q.id}/answer`, { answer: text }, { preserveScroll: true })}>
                    {t('Answer')}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => router.put(`/admin/seller-questions/${q.id}/hide`, { hidden: true }, { preserveScroll: true })}>
                    {t('Hide')}
                </Button>
            </div>
        </li>
    );
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Questions', href: '/admin/questions' }];

function Card({ q }: { q: Question }) {
    const { t, dateTime } = useI18n();
    const [text, setText] = useState(q.answer ?? '');
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <li className={`space-y-2 px-4 py-3 ${q.hidden ? 'opacity-60' : ''}`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <Link href={`/medicines/${q.medicine.id}`} className="font-medium hover:underline">
                    {q.medicine.name}
                </Link>
                <span className="text-xs text-muted-foreground">
                    {q.asker} · {dateTime(q.at)}
                </span>
            </div>
            <p className="text-sm">{q.question}</p>
            <textarea aria-label={t('Answer')} rows={2} maxLength={1500} value={text} onChange={(e) => setText(e.target.value)} className="w-full rounded-md border bg-background p-2 text-sm" placeholder={t('Write the answer')} />
            <InputError message={errors.answer} />
            <div className="flex flex-wrap gap-2">
                <Button size="sm" disabled={text.trim().length < 2} onClick={() => router.put(`/admin/questions/${q.id}/answer`, { answer: text }, { preserveScroll: true })}>
                    {q.answered_at ? t('Save the answer') : t('Answer')}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => router.put(`/admin/questions/${q.id}/hide`, { hidden: !q.hidden }, { preserveScroll: true })}>
                    {q.hidden ? t('Show again') : t('Hide')}
                </Button>
            </div>
        </li>
    );
}

export default function Questions({ waiting, answered, fromSellers }: { waiting: Question[]; answered: Question[]; fromSellers: FromSeller[] }) {
    const { t } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Questions')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Questions')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Buyers ask under a product. Everyone can read the answer. A hidden question is not shown.')}</p>
                </div>
                <Flash />

                {fromSellers.length > 0 && (
                    <section aria-labelledby="late-h" className="space-y-2">
                        <h2 id="late-h" className="font-medium">
                            {t('A seller left these too long')}
                        </h2>
                        <ul className="divide-y rounded-md border border-amber-500/50 bg-card">
                            {fromSellers.map((q) => (
                                <SellerCard key={q.id} q={q} />
                            ))}
                        </ul>
                    </section>
                )}

                <section aria-labelledby="wait-h" className="space-y-2">
                    <h2 id="wait-h" className="font-medium">
                        {t('Waiting for an answer')}
                    </h2>
                    {waiting.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No question is waiting.')}</p> : <ul className="divide-y rounded-md border bg-card">{waiting.map((q) => <Card key={q.id} q={q} />)}</ul>}
                </section>

                {answered.length > 0 && (
                    <section aria-labelledby="done-h" className="space-y-2">
                        <h2 id="done-h" className="font-medium">
                            {t('Answered')}
                        </h2>
                        <ul className="divide-y rounded-md border bg-card">
                            {answered.map((q) => (
                                <Card key={q.id} q={q} />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
