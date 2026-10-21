// Partly-shown pass, returns of offer goods (feature 273). The offer on Napa 500 mg (Square) is running: 3 boxes at the offer rate.

async function shopSale(t) {
    await t.as('admin');
    await t.go('/admin/counter');
    await t.fill('css=#cust-q', 'Karim');
    await t.wait(1500);
    await t.click('css=:is(a,button):text-is("Open it"), :is(a,button):text-is("Start")', { wait: 2200 });
}

export default [
    {
        n: 273, role: 'admin',
        did: 'Sold 3 boxes through the offer to a paikari shop. On the Returns screen, recorded a return of one offer box for cash, and approved it.',
        async run(t) {
            await shopSale(t);
            await t.page.getByLabel('Medicine name').fill('napa');
            await t.wait(1700);
            await t.page.getByLabel('Boxes').first().fill('3');
            await t.page.getByRole('button', { name: 'Order', exact: true }).first().click();
            await t.wait(1800);
            await t.click('Finish sale', { wait: 2500 });
            const memo = (await t.text()).match(/M\d{4}-\d{6}/)?.[0];
            await t.shot(`The memo ${memo} with the offer line`);
            await t.see(/Box · OFFER/, 'The memo marks the offer line');
            await t.go('/admin/returns');
            await t.fill('Memo number', memo);
            await t.click('Search', { wait: 2000 });
            await t.page.locator('main button, main a').filter({ hasText: memo }).first().click();
            await t.wait(1500);
            await t.shot('The offer line on the Returns screen');
            await t.see(/OFFER/, 'The Returns screen shows the line as an offer line');
            await t.page.getByLabel('Quantity').first().fill('1');
            await t.page.getByLabel('They want').selectOption('cash');
            await t.page.getByLabel(/Reason \(required\)/).fill('Not needed');
            await t.click('Record the return', { wait: 2000 });
            const card = t.page.locator('li').filter({ hasText: memo }).filter({ has: t.page.getByRole('button', { name: 'Approve', exact: true }) }).last();
            const text = await card.innerText();
            await t.shot('The waiting return: the offer price and what is paid back in cash');
            const amount = Number((text.match(/৳([\d,]+\.\d\d)/) ?? [])[1]?.replace(/,/g, ''));
            const pays = Number((text.match(/pays ৳([\d,]+\.\d\d)/) ?? [])[1]?.replace(/,/g, ''));
            t.check(amount > 0 && pays > 0 && Math.abs(pays - amount / 2) < 0.01, `Cash back for offer goods is 50% of the offer price (offer price ${amount}, cash ${pays})`, `offer price ${amount}, cash ${pays}`);
            await card.getByRole('button', { name: 'Approve', exact: true }).click();
            await t.wait(2000);
            await t.shot('Approved');
        },
    },
];
