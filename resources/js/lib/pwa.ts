import { useSyncExternalStore } from 'react';

/**
 * The installed app on a phone: register the service worker once, notice when a newer build is waiting, and keep the browser's "install" offer until the
 * person wants it. Every part works without the others, and a browser that has none of it simply shows the website as before.
 */

interface InstallEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

interface State {
    updateReady: boolean;
    canInstall: boolean;
    installed: boolean;
}

let state: State = { updateReady: false, canInstall: false, installed: false };
const listeners = new Set<() => void>();
let started = false;
let waiting: ServiceWorker | null = null;
let offer: InstallEvent | null = null;

function set(next: Partial<State>) {
    state = { ...state, ...next };
    listeners.forEach((l) => l());
}

export function startPwa(): void {
    if (started || typeof window === 'undefined') return;
    started = true;

    set({ installed: window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true });

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        offer = e as InstallEvent;
        set({ canInstall: true });
    });
    window.addEventListener('appinstalled', () => {
        offer = null;
        set({ canInstall: false, installed: true });
    });

    if (!('serviceWorker' in navigator)) return;

    // A new worker is installed and waiting while an older one still runs the page: that is an update.
    const watch = (reg: ServiceWorkerRegistration) => {
        const check = (w: ServiceWorker | null) => {
            if (w && w.state === 'installed' && navigator.serviceWorker.controller) {
                waiting = w;
                set({ updateReady: true });
            }
        };
        check(reg.waiting);
        reg.addEventListener('updatefound', () => {
            const w = reg.installing;
            w?.addEventListener('statechange', () => check(w));
        });
    };

    navigator.serviceWorker
        .register('/sw.js', { scope: '/' })
        .then((reg) => {
            watch(reg);
            // Look for a newer build now and then while the app stays open.
            setInterval(() => reg.update().catch(() => undefined), 60 * 60 * 1000);
        })
        .catch(() => undefined);

    let reloading = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) return;
        reloading = true;
        window.location.reload();
    });
}

/** The person agreed to the update: the waiting build takes over and the page reloads. */
export function applyUpdate(): void {
    waiting?.postMessage('skip-waiting');
}

export async function installApp(): Promise<void> {
    if (!offer) return;
    await offer.prompt();
    await offer.userChoice.catch(() => undefined);
    offer = null;
    set({ canInstall: false });
}

export function usePwa(): State {
    return useSyncExternalStore(
        (cb) => {
            listeners.add(cb);

            return () => listeners.delete(cb);
        },
        () => state,
        () => state,
    );
}

/** An iPhone or iPad browser: it has no install button, so the person is shown how to add the page to the home screen. */
export function isIos(): boolean {
    if (typeof navigator === 'undefined') return false;

    return /iphone|ipad|ipod/i.test(navigator.userAgent) && !(window as Window & { MSStream?: unknown }).MSStream;
}
