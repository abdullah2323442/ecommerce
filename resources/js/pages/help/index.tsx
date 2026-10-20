import Pagination from '@/components/admin/pagination';
import { categoryLabel, HelpNotice } from '@/components/help-bits';
import SectionHero from '@/components/home/section-hero';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Post {
    id: number;
    category: string;
    title: string;
    excerpt: string;
    at: string;
    comments: number | null;
}

interface Props {
    posts: { data: Post[]; total: number; links: { url: string | null; label: string; active: boolean }[] };
    category: string | null;
    q: string;
    categories: string[];
}

export default function HelpIndex({ posts, category, q, categories }: Props) {
    const { t, num, date } = useI18n();
    const signedIn = usePage<SharedData>().props.auth.user !== null;
    const [term, setTerm] = useState(q);
    const go = (next: { category?: string | null; q?: string }) =>
        router.get('/help', Object.fromEntries(Object.entries({ category, q, ...next }).filter(([, v]) => v)), {
            preserveState: true,
            replace: true,
        });

    return (
        <PublicLayout>
            <Head title={t('Help board')} />
            <div className="mx-auto max-w-5xl space-y-6">
                <SectionHero
                    image="/images/home/help"
                    title={t('Help board')}
                    line={t('Post what you need: a worker, a product you cannot find, any help with your business. Anyone can answer.')}
                >
                    {signedIn && (
                        <Button asChild variant="outline" className="bg-white/90 text-black hover:bg-white">
                            <Link href="/help/mine">{t('My posts')}</Link>
                        </Button>
                    )}
                    <Button asChild>
                        <Link href={signedIn ? '/help/new' : route('login')}>{t('Post something')}</Link>
                    </Button>
                </SectionHero>

                <HelpNotice />

                <form
                    role="search"
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go({ q: term });
                    }}
                >
                    <label htmlFor="help-q" className="sr-only">
                        {t('Search the board')}
                    </label>
                    <input
                        id="help-q"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        placeholder={t('Search the board')}
                        className="h-11 min-w-48 flex-1 rounded-full border border-[var(--pt-ink)] bg-transparent px-3"
                    />
                    <Button type="submit" className="h-11 rounded-full px-6">
                        {t('Search')}
                    </Button>
                </form>

                <div role="group" aria-label={t('Kind of post')} className="flex flex-wrap gap-2">
                    {[null, ...categories].map((c) => (
                        <button
                            key={c ?? 'all'}
                            type="button"
                            aria-pressed={category === c}
                            onClick={() => go({ category: c })}
                            className={`rounded-full border px-4 py-1.5 text-sm ${category === c ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-paper)]' : 'border-[var(--pt-line)]'}`}
                        >
                            {c === null ? t('All') : t(categoryLabel(c))}
                        </button>
                    ))}
                </div>

                {posts.data.length === 0 ? (
                    <p className="border border-dashed border-[var(--pt-ink)] p-10 text-center text-[var(--pt-muted)]">{t('Nothing here yet.')}</p>
                ) : (
                    <ul className="divide-y divide-[var(--pt-line)] border-y border-[var(--pt-ink)]">
                        {posts.data.map((p) => (
                            <li key={p.id}>
                                <Link href={`/help/${p.id}`} className="block px-1 py-5 hover:bg-[var(--pt-surface)] sm:px-3">
                                    <p className="text-xs text-[var(--pt-muted)]">
                                        {t(categoryLabel(p.category))} · {date(p.at)}
                                    </p>
                                    <p
                                        style={{ fontFamily: "'Cormorant Garamond', 'Hind Siliguri', Georgia, serif" }}
                                        className="mt-1 text-2xl leading-tight font-medium"
                                    >
                                        {p.title}
                                    </p>
                                    <p className="mt-0.5 text-sm text-[var(--pt-muted)]">{p.excerpt}</p>
                                    {p.comments ? <p className="mt-1 text-xs">{t('{n} comments', { n: num(p.comments) })}</p> : null}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination links={posts.links} />
            </div>
        </PublicLayout>
    );
}
