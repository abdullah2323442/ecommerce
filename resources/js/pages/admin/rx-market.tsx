import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Props {
    total: number;
    people: { id: number; name: string | null; phone: string; area: string | null; at: string }[];
    ready: { requests: number; offers: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Prescription marketplace', href: '/admin/rx-market' }];

/** How many people want the prescription marketplace, before it is built. */
export default function RxMarket({ total, people, ready }: Props) {
    const { t, num, dateTime } = useI18n();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Prescription marketplace')} />
            <div className="mx-auto w-full max-w-4xl space-y-5 p-4">
                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold tracking-tight">{t('Prescription marketplace')}</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">
                        {t('Not open yet. The tables for prescriptions and pharmacy answers are ready, and the page at /prescription-market lets people say they want it.')}
                    </p>
                </header>
                <section className="rounded-xl border bg-card p-4">
                    <p className="tabular font-display text-4xl font-extrabold">{num(total)}</p>
                    <p className="text-sm text-muted-foreground">
                        {t('people want it')} · {t('{a} prescriptions and {b} answers so far', { a: num(ready.requests), b: num(ready.offers) })}
                    </p>
                </section>
                {people.length === 0 ? (
                    <p className="rounded-xl border p-6 text-sm text-muted-foreground">{t('Nobody has asked yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-xl border text-sm">
                        {people.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                                <span className="font-medium">{p.name || t('No name given')}</span>
                                <span className="tabular">{p.phone}</span>
                                <span className="text-muted-foreground">{p.area}</span>
                                <span className="text-xs text-muted-foreground">{dateTime(p.at)}</span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
