import { usePortal } from '@/pages/paikari/index';
import { VoiceRecorder } from '@/components/order-extras';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PublicLayout from '@/layouts/public-layout';
import { useI18n } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface Memo {
    id: number;
    number: string;
    when: string | null;
    lines: { id: number; name: string; strength: string | null; unit: string; quantity: number; returnable: boolean; offer: boolean }[];
}

interface Props {
    memos: Memo[];
    filters: { from: string | null; to: string | null };
    days: number;
    credit: number;
    mine: { id: number; status: string; amount: number; resolution: string }[];
}

const REASONS = ['wrong_item', 'damaged', 'near_expiry', 'ordered_by_mistake', 'other'];
const reasonLabel = (r: string) =>
    ({ wrong_item: 'Wrong item', damaged: 'Damaged', near_expiry: 'Near expiry', ordered_by_mistake: 'Ordered by mistake', other: 'Other' })[r] ??
    'Other';

function MemoCard({ memo }: { memo: Memo }) {
    const { t, num, dateTime } = useI18n();
    const { base } = usePortal();
    const errors = usePage().props.errors as Record<string, string>;
    const [sel, setSel] = useState<Record<number, { unit: string; quantity: string }>>({});
    const [reasonCode, setReasonCode] = useState('damaged');
    const [reason, setReason] = useState('');
    const [resolution, setResolution] = useState('credit');
    const [voice, setVoice] = useState<File | null>(null);
    const items = Object.entries(sel)
        .filter(([, v]) => Number(v.quantity) > 0)
        .map(([id, v]) => ({ line_id: Number(id), unit: v.unit, quantity: v.quantity }));
    const any = memo.lines.some((l) => l.returnable);

    return (
        <li className="space-y-2 px-4 py-3">
            <p className="font-medium">
                {memo.number} <span className="text-sm font-normal text-[var(--pt-muted)]">{memo.when && dateTime(memo.when)}</span>
            </p>
            <ul className="space-y-1">
                {memo.lines.map((l) => (
                    <li key={l.id} className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="min-w-44 flex-1">
                            {l.name} <span className="text-[var(--pt-muted)]">{l.strength}</span> · {num(l.quantity)}{' '}
                            {t(l.unit === 'box' ? 'Box' : l.unit === 'strip' ? 'Strip' : 'Piece')}
                        </span>
                        {l.returnable ? (
                            <>
                                <span className="rounded-full bg-[var(--pt-sage)] px-2 py-0.5 text-xs font-semibold text-[var(--pt-deep)]">
                                    {t('Returnable')}
                                </span>
                                <select
                                    aria-label={t('Unit')}
                                    className="bg-background h-8 rounded-md border px-1 text-sm"
                                    value={sel[l.id]?.unit ?? l.unit}
                                    onChange={(e) => setSel((s) => ({ ...s, [l.id]: { unit: e.target.value, quantity: s[l.id]?.quantity ?? '' } }))}
                                >
                                    <option value="box">{t('Box')}</option>
                                    <option value="strip">{t('Strip')}</option>
                                    <option value="piece">{t('Piece')}</option>
                                </select>
                                <Input
                                    aria-label={t('How much')}
                                    className="h-8 w-20"
                                    type="number"
                                    min="0"
                                    step="any"
                                    placeholder={t('How much')}
                                    value={sel[l.id]?.quantity ?? ''}
                                    onChange={(e) => setSel((s) => ({ ...s, [l.id]: { unit: s[l.id]?.unit ?? l.unit, quantity: e.target.value } }))}
                                />
                            </>
                        ) : null}
                    </li>
                ))}
            </ul>
            {any && (
                <div className="space-y-2 border-t pt-2">
                    <div className="flex flex-wrap gap-2">
                        <select
                            aria-label={t('Why')}
                            value={reasonCode}
                            onChange={(e) => setReasonCode(e.target.value)}
                            className="bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            {REASONS.map((r) => (
                                <option key={r} value={r}>
                                    {t(reasonLabel(r))}
                                </option>
                            ))}
                        </select>
                        <select
                            aria-label={t('You want')}
                            value={resolution}
                            onChange={(e) => setResolution(e.target.value)}
                            className="bg-background h-9 rounded-md border px-2 text-sm"
                        >
                            <option value="credit">{t('Credit on my next memo')}</option>
                            <option value="exchange">{t('Exchange for another medicine')}</option>
                            <option value="cash">{t('Cash back (half the price)')}</option>
                        </select>
                        <Input
                            aria-label={t('Reason (required)')}
                            className="min-w-48 flex-1"
                            placeholder={t('Reason (required)')}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={500}
                        />
                    </div>
                    <div>
                        <p className="text-sm font-medium">{t('Voice note')}</p>
                        <div className="mt-1">
                            <VoiceRecorder file={voice} onChange={setVoice} />
                        </div>
                        <InputError message={errors.voice} />
                    </div>
                    <InputError message={errors.return ?? errors.reason} />
                    <Button
                        disabled={items.length === 0 || reason.trim() === ''}
                        onClick={() =>
                            router.post(
                                `${base}/returns`,
                                { invoice_id: memo.id, items, reason_code: reasonCode, reason, resolution, voice },
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setSel({});
                                        setReason('');
                                        setVoice(null);
                                    },
                                },
                            )
                        }
                    >
                        {t('Ask to return')}
                    </Button>
                </div>
            )}
        </li>
    );
}

