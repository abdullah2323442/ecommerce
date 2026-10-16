import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { preorderState } from '@/lib/preorder';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface Props {
    preorder: {
        id: number;
        status: string;
        late: boolean;
        open: boolean;
        due_on: string | null;
        days: number;
        advance: number;
        advance_state: string;
        total: number;
        reply_note: string | null;
        cancel_note: string | null;
        order: { id: number; number: string | null; token: string | null } | null;
        lines: { id: number; name: string; strength: string | null; company: string | null; unit: string; quantity: number; received: number | null; unit_price: number }[];
    };
    home: string;
}

/** The draft memo: what was asked for, at today's price. It stays a draft until the goods arrive and the real quantities are written in. */
export default function PreorderShow({ preorder: p, home }: Props) {
    const { t, num, money, date } = useI18n();
    const page = usePage();
    const errors = page.props.errors as Record<string, string>;
    const status = (page.props.flash as { status?: string } | undefined)?.status;
    const unit = (u: string) => t(u === 'box' ? 'Box' : u === 'strip' ? 'Strip' : 'Piece');

    return (
        <PublicLayout>
            <Head title={t('Pre-order')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href="/preorders" className="text-sm font-semibold underline underline-offset-4">
                    {t('Your pre-orders')}
                </Link>
                {status && <p className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-800">{status}</p>}
                <InputError message={errors.preorder} />

                <header className="space-y-1">
                    <h1 className="font-display text-3xl font-extrabold">{t('Pre-order')}</h1>
                    <p className={`font-semibold ${p.late ? 'text-red-700' : ''}`}>
                        {t(preorderState(p.status, p.late))}
                        {p.due_on && p.open && ` · ${t('Expected by')} ${date(p.due_on)}`}
                    </p>
                </header>

                {p.late && (
                    <p role="alert" className="rounded-2xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm">
                        {t('This is past the time we promised. You can cancel it, and any advance you paid comes back.')}
                    </p>
                )}
                {p.status === 'declined' && p.reply_note && <p className="rounded-2xl border px-4 py-3 text-sm">{p.reply_note}</p>}
                {p.status === 'cancelled' && p.cancel_note && (
                    <p role="status" className="rounded-2xl border px-4 py-3 text-sm">
                        {t('Sorry, this could not be supplied.')} {p.cancel_note}
                    </p>
                )}

                <section aria-labelledby="draft-h" className="space-y-2 rounded-2xl border bg-[var(--pt-surface)] p-4">
                    <h2 id="draft-h" className="font-display text-xl font-bold">
                        {p.status === 'fulfilled' ? t('What arrived') : t('Draft memo')}
                    </h2>
                    {p.status !== 'fulfilled' && <p className="text-sm text-[var(--pt-muted)]">{t('This is a draft. It becomes your real order when the goods arrive and we write in how much came.')}</p>}
                    <ul className="divide-y">
                        {p.lines.map((l) => (
                            <li key={l.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                                <div className="min-w-40 flex-1">
                                    <p className="font-semibold">
                                        {l.name} <span className="text-sm font-normal text-[var(--pt-muted)]">{l.strength}</span>
                                    </p>
                                    <p className="text-sm text-[var(--pt-muted)]">{l.company}</p>
                                </div>
                                <span className="text-sm">
                                    {l.received !== null ? `${num(l.received)} / ${num(l.quantity)}` : num(l.quantity)} {unit(l.unit)}
                                </span>
                                <span className="tabular text-sm text-[var(--pt-muted)]">{money(l.unit_price)}</span>
                            </li>
                        ))}
                    </ul>
                    {p.status !== 'fulfilled' && (
                        <p className="flex justify-between border-t pt-2 font-display text-lg font-bold">
                            <span>{t('Expected total')}</span>
                            <span className="tabular">{money(p.total)}</span>
                        </p>
                    )}
                    {p.advance > 0 && (
                        <p className="text-sm text-[var(--pt-muted)]">
                            {t('Advance')}: {money(p.advance)} · {t(p.advance_state === 'promised' ? 'To be paid' : p.advance_state === 'held' ? 'Received' : p.advance_state === 'applied' ? 'Used on your order' : p.advance_state === 'refund_due' ? 'To be returned' : p.advance_state === 'refunded' ? 'Returned' : 'Kept')}
                        </p>
                    )}
                    {p.status !== 'fulfilled' && <p className="text-xs text-[var(--pt-muted)]">{t('The price may change by the time it arrives.')}</p>}
                </section>

                {p.order && (
                    <Link
                        href={p.order.token ? `/orders/${p.order.token}` : home}
                        className="inline-block rounded-full bg-[var(--pt-deep)] px-5 py-2.5 text-sm font-semibold text-white"
                    >
                        {t('See your order')}
                    </Link>
                )}

                {p.open && (
                    <Button variant="outline" onClick={() => confirm(t('Cancel this pre-order?')) && router.post(`/preorders/${p.id}/cancel`)}>
                        {t('Cancel pre-order')}
                    </Button>
                )}
            </div>
        </PublicLayout>
    );
}
