/**
 * Notifications on this phone or browser (spec ch 23): ask once, hand the browser's address to the site, and the site can reach the device when it is closed.
 * Every step can fail quietly: a browser without push, a person who says no, a site where the owner has not switched it on.
 */
export type PushResult = 'on' | 'off' | 'denied' | 'unsupported' | 'error';

const token = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');

export const pushSupported = () => typeof window !== 'undefined' && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

/** The public key arrives as URL-safe base64; the browser wants raw bytes. */
function bytes(key: string): Uint8Array<ArrayBuffer> {
    const pad = '='.repeat((4 - (key.length % 4)) % 4);
    const raw = atob((key + pad).replace(/-/g, '+').replace(/_/g, '/'));
    const out = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);

    return out;
}

async function send(method: 'POST' | 'DELETE', body: unknown): Promise<boolean> {
    try {
        const res = await fetch('/push/subscribe', {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
        });

        return res.ok;
    } catch {
        return false;
    }
}

/** Is this device already getting notifications? */
export async function pushState(): Promise<PushResult> {
    if (!pushSupported()) return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';

    try {
        const reg = await navigator.serviceWorker.ready;

        return (await reg.pushManager.getSubscription()) ? 'on' : 'off';
    } catch {
        return 'error';
    }
}

export async function enablePush(publicKey: string): Promise<PushResult> {
    if (!pushSupported()) return 'unsupported';

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return 'denied';

    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes(publicKey) }));

        return (await send('POST', sub.toJSON())) ? 'on' : 'error';
    } catch {
        return 'error';
    }
}

export async function disablePush(): Promise<PushResult> {
    if (!pushSupported()) return 'unsupported';

    try {
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.getSubscription();

        if (sub) {
            await send('DELETE', { endpoint: sub.endpoint });
            await sub.unsubscribe();
        }

        return 'off';
    } catch {
        return 'error';
    }
}
