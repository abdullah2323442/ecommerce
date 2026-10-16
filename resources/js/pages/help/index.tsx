import Pagination from '@/components/admin/pagination';
import { categoryLabel, HelpNotice } from '@/components/help-bits';
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
    const go = (next: { category?: string | null; q?: string }) => router.get('/help', Object.fromEntries(Object.entries({ category, q, ...next }).filter(([, v]) => v)), { preserveState: true, replace: true });

    return (
        <PublicLayout>
            <Head title={t('Help board')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-display text-4xl font-extrabold tracking-tight">{t('Help board')}</h1>
                        <p className="mt-1 max-w-prose text-[var(--pt-muted)]">{t('Post what you need: a worker, a product you cannot find, any help with your business. Anyone can answer.')}</p>
                    </div>
                    <div className="flex gap-2">
                        {signedIn && (
                            <Button asChild variant="outline">
                                <Link href="/help/mine">{t('My posts')}</Link>
                            </Button>
                        )}
                        <Button asChild>
                            <Link href={signedIn ? '/help/new' : route('login')}>{t('Post something')}</Link>
                        </Button>
                    </div>
                </div>

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
                    <input id="help-q" value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t('Search the board')} className="h-11 min-w-48 flex-1 rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] px-3" />
                    <Button type="submit" variant="secondary">
                        {t('Search')}
                    </Button>
                </form>

                <div role="group" aria-label={t('Kind of post')} className="flex flex-wrap gap-2">
                    {[null, ...categories].map((c) => (
                        <button key={c ?? 'all'} type="button" aria-pressed={category === c} onClick={() => go({ category: c })} className={`rounded-full border px-3 py-1 text-sm ${category === c ? 'border-[var(--pt-ink)] bg-[var(--pt-ink)] text-[var(--pt-surface)]' : 'border-[var(--pt-line)]'}`}>
                            {c === null ? t('All') : t(categoryLabel(c))}
                        </button>
                    ))}
                </div>

                {posts.data.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('Nothing here yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {posts.data.map((p) => (
                            <li key={p.id}>
                                <Link href={`/help/${p.id}`} className="block px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <p className="text-xs font-semibold text-[var(--pt-muted)]">
                                        {t(categoryLabel(p.category))} · {date(p.at)}
                                    </p>
                                    <p className="font-display text-xl font-bold">{p.title}</p>
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
