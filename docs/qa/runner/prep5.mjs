import { Sessions, startBrowser, BASE } from './lib.mjs';
const b = await startBrowser(); const s = new Sessions(b);
const a = await s.page('admin');
await a.goto(BASE + '/admin/orders', { waitUntil: 'networkidle' });
const href = '/admin/orders/263';
await a.goto(BASE + href, { waitUntil: 'networkidle' });
console.log((await a.innerText('main')).slice(0, 800).replace(/\n+/g,' | '));
console.log(await a.evaluate(() => [...document.querySelectorAll('main button,main input,main select')].filter(x=>x.offsetWidth).map(x=>x.tagName+':'+(x.innerText||x.placeholder||x.value||x.type).slice(0,30)).join(' ; ')));
await s.close(); await b.close();
