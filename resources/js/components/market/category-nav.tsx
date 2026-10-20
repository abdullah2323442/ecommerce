import { useI18n } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

export interface TreeNode {
    slug: string;
    name: string;
    count: number;
    children: TreeNode[];
}

export interface CategoryNode {
    slug: string;
    name: string;
    trail: { slug: string; name: string }[];
    children: { slug: string; name: string; count: number; image: string | null }[];
}

const DISPLAY = { fontFamily: "'Changa One', 'Noto Serif Bengali', Georgia, serif" };

/** Home > Market > Electronics > Audio > Headphones: every step a link, the last one the page you are on. */
export function Breadcrumb({ sector, sectorName, category }: { sector: string; sectorName: string; category: CategoryNode | null }) {
    const { t } = useI18n();
    const steps = [
        { name: t('Market'), href: route('market.index') },
        { name: sectorName, href: category ? route('market.index', { sector }) : null },
        ...(category?.trail ?? []).map((c, i, all) => ({
            name: c.name,
            href: i === all.length - 1 ? null : route('market.index', { sector, category: c.slug }),
        })),
    ];

    return (
        <nav aria-label={t('You are here')}>
            <ol className="flex flex-wrap items-center gap-1 text-sm text-[var(--pt-muted)]">
                {steps.map((s, i) => (
                    <li key={`${s.name}-${i}`} className="flex items-center gap-1">
                        {i > 0 && <ChevronRight aria-hidden className="size-4" strokeWidth={1.6} />}
                        {s.href ? (
                            <Link href={s.href} className="underline-offset-4 hover:text-[var(--pt-ink)] hover:underline">
                                {s.name}
                            </Link>
                        ) : (
                            <span aria-current="page" className="text-[var(--pt-ink)]">
                                {s.name}
                            </span>
                        )}
                    </li>
                ))}
            </ol>
        </nav>
    );
}

/** The nodes just below where you are, each with a picture and a count: the way down the tree. */
export function SubTiles({
    sector,
    nodes,
    title,
}: {
    sector: string;
    nodes: { slug: string; name: string; count: number; image: string | null }[];
    title?: string;
}) {
    const { t, num } = useI18n();

    if (nodes.length === 0) return null;

    return (
        <section aria-label={title ?? t('Browse by type')}>
            {title && (
                <h2 style={DISPLAY} className="mb-4 text-3xl leading-none tracking-tight">
                    {title}
                </h2>
            )}
            <ul className="no-scrollbar -mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0 lg:grid-cols-5">
                {nodes.map((n) => (
                    <li key={n.slug} className="w-40 shrink-0 snap-start sm:w-auto">
                        <Link
                            href={route('market.index', { sector, category: n.slug })}
                            className="art-zoom art-lift relative isolate flex aspect-[4/5] flex-col justify-end overflow-hidden rounded-2xl bg-[var(--pt-ink)] p-4 text-white"
                        >
                            {n.image && (
                                <img src={n.image} alt="" loading="lazy" decoding="async" className="absolute inset-0 -z-20 size-full object-cover" />
                            )}
                            <span aria-hidden className="absolute inset-0 -z-10 bg-gradient-to-t from-black/75 via-black/10 to-transparent" />
                            <span style={DISPLAY} className="text-xl leading-tight">
                                {n.name}
                            </span>
                            <span className="tabular text-xs text-white/80">{t('{n} products', { n: num(n.count) })}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** The whole tree of one shop as a nested list. The branch you are in is open; the others fold shut. Every node is a link. */
export function TreeNav({ sector, tree, current }: { sector: string; tree: TreeNode[]; current: CategoryNode | null }) {
    const { t, num } = useI18n();
    const path = new Set((current?.trail ?? []).map((c) => c.slug));

    const render = (nodes: TreeNode[], depth: number) => (
        <ul className={depth === 0 ? 'space-y-0.5' : 'mt-0.5 ml-3 space-y-0.5 border-l border-[var(--pt-line)] pl-3'}>
            {nodes.map((n) => {
                const here = current?.slug === n.slug;
                const open = path.has(n.slug);

                return (
                    <li key={n.slug}>
                        <Link
                            href={route('market.index', { sector, category: n.slug })}
                            aria-current={here ? 'page' : undefined}
                            className={`flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors duration-200 ${here ? 'bg-[var(--pt-ink)] text-[var(--pt-paper)]' : open ? 'font-semibold' : 'hover:bg-[var(--pt-line)]/60'}`}
                        >
                            <span>{n.name}</span>
                            <span className="tabular text-xs opacity-60">{num(n.count)}</span>
                        </Link>
                        {open && n.children.length > 0 && render(n.children, depth + 1)}
                    </li>
                );
            })}
        </ul>
    );

    return (
        <nav aria-label={t('Categories')}>
            <p style={DISPLAY} className="mb-3 text-xl">
                {t('Categories')}
            </p>
            {render(tree, 0)}
        </nav>
    );
}
