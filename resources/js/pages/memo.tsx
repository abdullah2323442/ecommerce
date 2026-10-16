import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { Head, router } from '@inertiajs/react';
import { Printer, Send } from 'lucide-react';

interface Row {
    name: string;
    strength: string | null;
    unit: string;
    quantity: number;
    unit_price: number;
    total: number;
    not_available: boolean;
    cold?: boolean;
}

interface Section {
    lines: Row[];
    subtotal: number;
}

interface Props {
    memo: {
        id: number;
        number: string | null;
        status: string;
        when: string | null;
        shop: string;
        customer: string | null;
        phone: string | null;
        staff: string | null;
        reprint: boolean;
        payment_method: string | null;
        offer: Section;
        regular: Section;
        discount: number;
        delivery: number;
        delivery_extra?: number;
        credit: number;
        token?: number;
        total: number;
        paid: number;
        cancelled: boolean;
        preliminary?: boolean;
    };
    policy: { days: number; cash_percent: number };
    width: '58' | '80' | 'a4';
    canPrint: boolean;
    isAdmin: boolean;
}

function Lines({ title, section }: { title: string; section: Section }) {
    const { t, num, money } = useI18n();
    if (section.lines.length === 0) return null;

    return (
        <section className="mt-3">
            <p className="border-b border-dashed pb-1 text-xs font-bold tracking-wide">{title}</p>
            <table className="w-full text-[0.8em]">
                <tbody>
                    {section.lines.map((l, i) => (
                        <tr key={i} className="align-top">
                            <td className="py-0.5 pr-1">
                                {l.name} {l.strength}
                                <br />
                                <span className="opacity-70">
                                    {num(l.quantity)} {t(l.unit === 'box' ? 'Box' : l.unit === 'strip' ? 'Strip' : 'Piece')} × {money(l.unit_price)}
                                    {l.not_available && ` · ${t('Not available')}`}
                                    {l.cold && ` · ${t('Keep refrigerated')}`}
                                </span>
                            </td>
                            <td className="py-0.5 text-right whitespace-nowrap tabular-nums">{money(l.total)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className="flex justify-between border-t border-dashed pt-1 text-[0.85em] font-semibold">
                <span>{t('Subtotal')}</span>
                <span className="tabular-nums">{money(section.subtotal)}</span>
            </p>
        </section>
    );
}

const methodName = (m: string | null) =>
    m === 'bkash'
        ? 'bKash'
        : m === 'nagad'
          ? 'Nagad'
          : m === 'rocket'
            ? 'Rocket'
            : m === 'bank'
              ? 'Bank'
              : m === 'card'
                ? 'Card'
                : m === 'due'
                  ? 'On credit (due)'
                  : 'Cash';

export default function Memo({ memo, policy, width, canPrint, isAdmin }: Props) {
    const { t, money, dateTime } = useI18n();
    const mixed = memo.offer.lines.length > 0 && memo.regular.lines.length > 0;
    const due = Math.max(0, memo.total - memo.paid);
    const digits = (memo.phone ?? '').replace(/\D/g, '');
    const wa = digits
        ? `https://wa.me/${digits.startsWith('0') ? `88${digits}` : digits}?text=${encodeURIComponent(`${memo.shop}\n${memo.number ?? ''}\n${t('Total')}: ${money(memo.total)}`)}`
        : null;
    const paper = width === 'a4' ? '210mm' : `${width}mm`;

    return (
        <div className="min-h-screen bg-neutral-100 p-4 print:bg-white print:p-0">
            <Head title={memo.number ?? t('Memo')} />
            <style>{`@media print { @page { size: ${width === 'a4' ? 'A4' : `${width}mm auto`}; margin: ${width === 'a4' ? '12mm' : '2mm'}; } }`}</style>

            {canPrint && (
                <div className="mx-auto mb-4 flex max-w-xl flex-wrap items-center justify-center gap-2 print:hidden">
                    {(['58', '80', 'a4'] as const).map((w) => (
                        <Button
                            key={w}
                            size="sm"
                            variant={w === width ? 'default' : 'outline'}
                            onClick={() => router.get(`/memo/${memo.id}`, { w }, { preserveScroll: true })}
                        >
                            {w === 'a4' ? 'A4' : `${w} mm`}
                        </Button>
                    ))}
                    {isAdmin && (
                        <Button size="sm" variant="ghost" onClick={() => router.put('/admin/print-width', { width }, { preserveScroll: true })}>
                            {t('Use this size by default')}
                        </Button>
                    )}
                    <Button
                        size="sm"
                        onClick={() =>
                            router.post(`/admin/counter/${memo.id}/printed`, {}, { preserveScroll: true, onSuccess: () => window.print() })
                        }
                    >
                        <Printer className="size-4" /> {t('Print')}
                    </Button>
                    {wa && (
                        <Button size="sm" variant="outline" asChild>
                            <a href={wa} target="_blank" rel="noreferrer">
                                <Send className="size-4" /> {t('Send on WhatsApp')}
                            </a>
                        </Button>
                    )}
                </div>
            )}
            {!canPrint && (
                <div className="mx-auto mb-4 flex max-w-xl justify-center print:hidden">
                    <Button size="sm" onClick={() => window.print()}>
                        <Printer className="size-4" /> {t('Print or save as PDF')}
                    </Button>
                </div>
            )}

            <article
                className="relative mx-auto bg-white p-4 font-mono text-[13px] leading-snug text-black shadow print:shadow-none"
                style={{ width: paper, maxWidth: '100%' }}
            >
                {memo.preliminary && (
                    <p role="note" className="rounded border border-amber-500/50 bg-amber-500/10 px-3 py-2 text-sm font-semibold print:border-black">
                        {t('Preliminary memo at the printed prices, to give you an idea. Your final memo is at your own price.')}
                    </p>
                )}
                {memo.cancelled && (
                    <p className="absolute top-1/3 left-1/2 -translate-x-1/2 -rotate-12 text-4xl font-black text-red-600/30">{t('Cancelled')}</p>
                )}
                <header className="text-center">
                    <p className="text-base font-bold">{memo.shop}</p>
                    <p className="text-xs">
                        {memo.number ?? t('Draft')} {memo.reprint && <strong>· {t('Reprint')}</strong>}
                    </p>
                    <p className="text-xs">{memo.when && dateTime(memo.when)}</p>
                </header>
                {(memo.customer || memo.phone) && (
                    <p className="mt-2 text-xs">
                        {memo.customer} {memo.phone}
                    </p>
                )}

                {mixed ? (
                    <>
                        <Lines title={t('OFFER')} section={memo.offer} />
                        <Lines title={t('Regular items')} section={memo.regular} />
                    </>
                ) : (
                    <Lines title={t('Items')} section={memo.offer.lines.length > 0 ? memo.offer : memo.regular} />
                )}

                <section className="mt-3 space-y-0.5 border-t border-dashed pt-2 text-[0.9em]">
                    {memo.discount > 0 && (
                        <p className="flex justify-between">
                            <span>{t('Discount')}</span>
                            <span className="tabular-nums">−{money(memo.discount)}</span>
                        </p>
                    )}
                    {memo.delivery > 0 && (
                        <p className="flex justify-between">
                            <span>{t('Delivery charge')}</span>
                            <span className="tabular-nums">{money(memo.delivery - (memo.delivery_extra ?? 0))}</span>
                        </p>
                    )}
                    {(memo.delivery_extra ?? 0) > 0 && (
                        <p className="flex justify-between">
                            <span>{t('Extra delivery charge')}</span>
                            <span className="tabular-nums">{money(memo.delivery_extra ?? 0)}</span>
                        </p>
                    )}
                    {(memo.token ?? 0) > 0 && (
                        <p className="flex justify-between">
                            <span>{t('Aqua Token')}</span>
                            <span className="tabular-nums">−{money(memo.token ?? 0)}</span>
                        </p>
                    )}
                    {memo.credit > 0 && (
                        <p className="flex justify-between">
                            <span>{t('Return product')}</span>
                            <span className="tabular-nums">−{money(memo.credit)}</span>
                        </p>
                    )}
                    <p className="flex justify-between border-t pt-1 text-base font-bold">
                        <span>{t('Total')}</span>
                        <span className="tabular-nums">{money(memo.total)}</span>
                    </p>
                    <p className="flex justify-between">
                        <span>{t(methodName(memo.payment_method))}</span>
                        <span className="font-semibold">{due > 0 ? t('Due {amount}', { amount: money(due) }) : t('Paid')}</span>
                    </p>
                </section>

                {memo.staff && <p className="mt-2 text-xs">{t('Served by {name}', { name: memo.staff })}</p>}
                <p className="mt-3 border-t border-dashed pt-2 text-[0.7em] leading-tight">
                    {t(
                        'Medicine returns apply within {n} days of buying, with this memo. Cash back is {p}% because we offer exchange. Offer items return at the same rule on the offer price.',
                        { n: policy.days, p: policy.cash_percent },
                    )}
                </p>
            </article>
        </div>
    );
}
