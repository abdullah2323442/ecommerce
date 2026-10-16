import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

interface Job {
    id: number;
    number: string;
    kind: 'wood' | 'clothing';
    status: string;
    due_on: string | null;
    done: number;
    waiting: boolean;
    is_main: boolean;
    urgent: boolean;
}

interface Props {
    jobs: Job[];
    finished: Job[];
    asks: Job[];
    ledger: { earned: number; paid: number; owed: number; month: number; delays: number };
}

function List({ items, empty }: { items: Job[]; empty: string }) {
    const { t, num, date } = useI18n();

    return items.length === 0 ? (
        <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-[var(--pt-muted)]">{empty}</p>
    ) : (
        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
            {items.map((j) => (
                <li key={j.id}>
                    <Link href={`/craftsman/jobs/${j.id}`} className="flex flex-wrap items-center gap-x-6 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                        <span className="min-w-32 flex-1 font-medium">
                            {j.number} · {j.kind === 'wood' ? t('Wood') : t('Clothing')}
                            {j.urgent && <span className="ms-2 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-900">{t('Urgent')}</span>}
                        </span>
                        {j.due_on && <span className="text-sm text-[var(--pt-muted)]">{t('Due on {date}.', { date: date(j.due_on) })}</span>}
                        {j.waiting && <span className="text-sm font-semibold">{t('Waiting for materials')}</span>}
                        <span className="tabular w-16 text-right font-semibold">{num(j.done)}%</span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/** A craftsman's own screen (spec ch 16.4): their jobs, their deadline, their own wages. */
export default function CraftsmanHome({ jobs, finished, asks, ledger }: Props) {
    const { t, money, num } = useI18n();

    return (
        <PublicLayout>
            <Head title={t('Your jobs')} />
            <div className="mx-auto max-w-3xl space-y-8">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Your jobs')}</h1>
                    <Link href="/inbox" className="text-sm font-semibold underline underline-offset-4">
                        {t('Messages')}
                    </Link>
                </div>

                <section className="grid gap-3 rounded-2xl border bg-[var(--pt-surface)] p-4 sm:grid-cols-4">
                    {(
                        [
                            ['Earned', ledger.earned],
                            ['Paid to you', ledger.paid],
                            ['Still owed to you', ledger.owed],
                            ['Earned this month', ledger.month],
                        ] as const
                    ).map(([label, value]) => (
                        <div key={label}>
                            <p className="text-xs text-[var(--pt-muted)]">{t(label)}</p>
                            <p className="tabular font-display text-xl font-bold">{money(value)}</p>
                        </div>
                    ))}
                    {ledger.delays > 0 && <p className="text-xs text-[var(--pt-muted)] sm:col-span-4">{t('Jobs that ran late: {n}', { n: num(ledger.delays) })}</p>}
                </section>

                {asks.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Tell us the wage')}</h2>
                        <List items={asks} empty="" />
                    </section>
                )}

                <section className="space-y-2">
                    <h2 className="font-display text-xl font-bold">{t('Jobs you are on')}</h2>
                    <List items={jobs} empty={t('No jobs for you now.')} />
                </section>

                {finished.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-display text-xl font-bold">{t('Finished')}</h2>
                        <List items={finished} empty="" />
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
