import Flash from '@/components/admin/flash';
import { categoryLabel } from '@/components/help-bits';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Post {
    id: number;
    category: string;
    title: string;
    body: string;
    contact: string;
    status: string;
    hidden: boolean;
    by: string | null;
    at: string;
    reports: number;
    comments: { id: number; body: string; hidden: boolean; by: string | null }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Help posts', href: '/admin/help' }];

function PostCard({ p, review }: { p: Post; review?: boolean }) {
    const { t, dateTime, num } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;
    const [note, setNote] = useState('');

    return (
        <li className={`space-y-2 px-4 py-3 ${p.hidden ? 'opacity-60' : ''}`}>
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">{p.title}</p>
                <p className="text-xs text-muted-foreground">
                    {t(categoryLabel(p.category))} · {p.by} · {dateTime(p.at)}
                    {p.reports > 0 && ` · ${t('{n} reports', { n: num(p.reports) })}`}
                </p>
            </div>
            <p className="text-sm whitespace-pre-line">{p.body}</p>
            <p className="text-xs text-muted-foreground">
                {t('Contact')}: {p.contact}
            </p>
            {p.comments.length > 0 && (
                <ul className="space-y-1 rounded-md bg-muted/50 p-2 text-xs">
                    {p.comments.map((c) => (
                        <li key={c.id} className={`flex items-start justify-between gap-2 ${c.hidden ? 'line-through opacity-60' : ''}`}>
                            <span>
                                {c.by}: {c.body}
                            </span>
                            <button type="button" className="underline" onClick={() => router.put(`/admin/help/comments/${c.id}/hide`, { hidden: !c.hidden }, { preserveScroll: true })}>
                                {c.hidden ? t('Show again') : t('Hide')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {review ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" onClick={() => router.post(`/admin/help/${p.id}/approve`, {}, { preserveScroll: true })}>
                        {t('Publish')}
                    </Button>
                    <Input aria-label={t('Why not')} className="h-8 w-56" placeholder={t('Why not (needed to turn it down)')} value={note} onChange={(e) => setNote(e.target.value)} />
                    <Button size="sm" variant="outline" disabled={note.trim().length < 3} onClick={() => router.post(`/admin/help/${p.id}/reject`, { note }, { preserveScroll: true })}>
                        {t('Turn down')}
                    </Button>
                    <InputError message={errors.note} />
                </div>
            ) : (
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => router.put(`/admin/help/${p.id}/hide`, { hidden: !p.hidden }, { preserveScroll: true })}>
                        {p.hidden ? t('Show again') : t('Take down')}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => router.post(`/admin/help/${p.id}/close`, {}, { preserveScroll: true })}>
                        {t('Close')}
                    </Button>
                    <Button asChild size="sm" variant="ghost">
                        <Link href={`/help/${p.id}`}>{t('Open')}</Link>
                    </Button>
                </div>
            )}
        </li>
    );
}

function Section({ title, items, empty, review }: { title: string; items: Post[]; empty: string; review?: boolean }) {
    return (
        <section className="space-y-2">
            <h2 className="font-medium">{title}</h2>
            {items.length === 0 ? <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">{empty}</p> : <ul className="divide-y rounded-md border bg-card">{items.map((p) => <PostCard key={p.id} p={p} review={review} />)}</ul>}
        </section>
    );
}

export default function AdminHelp({ pending, reported, open }: { pending: Post[]; reported: Post[]; open: Post[] }) {
    const { t } = useI18n();
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('Help posts')} />
            <div className="mx-auto w-full max-w-3xl space-y-6 p-4">
                <div>
                    <h1 className="font-display text-2xl font-extrabold">{t('Help posts')}</h1>
                    <p className="text-sm text-muted-foreground">{t('Read each post before it is shown. Turn down spam, ads and anything that is not about work, a product or business help.')}</p>
                </div>
                <Flash />
                <InputError message={errors.post} />
                <Section title={t('Waiting to be read')} items={pending} empty={t('No post is waiting.')} review />
                <Section title={t('Reported')} items={reported} empty={t('No post has been reported.')} />
                <Section title={t('Open now')} items={open} empty={t('No post is open.')} />
            </div>
        </AppLayout>
    );
}
