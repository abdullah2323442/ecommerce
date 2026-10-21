// Partly-shown pass, returns and stock (features 266, 267).
// Set-up (through the stock service, like a purchase): two boxes of Seclo 20 mg were bought in one lot.
import { ensureSync, newRetail } from './_counter.mjs';

const SECLO = 22873;
let memo = null;

async function onHand(t) {
    await t.as('admin');
    await t.go(`/admin/stock/medicines/${SECLO}`);
    const body = await t.text();
    const m = body.match(/On hand\s*(\d+(?:\.\d+)?)/);
    return { value: m ? Number(m[1]) : null, body };
}

export default [
    {
        n: 266, role: 'admin',
        did: 'Sold both boxes of a lot of Seclo at the counter, so the lot was finished. Then recorded and approved a return of one box and looked at the stock again.',
        async run(t) {
            await ensureSync(t, true);
            await newRetail(t);
            await t.page.getByLabel('Quantity').first().fill('2');
            await t.page.getByLabel('Medicine name').fill('seclo');
            await t.wait(1600);
            await t.page.getByRole('button', { name: /Box.{0,4}\d/ }).first().click();
            await t.wait(1500);
            await t.click('Finish sale', { wait: 2500 });
            memo = (await t.text()).match(/M\d{4}-\d{6}/)?.[0];
            const sold = await onHand(t);
            await t.shot(`After selling both boxes the lot is finished (${sold.value})`);
            await t.go('/admin/returns');
            await t.fill('Memo number', memo);
            await t.click('Search', { wait: 2000 });
            await t.page.locator('main button, main a').filter({ hasText: memo }).first().click();
            await t.wait(1500);
            await t.page.getByLabel('Quantity').first().fill('1');
            await t.page.getByLabel('They want').selectOption('cash');
            await t.page.getByLabel(/Reason \(required\)/).fill('Wrong medicine');
            await t.click('Record the return', { wait: 2000 });
            await t.page.locator('li').filter({ hasText: memo }).filter({ has: t.page.getByRole('button', { name: 'Approve', exact: true }) }).last().getByRole('button', { name: 'Approve', exact: true }).click();
            await t.wait(2200);
            const back = await onHand(t);
            await t.shot(`After the return is approved the box is back in its lot (${back.value})`);
            t.note(`Stock page text after the return: ${back.body.replace(/\s+/g, ' ').slice(0, 500)}`);
            t.check(back.value !== null && sold.value !== null && back.value - sold.value === 1, `The finished lot reopens with the returned box (${sold.value} became ${back.value})`, `the stock went from ${sold.value} to ${back.value}`);
        },
    },
    {
        n: 267, role: 'admin',
        did: 'Sold the returned box of Seclo again at the counter and looked at the stock.',
        async run(t) {
            const before = await onHand(t);
            await newRetail(t);
            await t.page.getByLabel('Medicine name').fill('seclo');
            await t.wait(1600);
            await t.page.getByRole('button', { name: /Box.{0,4}\d/ }).first().click();
            await t.wait(1500);
            await t.shot('The returned box added to a new sale');
            await t.click('Finish sale', { wait: 2500 });
            const after = await onHand(t);
            await t.shot(`Stock after selling it again (${after.value})`);
            await t.see(/Seclo/, 'The returned goods can be sold again like any other stock');
            t.check(before.value !== null && after.value !== null && before.value - after.value === 1, `Selling the returned box takes it out of stock again (${before.value} became ${after.value})`, `the stock went from ${before.value} to ${after.value}`);
        },
    },
];
