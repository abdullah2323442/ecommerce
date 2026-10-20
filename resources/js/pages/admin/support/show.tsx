import Flash from '@/components/admin/flash';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useI18n } from '@/lib/i18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';

interface Msg {
    id: number;
    by: 'buyer' | 'staff' | 'system';
    who: string | null;
    body: string;
    at: string;
}

interface Props {
    ticket: {
        id: number;
        number: string;
        subject: string;
        name: string;
        phone: string;
        topic: string;
        status: 'open' | 'answered' | 'closed';
        account: boolean;
        assignee: string | null;
        assigned_to_me: boolean;
        late: boolean;
        order: { label: string; url: string | null } | null;
    };
    messages: Msg[];
}

const STATUS: Record<string, string> = { open: 'Waiting for us', answered: 'We answered', closed: 'Closed' };

export default function SupportShow({ ticket, messages }: Props) {
    const { t, dateTime } = useI18n();
    const form = useForm({ body: '' });
    const errors = form.errors as Record<string, string>;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Support tickets', href: '/admin/support' },
        { title: ticket.number, href: `/admin/support/${ticket.id}` },
    ];
    const act = (what: string, data: Record<string, string | number | boolean> = {}) => router.post(`/admin/support/${ticket.id}/${what}`, data, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${ticket.number} · ${ticket.subject}`} />
            <div className="mx-auto w-full max-w-3xl space-y-5 p-4">
                <Flash />
                <div className="space-y-1">
                    <p className="text-sm text-muted-foreground">
                        {ticket.number} · {t(STATUS[ticket.status])}
                        {ticket.late && <span className="ms-2 rounded bg-danger/15 px-1.5 text-xs font-semibold text-danger">{t('Late')}</span>}
                    </p>
                    <h1 className="text-2xl font-bold">{ticket.subject}</h1>
                    <p className="text-sm">
                        {ticket.name} · <a href={`tel:${ticket.phone}`} className="underline">{ticket.phone}</a> {ticket.account ? `· ${t('Has an account')}` : `· ${t('Guest')}`}
                        {ticket.order && (
                            <>
                                {' · '}
                                {ticket.order.url ? (
                                    <Link href={ticket.order.url} className="underline">
                                        {ticket.order.label}
                                    </Link>
                                ) : (
                                    ticket.order.label
                                )}
                            </>
                        )}
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => act('assign', { take: !ticket.assigned_to_me })}>
                        {ticket.assigned_to_me ? t('Give it back') : t('Take it')}
                    </Button>
                    {ticket.assignee && !ticket.assigned_to_me && <span className="self-center text-sm text-muted-foreground">{ticket.assignee}</span>}
                    {ticket.status === 'closed' ? (
                        <Button size="sm" variant="outline" onClick={() => act('reopen')}>
                            {t('Open again')}
                        </Button>
                    ) : (
                        <Button size="sm" variant="outline" onClick={() => window.confirm(t('Close this request?')) && act('close')}>
                            {t('Close')}
                        </Button>
                    )}
                </div>

                <ul className="space-y-3">
                    {messages.map((m) =>
                        m.by === 'system' ? (
                            <li key={m.id} className="text-center text-xs text-muted-foreground">
                                {t(m.body)} · {dateTime(m.at)}
                            </li>
                        ) : (
                            <li key={m.id} className={`rounded-md border p-3 ${m.by === 'staff' ? 'bg-muted/50' : 'bg-card'}`}>
                                <p className="text-xs font-semibold text-muted-foreground">
                                    {m.by === 'staff' ? (m.who ?? t('Staff')) : ticket.name} · {dateTime(m.at)}
                                </p>
                                <p className="mt-1 whitespace-pre-wrap text-sm">{m.body}</p>
                            </li>
                        ),
                    )}
                </ul>

                <InputError message={errors.support} />
                {ticket.status !== 'closed' && (
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/admin/support/${ticket.id}/reply`, { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <label htmlFor="a-reply" className="text-sm font-medium">
                            {t('Your answer')}
                        </label>
                        <textarea id="a-reply" rows={5} maxLength={2000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className="w-full rounded-md border bg-background p-3 text-sm" />
                        <InputError message={errors.body} />
                        <Button type="submit" disabled={form.processing || form.data.body.trim().length < 2}>
                            {t('Send to the buyer')}
                        </Button>
                    </form>
                )}
            </div>
        </AppLayout>
    );
}
