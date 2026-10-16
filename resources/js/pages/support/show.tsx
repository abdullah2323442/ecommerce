import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, useForm, usePage } from '@inertiajs/react';

interface Msg {
    id: number;
    by: 'buyer' | 'staff' | 'system';
    body: string;
    at: string;
}

interface Props {
    token: string;
    ticket: { number: string; subject: string; status: 'open' | 'answered' | 'closed'; topic: string; at: string; can_reply: boolean };
    messages: Msg[];
}

export const STATUS_LABEL: Record<string, string> = { open: 'Waiting for us', answered: 'We answered', closed: 'Closed' };

/** A request and its answers, on a page only the person with the link can open. */
export default function SupportShow({ token, ticket, messages }: Props) {
    const { t, dateTime } = useI18n();
    const page = usePage();
    const flash = (page.props.flash as { status?: string } | undefined)?.status;
    const form = useForm({ body: '' });
    const errors = form.errors as Record<string, string>;

    return (
        <PublicLayout>
            <Head title={`${ticket.number} · ${ticket.subject}`} />
            <div className="mx-auto max-w-2xl space-y-5">
                <div>
                    <p className="text-sm text-[var(--pt-muted)]">
                        {ticket.number} · {t(STATUS_LABEL[ticket.status])}
                    </p>
                    <h1 className="font-display text-2xl font-extrabold">{ticket.subject}</h1>
                </div>
                {flash && (
                    <p role="status" className="rounded-xl border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-900">
                        {flash}
                    </p>
                )}
                <ul className="space-y-3">
                    {messages.map((m) =>
                        m.by === 'system' ? (
                            <li key={m.id} className="text-center text-xs text-[var(--pt-muted)]">
                                {t(m.body)} · {dateTime(m.at)}
                            </li>
                        ) : (
                            <li key={m.id} className={`rounded-2xl border p-3 ${m.by === 'staff' ? 'bg-[var(--pt-sage)]' : 'bg-[var(--pt-surface)]'}`}>
                                <p className="text-xs font-semibold text-[var(--pt-muted)]">
                                    {m.by === 'staff' ? t('Our answer') : t('You')} · {dateTime(m.at)}
                                </p>
                                <p className="mt-1 whitespace-pre-wrap text-sm">{m.body}</p>
                            </li>
                        ),
                    )}
                </ul>
                <InputError message={errors.support} />
                {ticket.can_reply ? (
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/support/t/${token}/reply`, { preserveScroll: true, onSuccess: () => form.reset() });
                        }}
                    >
                        <label htmlFor="s-reply" className="text-sm font-medium">
                            {ticket.status === 'closed' ? t('Write again to open it') : t('Write to us')}
                        </label>
                        <textarea id="s-reply" rows={4} maxLength={2000} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} className="w-full rounded-lg border border-[var(--pt-line)] bg-[var(--pt-surface)] p-3" />
                        <InputError message={errors.body} />
                        <Button type="submit" disabled={form.processing || form.data.body.trim().length < 2}>
                            {t('Send')}
                        </Button>
                    </form>
                ) : (
                    <p className="rounded-xl border px-3 py-2 text-sm">
                        {t('This request was closed a while ago. Please open a new one.')} <a href="/support/new" className="font-semibold underline">{t('Contact us')}</a>
                    </p>
                )}
            </div>
        </PublicLayout>
    );
}
