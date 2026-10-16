import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Row {
    id: number;
    listing: string;
    question: string;
    answer: string | null;
    at: string;
    late: boolean;
}

function Card({ q }: { q: Row }) {
    const { t, dateTime } = useI18n();
    const [text, setText] = useState(q.answer ?? '');
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <li className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-semibold">{q.listing}</p>
                <span className="text-xs text-[var(--pt-muted)]">{dateTime(q.at)}</span>
            </div>
            <p>{q.question}</p>
            {q.late && !q.answer && <p className="text-xs font-semibold text-red-700">{t('This went to us because it was left too long.')}</p>}
            <textarea aria-label={t('Answer')} rows={2} maxLength={1500} value={text} onChange={(e) => setText(e.target.value)} className="w-full rounded-md border bg-background p-2 text-sm" placeholder={t('Write the answer')} />
            <InputError message={errors.answer} />
            <Button size="sm" disabled={text.trim().length < 2} onClick={() => router.put(`/wholesale/sell/questions/${q.id}`, { answer: text }, { preserveScroll: true })}>
                {q.answer ? t('Save the answer') : t('Answer')}
            </Button>
        </li>
    );
}

export default function SellerQuestions({ waiting, answered, hours }: { waiting: Row[]; answered: Row[]; hours: number }) {
    const { t, num } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;

    return (
        <PublicLayout>
            <Head title={t('Questions from buyers')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href="/wholesale/sell" className="text-sm underline underline-offset-4">
                    {t('Sell')}
                </Link>
                <div>
                    <h1 className="font-display text-3xl font-extrabold">{t('Questions from buyers')}</h1>
                    <p className="text-sm text-[var(--pt-muted)]">{t('Answer within {n} hours. After that the question goes to us, and it counts against you.', { n: num(hours) })}</p>
                </div>
                {flash && <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">{flash}</p>}

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Waiting for your answer')}</h2>
                    {waiting.length === 0 ? <p className="text-sm text-[var(--pt-muted)]">{t('No question is waiting.')}</p> : <ul className="space-y-3">{waiting.map((q) => <Card key={q.id} q={q} />)}</ul>}
                </section>

                {answered.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Answered')}</h2>
                        <ul className="space-y-3">
                            {answered.map((q) => (
                                <Card key={q.id} q={q} />
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
