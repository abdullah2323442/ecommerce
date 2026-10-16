import { kv, sales, type Copy, type Sale } from '@/lib/offline-store';

const token = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');

const headers = () => ({ Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token(), 'X-Requested-With': 'XMLHttpRequest' });

/** Fetch a fresh copy of the list and prices while the line is up. Returns it, or null with no line. */
export async function refreshCopy(): Promise<Copy | null> {
    try {
        const res = await fetch('/admin/counter/offline/snapshot', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!res.ok) return null;
        const copy = (await res.json()) as Copy;
        await kv.set('copy', copy);

        return copy;
    } catch {
        return null;
    }
}

export const savedCopy = () => kv.get<Copy>('copy');

export interface Outcome {
    sent: number;
    refused: number;
    offline: boolean;
}

/** Send the waiting sales. Each is answered with its real memo number, or why it was not taken; a refused sale stays on the device. */
export async function sendWaiting(): Promise<Outcome> {
    const waiting = (await sales.all()).filter((s) => s.status === 'pending');
    if (waiting.length === 0) return { sent: 0, refused: 0, offline: false };

    let sent = 0;
    let refused = 0;

    for (let i = 0; i < waiting.length; i += 20) {
        const batch = waiting.slice(i, i + 20);
        let res: Response;
        try {
            res = await fetch('/admin/counter/offline/sync', {
                method: 'POST',
                headers: headers(),
                credentials: 'same-origin',
                body: JSON.stringify({ sales: batch.map((s) => ({ ref: s.ref, at: s.at, lines: s.lines.map((l) => ({ id: l.id, unit: l.unit, qty: l.qty })), discount: s.discount, method: s.method, paid: s.paid })) }),
            });
        } catch {
            return { sent, refused, offline: true };
        }

        // Signed out, or the server had a problem: nothing is lost, it waits for the next try.
        if (!res.ok) return { sent, refused, offline: res.status >= 500 || res.status === 0 };

        const { results } = (await res.json()) as { results: { ref: string; status: 'synced' | 'refused'; number?: string | null; reason?: string; code?: Sale['code']; name?: string | null; flags?: Sale['flags'] }[] };
        for (const r of results) {
            const sale = batch.find((s) => s.ref === r.ref);
            if (!sale) continue;
            await sales.put({ ...sale, status: r.status, number: r.number ?? null, reason: r.reason, code: r.code, name: r.name, flags: r.flags });
            r.status === 'synced' ? sent++ : refused++;
        }
    }

    return { sent, refused, offline: false };
}
