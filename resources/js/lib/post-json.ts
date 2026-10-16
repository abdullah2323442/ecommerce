/** A small POST that does not reload the page, for heartbeats. Laravel gives the browser a CSRF token in a cookie. */
export async function postJson<T = unknown>(url: string): Promise<T | null> {
    const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');

    try {
        const res = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' } });

        return res.ok ? ((await res.json()) as T) : null;
    } catch {
        return null;
    }
}
