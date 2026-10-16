import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

/** Game top-up (spec ch 18): a list of games. Pick one. */
export default function GamingIndex({ games }: { games: { name: string; slug: string; packages: number }[] }) {
    const { t, num } = useI18n();

    return (
        <PublicLayout>
            <Head title={t('Game top-up')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <h1 className="font-display text-3xl font-extrabold">{t('Game top-up')}</h1>
                    <p className="mt-2 text-[var(--pt-muted)]">{t('Diamonds for your game. Pick the game, give your player ID, pick a package, pay online.')}</p>
                </div>
                {games.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('No games yet.')}</p>
                ) : (
                    <ul className="grid gap-3 sm:grid-cols-3">
                        {games.map((g) => (
                            <li key={g.slug}>
                                <Link href={`/gaming/${g.slug}`} className="flex h-full flex-col justify-between gap-6 rounded-2xl border bg-[var(--pt-surface)] p-4 hover:border-[var(--pt-ink)]">
                                    <span aria-hidden className="grid size-14 place-items-center rounded-xl bg-[var(--pt-ink)] font-display text-2xl font-extrabold text-[var(--pt-surface)]">
                                        {g.name.slice(0, 1)}
                                    </span>
                                    <span>
                                        <span className="block font-display text-lg font-bold">{g.name}</span>
                                        <span className="text-xs text-[var(--pt-muted)]">{t('{n} packages', { n: num(g.packages) })}</span>
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs text-[var(--pt-muted)]">{t('Top-ups are paid online. There is no cash on delivery.')}</p>
            </div>
        </PublicLayout>
    );
}
