/*
 * The service worker of the installed app and of the counter (spec ch 3, 8 and 27). It does three things:
 *  - keeps the built scripts and styles, and the offline counter page, so that page opens when the line is down;
 *  - when the line is down and someone opens any counter address, shows the offline counter instead of a browser error;
 *  - when the line is down and a visitor opens a page of the shop, shows a plain "no internet" page instead of a browser error.
 * Everything else goes to the network exactly as before. A new version waits until the person agrees ("skip-waiting"), so a page is never changed under them.
 * The last line of this file is added by the server (the build's fingerprint): a new build is a new worker, which is how an update is noticed.
 */
const SHELL = 'aqua-shell-v1';
const PAGES = 'aqua-pages-v1';
const OFFLINE_PAGE = '/admin/counter/offline';

const OFFLINE_SITE = '/offline';

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            try {
                const res = await fetch(OFFLINE_SITE, { credentials: 'same-origin' });
                if (res.ok) (await caches.open(PAGES)).put(OFFLINE_SITE, res);
            } catch {
                /* no line now: the page is kept the next time */
            }
        })(),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keep = [SHELL, PAGES];
            for (const key of await caches.keys()) {
                if (key.startsWith('aqua-') && !keep.includes(key)) await caches.delete(key);
            }
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Built files have a fingerprint in their name: once kept, they never change.
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.open(SHELL).then(async (cache) => {
                const hit = await cache.match(request);
                if (hit) return hit;
                const res = await fetch(request);
                if (res.ok) cache.put(request, res.clone());
                return res;
            }),
        );
        return;
    }

    // The offline counter page itself: the newest when there is a line, the kept one when there is not.
    if (url.pathname === OFFLINE_PAGE && (request.mode === 'navigate' || request.headers.get('X-Inertia'))) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(PAGES);
                try {
                    const res = await fetch(request);
                    if (res.ok && request.mode === 'navigate') cache.put(OFFLINE_PAGE, res.clone());
                    return res;
                } catch {
                    return (await cache.match(OFFLINE_PAGE)) ?? Response.error();
                }
            })(),
        );
        return;
    }

    // A page of the shop opened with no line: a plain "no internet" page, never the browser's error. The office pages are left alone.
    if (request.mode === 'navigate' && !url.pathname.startsWith('/admin')) {
        event.respondWith(
            (async () => {
                try {
                    return await fetch(request);
                } catch {
                    const cache = await caches.open(PAGES);

                    return (await cache.match(OFFLINE_SITE)) ?? Response.error();
                }
            })(),
        );
        return;
    }

    // Any other counter address opened with no line: go to the offline counter.
    if (request.mode === 'navigate' && url.pathname.startsWith('/admin/counter')) {
        event.respondWith(
            (async () => {
                try {
                    return await fetch(request);
                } catch {
                    const cache = await caches.open(PAGES);

                    return (await cache.match(OFFLINE_PAGE)) ?? Response.error();
                }
            })(),
        );
    }
});

// The page asks for its own page to be kept while the line is up.
self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') {
        self.skipWaiting();

        return;
    }

    if (event.data === 'keep-offline-page') {
        event.waitUntil(
            (async () => {
                try {
                    const res = await fetch(OFFLINE_PAGE, { credentials: 'same-origin' });
                    if (res.ok) (await caches.open(PAGES)).put(OFFLINE_PAGE, res);
                } catch {
                    /* no line: the copy already kept is used */
                }
            })(),
        );
    }
});
