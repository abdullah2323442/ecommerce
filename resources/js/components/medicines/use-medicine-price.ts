import { useI18n } from '@/lib/i18n';
import { type MedicineSummary, type Unit } from './types';

export interface UnitPrice {
    unit: Unit;
    /** Formatted taka, e.g. ৳৬১২ */
    amount: string;
    /** "Box", "Strip", "Piece" or the stored volume ("100 ml") for bottles. */
    label: string;
    /** What the unit holds: "10 × 10", "10 pieces". Empty when the data does not say. */
    contents: string;
}

/**
 * Every unit the backend has a printed price for, in box → strip → piece order. Values come straight
 * from the server (MRP per box/strip/piece); nothing is computed here except number formatting.
 */
export function useMedicineUnits() {
    const { t, num, money } = useI18n();

    return (m: MedicineSummary): UnitPrice[] => {
        const out: UnitPrice[] = [];

        if (m.packaging_type === 'box' && m.mrp_box) {
            out.push({
                unit: 'box',
                amount: money(m.mrp_box),
                label: t('Box'),
                contents: m.strips_per_box && m.pieces_per_strip ? `${num(m.strips_per_box)} × ${num(m.pieces_per_strip)}` : '',
            });
        }
        if (m.mrp_strip && m.pieces_per_strip) {
            out.push({ unit: 'strip', amount: money(m.mrp_strip), label: t('Strip'), contents: t('{n} pieces', { n: num(m.pieces_per_strip) }) });
        }
        if (m.mrp_piece) {
            out.push({ unit: 'piece', amount: money(m.mrp_piece), label: m.volume ?? t('Piece'), contents: '' });
        }

        return out;
    };
}

/** The price people expect first: per box for boxed medicines, otherwise per piece/bottle. */
export function useMedicinePrice() {
    const unitsOf = useMedicineUnits();

    return (m: MedicineSummary): UnitPrice | null => unitsOf(m)[0] ?? null;
}
