import { useI18n } from '@/lib/i18n';
import { Head, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { type Entry } from './controlled';

interface Props {
    entries: Entry[];
    filters: { from: string | null; to: string | null };
}

/** The register on plain paper for an inspector: no menus, black on white, prints when it opens. */
export default function ControlledPrint({ entries, filters }: Props) {
    const { t, num, dateTime, date } = useI18n();
    const brand = usePage<{ brand: { name: string } }>().props.brand;

    useEffect(() => {
        const timer = setTimeout(() => window.print(), 400);
        return () => clearTimeout(timer);
    }, []);

    return (
        <div className="mx-auto max-w-[1100px] bg-white p-6 text-black" style={{ colorScheme: 'light' }}>
            <Head title={t('Controlled register')} />
            <h1 className="text-xl font-bold">{t('Controlled register')}</h1>
            <p className="text-sm">
                {brand.name} · {filters.from && date(filters.from)} – {filters.to && date(filters.to)}
            </p>
            <table className="mt-4 w-full border-collapse text-left text-xs">
                <thead>
                    <tr className="border-y border-black">
                        {['Date', 'Memo', 'Medicine', 'Quantity', 'Buyer', 'Phone / address', 'Prescription', 'Doctor', 'Staff'].map((h) => (
                            <th key={h} className="px-1 py-1.5">
                                {t(h)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {entries.map((e) => (
                        <tr key={e.id} className="border-b border-gray-400 align-top">
                            <td className="px-1 py-1">{dateTime(e.when)}</td>
                            <td className="px-1 py-1 font-mono">
                                {e.memo}
                                {e.cancelled && ` (${t('Memo cancelled')})`}
                            </td>
                            <td className="px-1 py-1">{e.medicine}</td>
                            <td className="px-1 py-1">
                                {num(e.quantity)} {t(e.unit === 'box' ? 'Box' : e.unit === 'strip' ? 'Strip' : 'Piece')}
                            </td>
                            <td className="px-1 py-1">{e.buyer_name}</td>
                            <td className="px-1 py-1">{[e.buyer_phone, e.buyer_address].filter(Boolean).join(', ')}</td>
                            <td className="px-1 py-1">{e.prescription_no}</td>
                            <td className="px-1 py-1">{e.doctor_name}</td>
                            <td className="px-1 py-1">{e.staff}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