export default function Returns({ memos, filters, days, credit, mine }: Props) {
    const { base } = usePortal();
    const { t, money } = useI18n();
    const flash = usePage().props.flash as { status?: string } | undefined;
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    return (
        <PublicLayout>
            <Head title={t('Returns')} />
            <div className="mx-auto max-w-3xl space-y-6">
                {flash?.status && (
                    <p className="rounded-md border border-emerald-600/30 bg-emerald-600/10 px-3 py-2 text-sm text-emerald-800">{flash.status}</p>
                )}
                <Link href={base} className="text-sm underline underline-offset-4">
                    {t('Back to orders')}
                </Link>
                <h1 className="font-display text-3xl font-extrabold">{t('Returns')}</h1>
                {credit > 0 && (
                    <p className="rounded-2xl border border-[var(--pt-amber)]/50 bg-[var(--pt-amber)]/10 px-4 py-3 font-semibold">
                        {t('Credit: {amount}', { amount: money(credit) })} · <span className="font-normal">{t('It comes off your next memo.')}</span>
                    </p>
                )}
                <p className="rounded-2xl bg-[var(--pt-sage)] px-4 py-3 text-sm">
                    {t(
                        'Bring the memo and the goods. Returns within {n} days of receiving. Exchange or credit is the full price you paid. Cash back is half, because we only offer exchange.',
                        { n: days },
                    )}
                </p>

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(`${base}/returns`, { from: from || undefined, to: to || undefined }, { preserveState: true });
                    }}
                >
                    <label className="text-xs text-[var(--pt-muted)]">
                        {t('From')}
                        <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" />
                    </label>
                    <label className="text-xs text-[var(--pt-muted)]">
                        {t('To')}
                        <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" />
                    </label>
                    <Button type="submit" variant="outline">
                        {t('Search by date')}
                    </Button>
                </form>

                {memos.length === 0 ? (
                    <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-[var(--pt-muted)]">{t('Nothing bought yet.')}</p>
                ) : (
                    <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)]">
                        {memos.map((m) => (
                            <MemoCard key={m.id} memo={m} />
                        ))}
                    </ul>
                )}

                {mine.length > 0 && (
                    <section className="space-y-1">
                        <h2 className="font-display text-xl font-bold">{t('Your return requests')}</h2>
                        <ul className="divide-y rounded-2xl border bg-[var(--pt-surface)] text-sm">
                            {mine.map((r) => (
                                <li key={r.id} className="flex justify-between px-4 py-2">
                                    <span>#{r.id}</span>
                                    <span className="font-semibold">
                                        {t(r.status === 'approved' ? 'Approved' : r.status === 'rejected' ? 'Declined' : 'Waiting')}
                                    </span>
                                    <span className="tabular">{money(r.amount)}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
