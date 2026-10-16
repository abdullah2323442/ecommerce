import InputError from '@/components/input-error';
import { categoryLabel, HelpNotice, ProductCard, type ProductCardData } from '@/components/help-bits';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    post: {
        id: number;
        category: string;
        title: string;
        body: string;
        contact: string | null;
        at: string;
        status: 'pending' | 'open' | 'rejected' | 'closed';
        review_note: string | null;
        mine: boolean;
        asked: boolean;
        reported: boolean;
    };
    comments: { id: number; at: string; text: string; cards: ProductCardData[] }[];
    canComment: boolean;
}

export default function HelpShow({ post, comments, canComment }: Props) {
    const { t, date, dateTime } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const signedIn = (usePage().props.auth as { user: unknown }).user !== null;
    const flash = (usePage().props.flash as { status?: string } | undefined)?.status;
    const [text, setText] = useState('');
    const [reporting, setReporting] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <PublicLayout>
            <Head title={post.title} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href="/help" className="text-sm underline underline-offset-4">
                    {t('Back to the Help board')}
                </Link>

                {flash && <p role="status" className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">{flash}</p>}
                <InputError message={errors.post} />

                {post.mine && post.status !== 'open' && (
                    <p role="status" className="rounded-2xl border px-4 py-3 text-sm">
                        {post.status === 'pending' ? t('We are reading this post. It will be shown once we approve it.') : post.status === 'rejected' ? t('We did not publish this post. {note}', { note: post.review_note ?? '' }) : t('This post is closed.')}
                    </p>
                )}
                {post.mine && post.asked && (
                    <div role="alert" className="flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--pt-amber)]/50 bg-[var(--pt-amber)]/10 px-4 py-3 text-sm">
                        <p className="flex-1 font-semibold">{t('Is this still needed? If you do not answer, it will close.')}</p>
                        <Button size="sm" onClick={() => router.post(`/help/${post.id}/confirm`, {}, { preserveScroll: true })}>
                            {t('Yes, still needed')}
                        </Button>
                        <Button size="sm" variant="outline" onClick={() => router.post(`/help/${post.id}/close`, {}, { preserveScroll: true })}>
                            {t('No, close it')}
                        </Button>
                    </div>
                )}

                <article className="space-y-3">
                    <p className="text-xs font-semibold text-[var(--pt-muted)]">
                        {t(categoryLabel(post.category))} · {date(post.at)}
                    </p>
                    <h1 className="font-display text-3xl font-extrabold">{post.title}</h1>
                    <p className="whitespace-pre-line">{post.body}</p>
                    <div className="rounded-2xl border bg-[var(--pt-surface)] p-4 text-sm">
                        <p className="font-semibold">{t('How to reach them')}</p>
                        {post.contact ? <p className="mt-1">{post.contact}</p> : <p className="mt-1 text-[var(--pt-muted)]">{t('Log in to see how to reach them.')}</p>}
                    </div>
                </article>

                <HelpNotice />

                {post.mine && post.status === 'open' && !post.asked && (
                    <Button variant="outline" size="sm" onClick={() => router.post(`/help/${post.id}/close`, {}, { preserveScroll: true })}>
                        {t('This is solved. Close it.')}
                    </Button>
                )}

                {(post.status === 'open' || comments.length > 0) && (
                    <section aria-labelledby="com-h" className="space-y-3">
                        <h2 id="com-h" className="font-display text-xl font-bold">
                            {t('Comments')}
                        </h2>
                        {comments.length === 0 ? (
                            <p className="text-sm text-[var(--pt-muted)]">{t('No comments yet.')}</p>
                        ) : (
                            <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                                {comments.map((c) => (
                                    <li key={c.id} className="space-y-2 px-4 py-3">
                                        {c.text && <p className="whitespace-pre-line">{c.text}</p>}
                                        {c.cards.map((card) => (
                                            <ProductCard key={card.id} card={card} />
                                        ))}
                                        <p className="text-xs text-[var(--pt-muted)]">{dateTime(c.at)}</p>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {canComment ? (
                            <form
                                className="space-y-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(`/help/${post.id}/comments`, { comment: text }, { preserveScroll: true, onSuccess: () => setText('') });
                                }}
                            >
                                <label htmlFor="help-comment" className="text-sm font-medium">
                                    {t('Write a comment, or paste a link to one of our products')}
                                </label>
                                <textarea id="help-comment" rows={2} maxLength={600} value={text} onChange={(e) => setText(e.target.value)} className="w-full rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3" />
                                <Button type="submit" disabled={text.trim().length < 2}>
                                    {t('Comment')}
                                </Button>
                            </form>
                        ) : post.status === 'open' ? (
                            <p className="text-sm text-[var(--pt-muted)]">
                                <Link href={route('login')} className="font-semibold underline underline-offset-4">
                                    {t('Log in')}
                                </Link>{' '}
                                {t('to comment.')}
                            </p>
                        ) : null}
                    </section>
                )}

                {!post.mine && post.status === 'open' && (
                    <section className="border-t pt-4 text-sm">
                        {post.reported ? (
                            <p className="text-[var(--pt-muted)]">{t('You reported this post. Thank you.')}</p>
                        ) : reporting ? (
                            <form
                                className="flex flex-wrap items-end gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    router.post(`/help/${post.id}/report`, { reason }, { preserveScroll: true, onSuccess: () => setReporting(false) });
                                }}
                            >
                                <label className="min-w-48 flex-1">
                                    <span className="text-xs text-[var(--pt-muted)]">{t('What is wrong with it?')}</span>
                                    <input value={reason} maxLength={200} onChange={(e) => setReason(e.target.value)} className="mt-1 h-10 w-full rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] px-3" />
                                </label>
                                <Button type="submit" size="sm">
                                    {t('Report')}
                                </Button>
                            </form>
                        ) : (
                            <button type="button" className="underline underline-offset-4" onClick={() => (signedIn ? setReporting(true) : router.visit(route('login')))}>
                                {t('Report this post')}
                            </button>
                        )}
                    </section>
                )}
            </div>
        </PublicLayout>
    );

}
