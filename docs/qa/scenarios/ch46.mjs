// Partly-shown pass, an online order sent out by the shop's own delivery and received (features 475, 476, 477, 478, 489).
// One order is followed from the shop's request to the shop pressing "I received it". The scenarios run in this order and share it.
import { closeNotices, newestOrder, searchNapa } from './_paikari.mjs';

let orderHref = null;
let shopUrl = null;

async function asOwner(t) {
    await t.as('admin');
    await t.go(orderHref);
}

export default [
    {
        n: 475, role: 'admin',
        did: 'A paikari shop asked for a box of Napa to be delivered. The owner confirmed the line, set the delivery charge to 50, finished checking, then wrote an extra delivery charge of 20 and marked it as sent.',
        async run(t) {
            await searchNapa(t);
            await t.click(/Box.{0,4}\d/, { wait: 1500 });
            await t.page.locator('select').last().selectOption({ label: 'Deliver it to me' });
            await t.wait(800);
            await t.click(/Send order/, { wait: 2500 });
            shopUrl = t.page.url();
            orderHref = await newestOrder(t);
            await t.go(orderHref);
            await t.click('Full', { wait: 1500 });
            await t.page.locator('main input[type=number]').last().fill('50');
            await t.page.getByRole('button', { name: 'Save', exact: true }).click();
            await t.wait(1500);
            await t.click('Checking done', { wait: 2200 });
            await t.shot('The order is checked: the "Send it" box');
            await t.see(/Extra delivery charge/, 'The owner can write an extra delivery charge');
            await t.see(/If sending cost more than the buyer was charged, write the extra\. It is added and shown on the memo\./, 'The page explains it is added and shown on the memo');
            const nums = t.page.locator('main input[type=number]');
            await nums.nth(0).fill('20');
            await t.shot('An extra charge of 20 typed in');
        },
    },
    {
        n: 477, role: 'admin',
        did: 'In the same "Send it" box, looked at the choices for who pays the courier and picked "Shared 50-50".',
        async run(t) {
            await asOwner(t);
            const sel = t.page.locator('select').filter({ hasText: 'Shared 50-50' }).first();
            const opts = await sel.locator('option').allInnerTexts();
            t.check(opts.join('|') === 'As quoted|Shared 50-50|A fixed amount', `The courier charge can be as quoted, shared 50-50, or a fixed amount (${opts.join(', ')})`);
            await sel.selectOption({ label: 'Shared 50-50' });
            await t.shot('Shared 50-50 chosen: the courier\'s charge and the cartons are asked for');
            await t.see(/What the courier charged/, 'Choosing 50-50 asks what the courier charged');
        },
    },
    {
        n: 478, role: 'admin',
        did: 'Wrote what the courier charged (100) and 2 cartons, a tracking note, and pressed "Mark as sent".',
        async run(t) {
            await asOwner(t);
            const sel = t.page.locator('select').filter({ hasText: 'Shared 50-50' }).first();
            await sel.selectOption({ label: 'Shared 50-50' });
            const nums = t.page.locator('main input[type=number]');
            await nums.nth(0).fill('20');
            await nums.nth(1).fill('100');
            await nums.nth(2).fill('2');
            await t.page.locator('main input:not([type])').first().fill('T-2001').catch(() => {});
            await t.click('Mark as sent', { wait: 2500 });
            await t.shot('The order is sent: the courier charge and the cartons are recorded');
            await t.see(/Courier charge shared 50-50, 2 cartons/, 'The order records the courier charge arrangement and the number of cartons');
            await t.see(/T-2001/, 'And the tracking note');
        },
    },
    {
        n: 476, role: 'paikari',
        did: 'As the shop, opened the order after the owner marked it as sent.',
        async run(t) {
            await t.go(shopUrl);
            await t.shot('The shop sees "On the way" with the tracking note');
            await t.see(/On the way/, 'The shop sees the "On the way" step');
            await t.see(/Tracking: T-2001/, 'With the tracking note');
        },
    },
    {
        n: 489, role: 'paikari',
        did: 'As the shop, pressed "I received it", then looked at the order and at the memo.',
        async run(t) {
            await t.go(shopUrl);
            await t.see(/I received it/, 'The shop has a "I received it" button');
            await t.click('I received it', { wait: 2500 });
            await t.shot('After the shop taps "I received it"');
            await t.as('admin');
            await t.go(orderHref);
            await t.shot('The owner\'s order after the shop received it');
            const body = await t.text();
            t.check(!/Mark as sent/.test(body), 'The order is finished: it can no longer be sent again', 'the order still offers to be sent');
        },
    },
];
