import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Card {
    id: number;
    name: string;
    description: string | null;
    area: string;
    open: boolean;
    photo: string | null;
    hours: string;
}

/** Food (spec ch 17): the buyer's area first, then only the restaurants that deliver there. */
export default function FoodIndex({ area, restaurants, areas }: { area: string; restaurants: Card[]; areas: string[] }) {
    const { t } = useI18n();
    const [value, setValue] = useState(area);

    return (
        <PublicLayout>
            <Head title={t('Food')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <h1 className="font-display text-3xl font-extrabold">{t('Food')}</h1>
                    <p className="mt-2 text-[var(--pt-muted)]">{t('Fast food from restaurants near you. Tell us where you are and we show who delivers there.')}</p>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/food', { area: value }, { preserveState: true });
                    }}
                    className="flex flex-wrap items-end gap-2 rounded-2xl border bg-[var(--pt-surface)] p-4"
                >
                    <label className="min-w-56 flex-1 text-sm font-medium">
                        {t('Your area')}
                        <Input list="food-areas" className="mt-1" value={value} maxLength={80} placeholder={t('For example: Mirpur')} onChange={(e) => setValue(e.target.value)} />
                        <datalist id="food-areas">
                            {areas.map((a) => (
                                <option key={a} value={a} />
                            ))}
                        </datalist>
                    </label>
                    <Button type="submit" disabled={value.trim() === ''}>
                        {t('Show restaurants')}
                    </Button>
                </form>

                {area === '' ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('Write your area to see the restaurants.')}</p>
                ) : restaurants.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('No restaurant delivers to this area yet.')}</p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {restaurants.map((r) => (
                            <li key={r.id}>
                                <Link href={`/food/r/${r.id}`} className={`flex h-full gap-3 rounded-2xl border bg-[var(--pt-surface)] p-3 hover:border-[var(--pt-ink)] ${r.open ? '' : 'opacity-70'}`}>
                                    {r.photo ? <img src={r.photo} alt="" className="size-20 rounded-xl object-cover" /> : <div aria-hidden className="size-20 rounded-xl bg-[var(--pt-sage)]" />}
                                    <div className="min-w-0 space-y-1">
                                        <p className="font-display text-lg font-bold">{r.name}</p>
                                        {r.description && <p className="line-clamp-2 text-sm text-[var(--pt-muted)]">{r.description}</p>}
                                        <p className="text-xs">
                                            <span className={`rounded-full px-2 py-0.5 font-semibold ${r.open ? 'bg-emerald-100 text-emerald-900' : 'bg-muted'}`}>{r.open ? t('Open now') : t('Closed now')}</span>{' '}
                                            <span className="text-[var(--pt-muted)]">{r.hours}</span>
                                        </p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
