import { Button } from '@/components/ui/button';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { noticeText, preorderState, type PreorderNotice } from '@/lib/preorder';
import { Head, Link, router } from '@inertiajs/react';

interface Row {
    id: number;
    status: string;
    late: boolean;
    due_on: string | null;
    days: number;
    name: string;
    quantity: number;
    unit: string | null;
    advance: number;
    open: boolean;
}

interface Props {
    items: Row[];
    notices: PreorderNotice[];
    home: string;
}

export default function Preorders({ items, notices, home }: Props) {
    const { t, num, money, date } = useI18n();
    const unit = (u: string | null) => t(u === 'box' ? 'Box' : u === 'strip' ? 'Strip' : 'Piece');

    return (
        <PublicLayout>
            <Head title={t('Your pre-orders')} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex items-baseline justify-between gap-3">
                    <h1 className="font-display text-3xl font-extrabold">{t('Your pre-orders')}</h1>
                    <Link href={home} className="text-sm font-semibold underline underline-offset-4">
                        {t('Back')}
                    </Link>
                </div>

                {notices.map((n) => (
                    <div key={n.id} role="status" className="flex items-center gap-3 rounded-2xl border border-[var(--pt-amber)]/50 bg-[var(--pt-amber)]/10 px-4 py-3">
                        <p className="flex-1 font-semibold">{noticeText(n, t)}</p>
                        <Button size="sm" variant="ghost" onClick={() => router.post(`/notices/${n.id}/read`, {}, { preserveScroll: true })}>
                            {t('Close')}
                        </Button>
                    </div>
                ))}

                {items.length === 0 ? (
                    <div className="rounded-2xl border border-dashed p-10 text-center text-sm text-[var(--pt-muted)]">
                        <p className="font-semibold text-[var(--pt-ink)]">{t('No pre-orders yet.')}</p>
                        <p className="mt-1">{t('When a medicine can be pre-ordered, you will see the button next to it.')}</p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {items.map((p) => (
                            <li key={p.id}>
                                <Link href={`/preorders/${p.id}`} className="flex flex-wrap items-center gap-x-5 gap-y-1 px-4 py-3 hover:bg-[var(--pt-sage)]">
                                    <span className="min-w-40 flex-1 font-medium">
                                        {p.name} <span className="text-sm font-normal text-[var(--pt-muted)]">· {num(p.quantity)} {unit(p.unit)}</span>
                                    </span>
                                    {p.due_on && p.open && <span className={`text-sm ${p.late ? 'font-semibold text-danger' : 'text-[var(--pt-muted)]'}`}>{date(p.due_on)}</span>}
                                    {p.advance > 0 && <span className="tabular text-sm text-[var(--pt-muted)]">{money(p.advance)}</span>}
                                    <span className={`text-sm font-semibold ${p.late ? 'text-danger' : ''}`}>{t(preorderState(p.status, p.late))}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </PublicLayout>
    );
}
