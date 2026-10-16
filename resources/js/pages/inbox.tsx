import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { noticeText, type PreorderNotice } from '@/lib/preorder';
import { Head, Link, router } from '@inertiajs/react';

interface Props {
    messages: (PreorderNotice & { importance: string; read: boolean; when: string; link: string | null })[];
    home: string;
}

/** The customer's inbox: urgent messages first, then the newest. The same messages can also come by email or SMS, as the owner decides. */
export default function Inbox({ messages, home }: Props) {
    const { t, dateTime } = useI18n();
    const unread = messages.filter((m) => !m.read).length;

    return (
        <PublicLayout>
            <Head title={t('Your messages')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Your messages')}</h1>
                    <div className="flex items-center gap-4">
                        {unread > 0 && (
                            <Button size="sm" variant="outline" onClick={() => router.post('/inbox/read-all', {}, { preserveScroll: true })}>
                                {t('Mark all as read')}
                            </Button>
                        )}
                        <Link href={home} className="text-sm font-semibold underline underline-offset-4">
                            {t('Back')}
                        </Link>
                    </div>
                </div>

                {messages.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-10 text-center text-sm text-[var(--pt-muted)]">{t('No messages yet. We will tell you here when something happens to your order.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {messages.map((m) => {
                            const text = noticeText(m, t);
                            const inner = (
                                <>
                                    <span className={`mt-1.5 size-2 shrink-0 rounded-full ${m.read ? 'bg-transparent' : 'bg-[var(--pt-amber)]'}`} aria-hidden />
                                    <span className="min-w-0 flex-1">
                                        <span className={`block ${m.read ? '' : 'font-semibold'}`}>{text}</span>
                                        <span className="text-xs text-[var(--pt-muted)]">{dateTime(m.when)}</span>
                                    </span>
                                    {m.importance === 'urgent' && <span className="rounded bg-red-500/15 px-1.5 py-0.5 text-xs font-semibold text-red-700">{t('Important')}</span>}
                                </>
                            );

                            return (
                                <li key={m.id}>
                                    {m.link ? (
                                        <Link href={m.link} className="flex items-start gap-3 px-4 py-3 hover:bg-[var(--pt-sage)]" onClick={() => !m.read && router.post(`/notices/${m.id}/read`, {}, { preserveScroll: true, preserveState: true })}>
                                            {inner}
                                        </Link>
                                    ) : (
                                        <div className="flex items-start gap-3 px-4 py-3">{inner}</div>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
