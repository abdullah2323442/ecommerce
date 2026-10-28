import { Sessions, startBrowser, BASE } from './lib.mjs';
const b = await startBrowser(); const s = new Sessions(b);
const a = await s.page('admin');
await a.goto(BASE + '/admin/settings', { waitUntil: 'networkidle' });
const box = a.getByLabel('Advance share asked on a pre-order', { exact: false }).first();
console.log('old', await box.inputValue()); await box.fill('50');
await box.locator('xpath=ancestor::*[.//button[normalize-space()="Save"]][1]').getByRole('button', { name: 'Save', exact: true }).first().click(); await a.waitForTimeout(1500);
const p = await s.page('paikari');
await p.goto(BASE + '/paikari', { waitUntil: 'networkidle' });
for (let i = 0; i < 8; i++) { const c = p.getByRole('button', { name: 'Close', exact: true }).first(); if (!(await c.count())) break; await c.click().catch(()=>{}); }
await p.getByLabel('Medicine name').fill('seclo'); await p.waitForTimeout(1800);
await p.getByRole('button', { name: /Pre-order/ }).first().click(); await p.waitForTimeout(2200);
await p.goto(BASE + '/preorders', { waitUntil: 'networkidle' });
console.log((await p.innerText('main')).slice(0, 600).replace(/\n+/g,' | '));
console.log(await p.evaluate(() => [...document.querySelectorAll('main button')].filter(x=>x.offsetWidth).map(x=>x.innerText).join(' | ')));
await s.close(); await b.close();
