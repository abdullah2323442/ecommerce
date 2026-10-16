import { Button } from '@/components/ui/button';
import { useI18n } from '@/lib/i18n';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

interface Receipt {
    kind: string;
    title: string;
    number: string;
    date: string;
    business: { name: string; address: string | null; phone: string | null };
    powered_by?: string;
    sold_by?: string;
    buyer: { name: string | null; phone: string | null; address: string | null; player?: string };
    lines: { name: string; qty: number; unit: string | null; price: number; total: number }[];
    steps: { label: string; amount: number }[];
    total: number;
    payment: { method: string; status: string };
    notes: string[];
}

type Width = 'a4' | '80' | '58';

/** A clean receipt that prints on A4 or on a small receipt printer (spec ch 22). Every step is on its own line. */
export default function ReceiptPage({ receipt: r }: { receipt: Receipt }) {
    const { t, money, num, dateTime } = useI18n();
    const [width, setWidth] = useState<Width>('a4');
    const narrow = width !== 'a4';

    return (
        <>
            <Head title={`${r.title} ${r.number}`} />
            {/* The printer paper is chosen here; the screen shows the same width so what you see is what prints. */}
            {narrow && <style>{`@page { size: ${width}mm auto; margin: 2mm; }`}</style>}
            <div className="min-h-screen bg-neutral-100 p-4 print:bg-white print:p-0">
                <div className="mx-auto mb-3 flex max-w-2xl flex-wrap items-center justify-between gap-2 print:hidden">
                    <div role="group" aria-label={t('Paper')} className="flex gap-1 text-sm">
                        {(['a4', '80', '58'] as const).map((w) => (
                            <button key={w} type="button" aria-pressed={width === w} onClick={() => setWidth(w)} className={`rounded-full border bg-white px-3 py-1 ${width === w ? 'border-black font-semibold' : ''}`}>
                                {w === 'a4' ? 'A4' : `${w} mm`}
                            </button>
                        ))}
                    </div>
                    <Button onClick={() => window.print()}>{t('Print')}</Button>
                </div>

                <article className={`mx-auto bg-white text-black shadow print:shadow-none ${narrow ? 'p-2 text-[11px] leading-tight' : 'max-w-2xl p-8 text-sm'}`} style={narrow ? { width: `${width}mm` } : undefined}>
                    <header className={`border-b pb-3 ${narrow ? 'text-center' : ''}`}>
                        <h1 className={`font-bold ${narrow ? 'text-sm' : 'text-2xl'}`}>{r.business.name}</h1>
                        {r.business.address && <p>{r.business.address}</p>}
                        {r.business.phone && <p>{r.business.phone}</p>}
                    </header>

                    <section className="flex flex-wrap justify-between gap-x-6 border-b py-3">
                        <p className="font-semibold">
                            {r.title} · {r.number}
                        </p>
                        <p>{dateTime(r.date)}</p>
                    </section>

                    <section className="border-b py-3">
                        {r.buyer.name && <p className="font-medium">{r.buyer.name}</p>}
                        {r.buyer.phone && <p>{r.buyer.phone}</p>}
                        {r.buyer.player && <p>{t('Player ID')}: {r.buyer.player}</p>}
                        {r.buyer.address && <p>{r.buyer.address}</p>}
                        {r.sold_by && <p className="text-neutral-600">{t('Sold by')} {r.sold_by}</p>}
                    </section>

                    <table className="tabular my-3 w-full">
                        <tbody>
                            {r.lines.map((l, i) => (
                                <tr key={i} className="align-top">
                                    <td className="py-1 pe-2">
                                        {l.name}
                                        <span className="block text-neutral-600">
                                            {num(l.qty)}
                                            {l.unit ? ` ${l.unit}` : ''} × {money(l.price)}
                                        </span>
                                    </td>
                                    <td className="py-1 text-end">{money(l.total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <dl className="tabular space-y-0.5 border-t pt-2">
                        {r.steps.map((s, i) => (
                            <div key={i} className="flex justify-between">
                                <dt>{s.label}</dt>
                                <dd>{s.amount < 0 ? `−${money(-s.amount)}` : money(s.amount)}</dd>
                            </div>
                        ))}
                        <div className={`flex justify-between border-t pt-1 font-bold ${narrow ? 'text-sm' : 'text-lg'}`}>
                            <dt>{t('Total')}</dt>
                            <dd>{money(r.total)}</dd>
                        </div>
                    </dl>

                    <p className="mt-2">
                        {r.payment.method} · <strong>{r.payment.status}</strong>
                    </p>

                    {r.notes.map((n, i) => (
                        <p key={i} className="mt-2 text-neutral-600">
                            {n}
                        </p>
                    ))}
                    {r.powered_by && <p className="mt-4 text-center text-[10px] text-neutral-500">{r.powered_by}</p>}
                </article>
            </div>
        </>
    );
}
