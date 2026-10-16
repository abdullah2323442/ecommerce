import { refreshCopy, savedCopy } from '@/lib/offline-sync';
import { useEffect, useState } from 'react';

const HOURS = 6;

/**
 * Makes this device ready for the day the internet goes down: keeps the offline counter page and a copy of the medicine list.
 * Call it on the counter screens; it does its work quietly in the background and says when the copy was taken.
 */
export function useOfflineReady(): { ready: boolean; takenAt: string | null } {
    const [takenAt, setTakenAt] = useState<string | null>(null);

    useEffect(() => {
        let alive = true;

        (async () => {
            // The page is kept by a small service worker. Where a browser will not run one, the list and the sales are still kept.
            try {
                if ('serviceWorker' in navigator) {
                    const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
                    const worker = reg.active ?? (await Promise.race([navigator.serviceWorker.ready, new Promise<undefined>((r) => setTimeout(r, 5000))]))?.active;
                    worker?.postMessage('keep-offline-page');
                }
            } catch {
                /* no service worker here */
            }

            try {
                const have = await savedCopy();
                const old = !have || Date.now() - new Date(have.taken_at).getTime() > HOURS * 3600 * 1000;
                const copy = old ? await refreshCopy() : have;
                if (alive && (copy ?? have)) setTakenAt((copy ?? have)!.taken_at);
            } catch {
                /* the copy could not be kept on this device */
            }
        })();

        return () => {
            alive = false;
        };
    }, []);

    return { ready: takenAt !== null, takenAt };
}
