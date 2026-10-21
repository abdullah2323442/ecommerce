// Sixth pass: an offer selling out and coming back (replaces "partly shown" results 209, 210)
import { closeNotices, searchNapa as paikariSearch } from './_paikari.mjs';

async function shopSale(t) {
    await t.as('admin');
    await t.go('/admin/counter');
    await t.fill('css=#cust-q', 'Karim');
    await t.wait(1500);
    await t.click('css=:is(a,button):text-is("Open it"), :is(a,button):text-is("Start")', { wait: 2200 });
}

export default [
    {
        n: 209, role: 'paikari',
        did: 'At the counter, ordered every box of the offer (8) into a draft, then looked at the offer as a paikari shop and tried to add it.',
        async run(t) {
            await shopSale(t);
            await t.page.getByLabel('Medicine name').fill('napa');
            await t.wait(1700);
            await t.page.getByLabel('Boxes').first().fill('8');
            await t.page.getByRole('button', { name: 'Order', exact: true }).first().click();
            await t.wait(2000);
            await t.shot('All 8 offer boxes are now in a draft');
            await paikariSearch(t);
            await t.shot('The shop looks at the offer after it is all taken');
            const body = await t.text();
            t.check(/Was on offer a moment ago\. Stock out/.test(body), 'The offer is greyed out and says "Was on offer a moment ago. Stock out."', 'the offer still shows as available');
            t.check(!/minimum 3 boxes/.test(body), 'It has no order box and no price, so it cannot be added to the order');
        },
    },
    {
        n: 210, role: 'admin',
        did: 'Cancelled the counter draft that held all 8 offer boxes, then looked at the offer again.',
        async run(t) {
            await shopSale(t);
            await t.click('Cancel draft', { wait: 2000 });
            await t.shot('The draft is cancelled');
            await paikariSearch(t);
            await t.shot('The offer is back for the shop');
            await t.see(/8 boxes available|OFFER/, 'The offer is on sale again with its boxes back');
        },
    },
    {
        n: 217, role: 'paikari',
        did: 'Watched the offer go off when its boxes were all taken, and come back when the boxes were put back.',
        async run(t) {
            await shopSale(t);
            await t.page.getByLabel('Medicine name').fill('napa');
            await t.wait(1700);
            await t.page.getByLabel('Boxes').first().fill('8');
            await t.page.getByRole('button', { name: 'Order', exact: true }).first().click();
            await t.wait(2000);
            await paikariSearch(t);
            await t.shot('Boxes all taken: the offer is off');
            await t.see(/Was on offer a moment ago\. Stock out/, 'With no boxes left the offer goes off by itself');
            await shopSale(t);
            await t.click('Cancel draft', { wait: 2000 });
            await paikariSearch(t);
            await t.shot('Boxes back: the offer is on again');
            await t.see(/minimum 3 boxes/, 'When the boxes are back the offer comes on again by itself');
        },
    },
];
