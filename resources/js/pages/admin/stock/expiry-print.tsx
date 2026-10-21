import { useI18n } from '@/lib/i18n';
import { Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

interface Batch {
    lot_id: number;
    brand_name: string;
    strength: string | null;
    units: number;
    value: number;
    expiry_date: string | null;
}

interface Props {
    groups: { company: string; batches: Batch[] }[];
    printed_on: string;
}

/** The goods to hand back to each company, on plain paper: no menus, black on white, prints when it opens. */
export default function ExpiryPrint({ groups, printed_on }: Props) {
    const { t, num, money, date } = useI18n();
    const brand = usePage<{ brand: { name: string } }>().props.brand;

    useEffect(() => {
        const timer = setTimeout(() => window.print(), 400);
        return () => clearTimeout(timer);
    }, []);

    return (
        <div className="mx-auto max-w-[900px] bg-white p-6 text-black" style={{ colorScheme: 'light' }}>
            <Head title={t('Return to company')} />
            <h1 className="text-xl font-bold">{t('Return to company')}</h1>
            <p className="text-sm">
                {brand.name} · {date(printed_on)}
            </p>
            {groups.length === 0 && <p className="mt-6 text-sm">{t('Nothing is waiting to be handed back.')}</p>}
            {groups.map((g) => (
                <section key={g.company} className="mt-6 break-inside-avoid">
                    <h2 className="text-base font-semibold">{g.company}</h2>
                    <table className="mt-2 w-full border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-y border-black">
                                <th className="px-1 py-1.5">{t('Medicine')}</th>
                                <th className="px-1 py-1.5">{t('Expired on')}</th>
                                <th className="px-1 py-1.5 text-right">{t('Units')}</th>
                                <th className="px-1 py-1.5 text-right">{t('Cost')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {g.batches.map((b) => (
                                <tr key={b.lot_id} className="border-b border-gray-400">
                                    <td className="px-1 py-1">
                                        {b.brand_name} {b.strength}
                                    </td>
                                    <td className="px-1 py-1">{b.expiry_date ? date(b.expiry_date) : '—'}</td>
                                    <td className="px-1 py-1 text-right tabular-nums">{num(b.units)}</td>
                                    <td className="px-1 py-1 text-right tabular-nums">{money(b.value)}</td>
                                </tr>
                            ))}
                            <tr className="font-semibold">
                                <td className="px-1 py-1.5" colSpan={2}>
                                    {t('Total')}
                                </td>
                                <td className="px-1 py-1.5 text-right tabular-nums">{num(g.batches.reduce((n, b) => n + b.units, 0))}</td>
                                <td className="px-1 py-1.5 text-right tabular-nums">{money(g.batches.reduce((n, b) => n + b.value, 0))}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p className="mt-8 text-xs">
                        {t('Received by')}: ____________________ &nbsp; {t('Date')}: ______________
                    </p>
                </section>
            ))}
        </div>
    );
}
