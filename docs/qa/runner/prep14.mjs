import { Sessions, startBrowser, BASE } from './lib.mjs';
const b = await startBrowser(); const s = new Sessions(b);
const p = await s.page('paikari');
await p.goto(BASE + '/paikari', { waitUntil: 'networkidle' });
for (let i = 0; i < 8; i++) { const c = p.getByRole('button', { name: 'Close', exact: true }).first(); if (!(await c.count())) break; await c.click().catch(()=>{}); }
for (const q of ['napa']) {
  await p.getByLabel('Medicine name').fill(q); await p.waitForTimeout(1800);
  const btn = p.getByRole('button', { name: /Pre-order/ }); console.log(q, await btn.count());
  if (await btn.count()) { await btn.first().click(); await p.waitForTimeout(2200); const msg = (await p.innerText('main')).match(/already have a pre-order|pre-order for [^.]*\./i); console.log(msg?.[0]); }
}
await p.goto(BASE + '/preorders', { waitUntil: 'networkidle' });
console.log((await p.innerText('main')).slice(0, 700).replace(/\n+/g,' | '));
await s.close(); await b.close();
