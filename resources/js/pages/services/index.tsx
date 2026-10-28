import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Offer {
    id: number;
    title: string;
    description: string | null;
    minutes: number;
    price: number;
    place: 'provider' | 'online' | 'home';
    area: string | null;
    provider: string;
    next: string;
}

/** Services anyone can book: a lesson, a repair, a visit. Each shows its next free time. */
export default function Services({ offers, area }: { offers: Offer[]; area: string }) {
    const { t, num, money, dateTime } = useI18n();
    const [q, setQ] = useState(area);
    const place = (p: Offer['place']) => ({ provider: t('At the provider'), online: t('Online'), home: t('At your home') })[p];

    return (
        <PublicLayout>
            <Head title={t('Book a service')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <header className="space-y-1">
                    <h1 className="font-display text-4xl font-extrabold leading-tight">{t('Book a service')}</h1>
                    <p className="text-[var(--pt-muted)]">{t('Lessons, repairs and visits from people who sell here. Pick a time, and they confirm it.')}</p>
                </header>

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/services', { area: q || undefined }, { preserveState: true });
                    }}
                >
                    <label className="text-sm font-medium">
                        {t('Your area')}
                        <Input className="mt-1 w-56" value={q} onChange={(e) => setQ(e.target.value)} />
                    </label>
                    <button type="submit" className="h-10 rounded-full border border-[var(--pt-ink)] px-5 text-sm font-semibold">
                        {t('Search')}
                    </button>
                </form>

                {offers.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-sm text-[var(--pt-muted)]">{t('No service has a free time right now. Please look again later.')}</p>
                ) : (
                    <ul className="divide-y rounded-[1.5rem] border bg-[var(--pt-surface)]">
                        {offers.map((o) => (
                            <li key={o.id}>
                                <Link href={`/services/${o.id}`} className="block space-y-1 px-5 py-4 hover:bg-[var(--pt-line)]/40 focus-visible:bg-[var(--pt-line)]/40 focus-visible:outline-hidden">
                                    <span className="flex flex-wrap items-baseline justify-between gap-2">
                                        <span className="font-display text-xl font-bold">{o.title}</span>
                                        <span className="tabular font-semibold">{o.price > 0 ? money(o.price) : t('Ask the provider')}</span>
                                    </span>
                                    <span className="block text-sm text-[var(--pt-muted)]">
                                        {o.provider} · {t('{n} minutes', { n: num(o.minutes) })} · {place(o.place)}
                                        {o.area && ` · ${o.area}`}
                                    </span>
                                    <span className="block text-sm">{t('Next free time: {when}', { when: dateTime(o.next) })}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
