/**
 * Visitor statistics (spec ch 27): tells the Meta Pixel and Google Analytics what a visitor did, only when the owner switched them on in Connections.
 * The ids arrive with every page (the `analytics` shared prop, empty for staff and for the admin screens); with no ids this does nothing.
 */
export type AnalyticsIds = { meta_pixel: string | null; ga4: string | null } | null | undefined;

interface Thing {
    id?: string | number;
    name?: string;
    value?: number;
    quantity?: number;
    query?: string;
    transaction?: string;
}

type Event = 'PageView' | 'ViewContent' | 'AddToCart' | 'Search' | 'InitiateCheckout' | 'Purchase';

const win = window as unknown as { fbq?: (...a: unknown[]) => void; gtag?: (...a: unknown[]) => void };
let ids: AnalyticsIds = null;

const dontTrack = () => navigator.doNotTrack === '1';

/** Called on every page with the shared prop. */
export function setAnalytics(next: AnalyticsIds) {
    ids = next;
}

export function track(event: Event, thing: Thing = {}) {
    if (!ids || dontTrack()) return;

    const currency = 'BDT';
    const item = thing.id !== undefined ? [{ item_id: String(thing.id), item_name: thing.name, quantity: thing.quantity ?? 1, price: thing.value }] : undefined;

    if (ids.meta_pixel && win.fbq) {
        const data: Record<string, unknown> = {};
        if (thing.id !== undefined) {
            data.content_ids = [String(thing.id)];
            data.content_type = 'product';
        }
        if (thing.name) data.content_name = thing.name;
        if (thing.value !== undefined) {
            data.value = thing.value;
            data.currency = currency;
        }
        if (thing.query) data.search_string = thing.query;
        win.fbq('track', event, data);
    }

    if (ids.ga4 && win.gtag) {
        switch (event) {
            case 'PageView':
                win.gtag('event', 'page_view', { page_location: window.location.href, page_path: window.location.pathname, page_title: document.title });
                break;
            case 'ViewContent':
                win.gtag('event', 'view_item', { currency, value: thing.value, items: item });
                break;
            case 'AddToCart':
                win.gtag('event', 'add_to_cart', { currency, value: thing.value, items: item });
                break;
            case 'Search':
                win.gtag('event', 'search', { search_term: thing.query });
                break;
            case 'InitiateCheckout':
                win.gtag('event', 'begin_checkout', { currency, value: thing.value });
                break;
            case 'Purchase':
                win.gtag('event', 'purchase', { transaction_id: thing.transaction, currency, value: thing.value });
                break;
        }
    }
}

/** An order is reported once for each browser tab, however often the page is opened again. */
export function trackOnce(key: string, event: Event, thing: Thing) {
    try {
        const k = `tracked:${event}:${key}`;
        if (sessionStorage.getItem(k)) return;
        sessionStorage.setItem(k, '1');
    } catch {
        // Storage can be switched off; then the event is sent as usual.
    }
    track(event, thing);
}
