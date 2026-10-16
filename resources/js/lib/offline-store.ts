/**
 * What the counter device keeps for itself (spec ch 8, "Working without internet"): a copy of the medicine list and prices, and the
 * sales made while the line was down. IndexedDB, because the list is big and the sales must survive a closed tab.
 */

export interface CopyMedicine {
    id: number;
    n: string;
    s: string | null;
    f: string | null;
    c: string | null;
    p: 'box' | 'pcs';
    sb: number | null;
    pp: number | null;
    mb: number | null;
    ms: number | null;
    mp: number | null;
    ctl?: 1;
    ml?: number;
    st?: [Unit, number, 'p' | 't', number][];
}

export type Unit = 'box' | 'strip' | 'piece';

export interface Copy {
    taken_at: string;
    limit: number | null;
    shop: { name: string; phone: string | null; address: string | null };
    methods: string[];
    medicines: CopyMedicine[];
    codes: Record<string, number>;
}

export interface SaleLine {
    id: number;
    name: string;
    unit: Unit;
    qty: number;
    price: number;
}

export interface Sale {
    ref: string;
    at: string;
    lines: SaleLine[];
    discount: number;
    method: string;
    paid: number | null;
    total: number;
    status: 'pending' | 'synced' | 'refused';
    number?: string | null;
    reason?: string;
    code?: 'unknown' | 'controlled' | 'unit' | 'other';
    name?: string | null;
    flags?: { out_of_stock: string[]; loss: string[]; discount_reduced: boolean };
}

const DB = 'aqua-counter';

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB, 1);
        req.onupgradeneeded = () => {
            req.result.createObjectStore('kv');
            req.result.createObjectStore('sales', { keyPath: 'ref' });
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function run<T>(store: 'kv' | 'sales', mode: IDBTransactionMode, work: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await open();

    return new Promise((resolve, reject) => {
        const tx = db.transaction(store, mode);
        const req = work(tx.objectStore(store));
        tx.oncomplete = () => {
            db.close();
            resolve(req.result);
        };
        tx.onerror = () => reject(tx.error);
    });
}

export const kv = {
    get: <T>(key: string) => run<T | undefined>('kv', 'readonly', (s) => s.get(key)),
    set: (key: string, value: unknown) => run('kv', 'readwrite', (s) => s.put(value, key)),
};

export const sales = {
    all: async (): Promise<Sale[]> => (await run<Sale[]>('sales', 'readonly', (s) => s.getAll())).sort((a, b) => a.at.localeCompare(b.at)),
    put: (sale: Sale) => run('sales', 'readwrite', (s) => s.put(sale)),
    remove: (ref: string) => run('sales', 'readwrite', (s) => s.delete(ref)),
};

/** A number for a sale made here, which the shop's own memo number replaces after sending. Like T-K3F-0007. */
export async function nextTempNumber(): Promise<string> {
    let device = await kv.get<string>('device');
    if (!device) {
        device = Math.random().toString(36).slice(2, 5).toUpperCase();
        await kv.set('device', device);
    }
    const n = ((await kv.get<number>('seq')) ?? 0) + 1;
    await kv.set('seq', n);

    return `T-${device}-${String(n).padStart(4, '0')}`;
}

// ---------------------------------------------------------------- prices, the same sums as the server

export const money2 = (n: number) => Math.round((n + 1e-9) * 100) / 100;

export function unitMrp(m: CopyMedicine, unit: Unit): number | null {
    return unit === 'box' ? m.mb : unit === 'strip' ? m.ms : m.mp;
}

export function unitsOf(m: CopyMedicine): Unit[] {
    return (m.p === 'box' ? ['box', 'strip', 'piece'] : ['piece']).filter((u) => (unitMrp(m, u as Unit) ?? 0) > 0) as Unit[];
}

/** The printed price, less the step that the quantity reaches (spec ch 4). */
export function priceFor(m: CopyMedicine, unit: Unit, qty: number): number {
    const mrp = unitMrp(m, unit) ?? 0;
    const step = (m.st ?? []).filter(([u, min]) => u === unit && qty >= min).sort((a, b) => b[1] - a[1])[0];
    if (!step) return mrp;

    return money2(Math.max(0, step[2] === 'p' ? mrp - (mrp * step[3]) / 100 : mrp - step[3]));
}

export function totals(lines: SaleLine[], discount: number) {
    const subtotal = money2(lines.reduce((sum, l) => sum + money2(l.price * l.qty), 0));
    const off = money2((subtotal * discount) / 100);

    return { subtotal, off, total: money2(subtotal - off) };
}

/** The biggest discount this sale may carry: the staff limit, and the thin-margin ceiling of any medicine on it (loss protection). */
export function maxDiscount(limit: number | null, medicines: (CopyMedicine | undefined)[]): number {
    return medicines.reduce<number>((max, m) => (m?.ml !== undefined ? Math.min(max, m.ml) : max), limit ?? 100);
}
