import { Sessions, startBrowser, BASE } from './lib.mjs';
const b = await startBrowser(); const s = new Sessions(b);
// (a) the buyer writes in the job chat and nobody answers
const v = await s.page('visitor');
await v.goto(BASE + '/custom/Lw6cFD00taRQezAUb5HrKWEK4nxqoB7iWet0Qs3x', { waitUntil: 'networkidle' });
await v.getByPlaceholder('Write a message').fill('When can I collect the table?'); await v.getByRole('button', { name: 'Send', exact: true }).last().click(); await v.waitForTimeout(1800);
// (b) an unconfirmed food order
await v.goto(BASE + '/food/r/1', { waitUntil: 'networkidle' });
await v.getByRole('button', { name: 'Add', exact: true }).first().click(); await v.waitForTimeout(1500);
await v.getByLabel('Your name').fill('QA Shopper'); await v.getByLabel('Mobile number').fill('01711111111');
await v.getByLabel('Your area').fill('Mirpur'); await v.getByLabel('Address').fill('House 4, Road 2, Mirpur 10');
await v.getByRole('button', { name: 'Place the order' }).click(); await v.waitForTimeout(2200);
console.log('food order', v.url());
// (c) the quote form
const a = await s.page('admin');
await a.goto(BASE + '/admin/quotes', { waitUntil: 'networkidle' });
console.log(await a.evaluate(() => [...document.querySelectorAll('main input,main button')].filter(x=>x.offsetWidth).map(x=>x.tagName+':'+(x.placeholder||x.innerText||x.type)).join(' ; ')));
await s.close(); await b.close();
