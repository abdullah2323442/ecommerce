import { categoryLabel } from '@/components/help-bits';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Post {
    id: number;
    category: string;
    title: string;
    at: string;
    status: 'pending' | 'open' | 'rejected' | 'closed';
    review_note: string | null;
    asked: boolean;
    hidden: boolean;
}

export default function HelpMine({ posts, refusal }: { posts: Post[]; refusal: string | null }) {
    const { t, date } = useI18n();
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const state = (p: Post) => (p.status === 'pending' ? t('Waiting for us to read it') : p.status === 'open' ? (p.hidden ? t('Taken down for now') : t('Shown to everyone')) : p.status === 'rejected' ? t('Not published') : t('Closed'));

    return (
        <PublicLayout>
            <Head title={t('My posts')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('My posts')}</h1>
                    <Button asChild disabled={!!refusal}>
                        <Link href="/help/new">{t('Post something')}</Link>
                    </Button>
                </div>
                {flash && <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">{flash}</p>}
                {refusal && <p className="text-sm text-[var(--pt-muted)]">{refusal}</p>}

                {posts.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-[var(--pt-muted)]">{t('You have not posted anything yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {posts.map((p) => (
                            <li key={p.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <div className="min-w-48 flex-1">
                                    <Link href={`/help/${p.id}`} className="font-semibold hover:underline">
                                        {p.title}
                                    </Link>
                                    <p className="text-xs text-[var(--pt-muted)]">
                                        {t(categoryLabel(p.category))} · {date(p.at)} · {state(p)}
                                    </p>
                                    {p.status === 'rejected' && p.review_note && <p className="text-xs">{p.review_note}</p>}
                                    {p.asked && <p className="text-xs font-semibold text-amber-800">{t('Is this still needed? If you do not answer, it will close.')}</p>}
                                </div>
                                {p.asked && (
                                    <Button size="sm" onClick={() => router.post(`/help/${p.id}/confirm`, {}, { preserveScroll: true })}>
                                        {t('Yes, still needed')}
                                    </Button>
                                )}
                                {(p.status === 'open' || p.status === 'pending') && (
                                    <Button size="sm" variant="outline" onClick={() => router.post(`/help/${p.id}/close`, {}, { preserveScroll: true })}>
                                        {t('Close it')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
