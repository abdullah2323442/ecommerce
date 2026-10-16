import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Props {
    days: number;
    searches: { term: string; times: number; found: number | null }[];
    medicines: { id: number; name: string; views: number; carts: number }[];
    funnel: { search: number; view: number; cart: number; checkout: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'What people look for', href: '/admin/behavior' }];

export default function Behavior({ days, searches, medicines, funnel }: Props) {
    const { t, num } = useI18n();
    const steps = [
        ['Searched', funnel.search],
        ['Looked at a medicine', funnel.view],
        ['Put something in the cart', funnel.cart],
        ['Sent an order', funnel.checkout],
    ] as const;
    const top = Math.max(1, ...steps.map(([, n]) => n));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('What people look for')} />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4">
                <header className="flex flex-wrap items-baseline justify-between gap-3">
                    <div>
                        <h1 className="font-display text-2xl font-extrabold">{t('What people look for')}</h1>
                        <p className="text-muted-foreground text-sm">{t('From visitors and customers on the site. Staff are left out. No names are kept unless the person was signed in.')}</p>
                    </div>
                    <select aria-label={t('Period')} value={days} onChange={(e) => router.get('/admin/behavior', { days: e.target.value }, { preserveState: true })} className="bg-background h-9 rounded-md border px-2 text-sm">
                        {[7, 30, 90].map((d) => (
                            <option key={d} value={d}>
                                {t('Last {n} days', { n: num(d) })}
                            </option>
                        ))}
                    </select>
                </header>

                <section aria-labelledby="fun-h" className="space-y-2">
                    <h2 id="fun-h" className="font-medium">
                        {t('Visitors, step by step')}
                    </h2>
                    <ul className="space-y-1.5">
                        {steps.map(([label, n]) => (
                            <li key={label} className="flex items-center gap-3 text-sm">
                                <span className="w-56">{t(label)}</span>
                                <span className="bg-muted h-3 flex-1 overflow-hidden rounded-full">
                                    <span className="bg-primary block h-full rounded-full" style={{ width: `${(n / top) * 100}%` }} />
                                </span>
                                <span className="tabular w-12 text-right font-semibold">{num(n)}</span>
                            </li>
                        ))}
                    </ul>
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-2">
                        <h2 className="font-medium">{t('What people search for')}</h2>
                        {searches.length === 0 ? (
                            <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('Nothing yet.')}</p>
                        ) : (
                            <ul className="divide-y rounded-md border text-sm">
                                {searches.map((s) => (
                                    <li key={s.term} className="flex items-center gap-3 px-3 py-1.5">
                                        <span className="flex-1">{s.term}</span>
                                        {s.found === 0 && <span className="rounded bg-red-500/15 px-1.5 text-xs font-semibold">{t('Found nothing')}</span>}
                                        <span className="tabular text-muted-foreground w-10 text-right">{num(s.times)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="space-y-2">
                        <div>
                            <h2 className="font-medium">{t('Looked at, but not ordered')}</h2>
                            <p className="text-muted-foreground text-xs">{t('Many looks and no cart can mean the price or the photo is the problem.')}</p>
                        </div>
                        {medicines.length === 0 ? (
                            <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">{t('Nothing yet.')}</p>
                        ) : (
                            <ul className="divide-y rounded-md border text-sm">
                                {medicines.map((m) => (
                                    <li key={m.id} className="flex items-center gap-3 px-3 py-1.5">
                                        <span className="flex-1">{m.name}</span>
                                        <span className="tabular text-muted-foreground w-24 text-right">
                                            {num(m.views)} {t('looks')}
                                        </span>
                                        <span className={`tabular w-24 text-right ${m.carts === 0 && m.views >= 3 ? 'font-semibold text-red-600' : ''}`}>
                                            {num(m.carts)} {t('carts')}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
