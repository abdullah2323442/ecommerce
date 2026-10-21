// usage: node probe.mjs <role> <url>  -> prints the headings, fields, buttons and links on a screen
import { Sessions, startBrowser, BASE } from './lib.mjs';

const [role, url] = process.argv.slice(2);
const b = await startBrowser();
const s = new Sessions(b);
try {
    const p = await s.page(role);
    await p.goto(url.startsWith('http') ? url : BASE + url, { waitUntil: 'networkidle' });
    await p.waitForTimeout(800);
    const info = await p.evaluate(() => {
        const t = (e) => (e.innerText || e.value || e.placeholder || e.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 60);
        const vis = (e) => !!(e.offsetWidth || e.offsetHeight);
        return {
            url: location.pathname + location.search,
            headings: [...document.querySelectorAll('h1,h2,h3')].filter(vis).map(t),
            fields: [...document.querySelectorAll('input,textarea,select')].filter(vis).map((e) => `${e.tagName.toLowerCase()}[${e.type || ''}] label="${(e.labels?.[0]?.innerText || '').trim().slice(0, 40)}" ph="${e.placeholder || ''}" name="${e.name || ''}"`),
            buttons: [...document.querySelectorAll('button,[role=button],[role=tab]')].filter(vis).map(t).filter(Boolean),
            links: [...document.querySelectorAll('main a[href]')].filter(vis).map((a) => `${t(a)} -> ${a.getAttribute('href')}`).slice(0, 40),
        };
    });
    console.log(JSON.stringify(info, null, 1));
} finally {
    await s.close();
    await b.close();
}
