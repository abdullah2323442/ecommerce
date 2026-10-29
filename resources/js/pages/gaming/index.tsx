import RequestButton from '@/components/requests/request-button';
import { DISPLAY, FaqList, type Front, type FrontBlock, SectionHead, ServiceStrip, StepsList } from '@/components/storefront/shared';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Search, Zap } from 'lucide-react';
import { useState } from 'react';

interface Game {
    name: string;
    slug: string;
    packages: number;
    cover: string | null;
    packs: { id: number; name: string; price: number }[];
    from: number;
}

interface Pack {
    id: number;
    name: string;
    price: number;
    game: string;
    slug: string;
    cover: string | null;
}

/**
 * Game top-up, laid out the way a game store is: a night-blue stage with the search up front, a poster for each game, the best value packs, and three
 * plain steps. The sections, their order and all their words come from the admin (Admin > Shop fronts).
 */
export default function GamingIndex({ games, front }: { games: Game[]; front: Front | null }) {
    const { t, money } = useI18n();
    const [q, setQ] = useState('');
    const term = q.trim().toLowerCase();

    const blocks: FrontBlock[] = front?.blocks ?? [
        {
            id: 0,
            kind: 'hero',
            title: t('Game top-up'),
            text: t('Diamonds for your game. Pick the game, give your player ID, pick a package, pay online.'),
            button: null,
        },
        { id: -1, kind: 'games', title: null, text: null, button: null, games },
    ];

    const section = (b: FrontBlock) => {
        switch (b.kind) {
            case 'hero':
                return (
                    <section key={b.id} aria-labelledby="game-title" className="relative isolate -mx-5 overflow-hidden sm:mx-0 sm:rounded-[2rem]">
                        {b.image && (
                            <img
                                src={b.image}
                                alt=""
                                fetchPriority="high"
                                decoding="async"
                                className="absolute inset-0 -z-20 size-full object-cover"
                            />
                        )}
                        <span
                            aria-hidden
                            className="absolute inset-0 -z-10 bg-gradient-to-r from-[var(--g-bg)] via-[var(--g-bg)]/80 to-[var(--g-bg)]/10"
                        />
                        <div className="px-5 py-14 sm:px-12 sm:py-24">
                            <h1
                                id="game-title"
                                style={DISPLAY}
                                className="art-rise max-w-xl text-5xl leading-none font-normal tracking-tight text-balance sm:text-7xl"
                            >
                                {b.title}
                            </h1>
                            {b.text && <p className="art-rise art-d1 mt-4 max-w-lg text-lg text-[var(--g-muted)]">{b.text}</p>}
                            <label className="art-rise art-d2 mt-8 flex h-14 max-w-xl items-center gap-3 rounded-full border border-[var(--g-line)] bg-[var(--g-panel)]/90 px-5 backdrop-blur focus-within:border-[var(--g-cyan)]">
                                <Search aria-hidden className="size-5 text-[var(--g-muted)]" strokeWidth={1.6} />
                                <span className="sr-only">{t('Search games')}</span>
                                <input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder={t('Search games')}
                                    className="h-full min-w-0 flex-1 bg-transparent outline-none placeholder:text-[var(--g-muted)]"
                                />
                            </label>
                        </div>
                    </section>
                );
            case 'games': {
                const list = ((b.games as Game[]) ?? games).filter((g) => g.name.toLowerCase().includes(term));

                return (
                    <section key={b.id} aria-label={b.title ?? t('Games')}>
                        <SectionHead title={b.title} />
                        {list.length === 0 ? (
                            <p className="mt-8 rounded-[1.5rem] border border-dashed border-[var(--g-line)] p-10 text-center text-[var(--g-muted)]">
                                {games.length === 0 ? t('No games yet.') : t('No game matches that.')}
                            </p>
                        ) : (
                            <ul className="mt-8 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3">
                                {list.map((g) => (
                                    <li key={g.slug}>
                                        <Link
                                            href={`/gaming/${g.slug}`}
                                            className="art-zoom art-lift group relative isolate flex aspect-[4/5] flex-col justify-end overflow-hidden rounded-[1.5rem] border border-[var(--g-line)] bg-[var(--g-panel-2)] p-4 sm:p-5"
                                        >
                                            {g.cover && (
                                                <img
                                                    src={g.cover}
                                                    alt=""
                                                    loading="lazy"
                                                    decoding="async"
                                                    className="absolute inset-0 -z-20 size-full object-cover"
                                                />
                                            )}
                                            <span
                                                aria-hidden
                                                className="absolute inset-0 -z-10 bg-gradient-to-t from-[var(--g-bg)] via-[var(--g-bg)]/20 to-transparent"
                                            />
                                            <span style={DISPLAY} className="text-2xl leading-tight sm:text-3xl">
                                                {g.name}
                                            </span>
                                            <span className="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                <span className="tabular text-sm text-[var(--g-muted)]">
                                                    {t('From {price}', { price: money(g.from) })}
                                                </span>
                                                <span className="inline-flex h-9 items-center gap-1.5 rounded-full bg-[var(--g-cyan)] px-4 text-sm font-medium text-[var(--g-bg)]">
                                                    <Zap aria-hidden className="size-4" strokeWidth={2} />
                                                    {t('Top up')}
                                                </span>
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                );
            }
            case 'packs': {
                const packs = (b.packs as Pack[]) ?? [];

                return (
                    <section key={b.id} aria-label={b.title ?? undefined} className="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-16">
                        <SectionHead title={b.title} text={b.text} />
                        <ul className="border-t border-[var(--g-line)]">
                            {packs.map((p) => (
                                <li key={p.id} className="border-b border-[var(--g-line)]">
                                    <Link href={`/gaming/${p.slug}?pack=${p.id}`} className="group flex min-h-20 items-center gap-4 py-3">
                                        <span className="block size-14 shrink-0 overflow-hidden rounded-xl bg-[var(--g-panel-2)]">
                                            {p.cover && (
                                                <img src={p.cover} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
                                            )}
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-semibold">{p.name}</span>
                                            <span className="block text-sm text-[var(--g-muted)]">{p.game}</span>
                                        </span>
                                        <span style={DISPLAY} className="tabular text-2xl text-[var(--g-cyan)]">
                                            {money(p.price)}
                                        </span>
                                        <ArrowUpRight
                                            aria-hidden
                                            className="size-5 shrink-0 text-[var(--g-muted)] transition-transform duration-300 group-hover:translate-x-1 group-hover:-translate-y-1"
                                            strokeWidth={1.6}
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                );
            }
            case 'steps':
                return <StepsList key={b.id} title={b.title} items={b.items ?? []} accent="var(--g-cyan)" />;
            case 'service':
                return <ServiceStrip key={b.id} items={b.items ?? []} label={t('Why top up here')} />;
            case 'faq':
                return <FaqList key={b.id} title={b.title} items={b.items ?? []} />;
            default:
                return null;
        }
    };

    return (
        <PublicLayout>
            <Head title={t('Game top-up')} />
            <div className="game -mx-4 min-h-[70vh] px-5 py-10 sm:mx-0 sm:rounded-[2rem] sm:px-10 sm:py-14">
                <div className="mx-auto max-w-5xl space-y-16">
                    {blocks.map(section)}
                    <section aria-labelledby="game-missing-h" className="space-y-3 rounded-2xl bg-[var(--pt-surface)] p-6 text-[var(--pt-ink)]">
                        <h2 id="game-missing-h" className="font-display text-xl font-extrabold">
                            {t('Not the game or pack you wanted?')}
                        </h2>
                        <RequestButton sector="gaming" />
                    </section>
                </div>
            </div>
        </PublicLayout>
    );
}
