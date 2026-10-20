import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

interface Chat {
    id: number;
    number: string;
    buyer: string;
    status: string;
    total: number;
    last: string;
    last_role: string;
    last_at: string;
    slow: boolean;
    blocked: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Job chats', href: '/admin/chats' }];

export default function CustomChats({
    q,
    chats,
    found,
    blocks,
    repeat,
}: {
    q: string;
    chats: Chat[];
    found: { id: number; order_id: number; number: string; role: string; who: string | null; body: string; at: string }[];
    blocks: { order_id: number; role: string; reason: string; excerpt: string; at: string }[];
    repeat: { user_id: number; name: string | null; n: number }[];
}) {
    const { t, num, dateTime } = useI18n();
    const [term, setTerm] = useState(q);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Job chats')} />
            <div className="mx-auto w-full max-w-4xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Job chats')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Every chat between a buyer and the maker. The buyer thinks they are talking to us. A number inside a photo cannot be caught by the system, so we read.')}</p>
                </div>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/admin/chats', { q: term }, { preserveState: true });
                    }}
                    className="flex gap-2"
                >
                    <input aria-label={t('Search old chats')} value={term} onChange={(e) => setTerm(e.target.value)} placeholder={t('Search old chats')} className="h-9 flex-1 rounded-md border bg-background px-3 text-sm" />
                    <button type="submit" className="h-9 rounded-md border px-3 text-sm font-medium">
                        {t('Search')}
                    </button>
                </form>

                {q !== '' && (
                    <section className="space-y-1">
                        <h2 className="font-medium">{t('Found')}</h2>
                        {found.length === 0 ? (
                            <p className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">{t('Nothing found.')}</p>
                        ) : (
                            <ul className="divide-y rounded-md border bg-card text-sm">
                                {found.map((m) => (
                                    <li key={m.id} className="px-3 py-2">
                                        <Link href={`/admin/chats/${m.order_id}`} className="font-medium underline">
                                            {m.number}
                                        </Link>{' '}
                                        <span className="text-xs text-muted-foreground">
                                            {m.who ?? m.role} · {dateTime(m.at)}
                                        </span>
                                        <p>{m.body}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {repeat.length > 0 && (
                    <p role="alert" className="rounded-md border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
                        {t('Tried to send a number or link again and again')}: {repeat.map((r) => `${r.name ?? r.user_id} (${num(r.n)})`).join(', ')}
                    </p>
                )}

                <section className="space-y-1">
                    <h2 className="font-medium">{t('Chats')}</h2>
                    {chats.length === 0 ? (
                        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{t('No chats yet.')}</p>
                    ) : (
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {chats.map((c) => (
                                <li key={c.id}>
                                    <Link href={`/admin/chats/${c.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 hover:bg-muted/50">
                                        <span className="min-w-32 font-medium">
                                            {c.number} · {c.buyer}
                                        </span>
                                        <span className="flex-1 truncate text-muted-foreground">{c.last}</span>
                                        {c.slow && <span className="rounded bg-danger/15 px-1.5 py-0.5 text-xs font-semibold text-danger">{t('Not answered in time')}</span>}
                                        {c.blocked > 0 && <span className="rounded bg-warning/15 px-1.5 py-0.5 text-xs font-semibold text-warning">{t('Blocked: {n}', { n: num(c.blocked) })}</span>}
                                        <span className="text-xs text-muted-foreground">{dateTime(c.last_at)}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                {blocks.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="font-medium">{t('Just blocked')}</h2>
                        <ul className="divide-y rounded-md border bg-card text-sm">
                            {blocks.map((b, i) => (
                                <li key={i} className="px-3 py-2">
                                    <Link href={`/admin/chats/${b.order_id}`} className="underline">
                                        #{b.order_id}
                                    </Link>{' '}
                                    {b.role} · {b.reason === 'number' ? t('a phone number') : t('a link')} · {dateTime(b.at)}
                                    <p className="text-muted-foreground">{b.excerpt}</p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
