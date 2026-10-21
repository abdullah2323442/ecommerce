// Second pass: features that need a setting changed or a state set up first (replaces the "partly shown" results of the earlier pass)
import { addNapa, ensureSync, newRetail } from './_counter.mjs';
import { closeNotices, newestOrder, searchNapa, sendOrder } from './_paikari.mjs';

/** Change one number on the owner's Settings page and return what it was. */
async function setSetting(t, label, value) {
    await t.as('admin');
    await t.go('/admin/settings');
    const box = t.page.getByLabel(label, { exact: false }).first();
    const old = await box.inputValue();
    await box.fill(String(value));
    const save = box.locator('xpath=ancestor::*[.//button[normalize-space()="Save"]][1]').getByRole('button', { name: 'Save', exact: true }).first();
    await save.click();
    await t.wait(1500);
    return old;
}

export default [
    {
        n: 95, role: 'admin',
        did: 'Lowered "Warn when one medicine has this many lots" to 2 in Settings, then looked at the stock page and the medicine page. Napa has several lots.',
        async run(t) {
            const old = await setSetting(t, 'Warn when one medicine has this many lots', 2);
            await t.go('/admin/stock');
            await t.shot('The stock page after lowering the lot limit');
            await t.see(/\d+ lots/, 'The stock list shows how many lots a medicine is spread over');
            await t.go('/admin/stock/medicines/1');
            await t.shot('The medicine page warns: "This medicine is spread over 5 lots. Sell the old small ones first."');
            await t.see(/This medicine is spread over \d+ lots\. Sell the old small ones first/, 'The medicine page warns when stock is split across many lots, and says what to do');
            await setSetting(t, 'Warn when one medicine has this many lots', old);
        },
    },
    {
        n: 119, role: 'admin',
        did: 'Set "Ask about a draft nobody touched for" to a very short time in Settings, then opened the counter where many drafts have been left.',
        async run(t) {
            const old = await setSetting(t, 'Ask about a draft nobody touched for (hours)', 1);
            await t.go('/admin/counter');
            await t.shot('The counter, with old drafts');
            const body = await t.text();
            t.check(/Keep|return its goods|nobody touched|forgotten/i.test(body), 'The counter asks what to do with a draft nobody has touched: return its goods to stock, or keep it', 'no question about old drafts appeared');
            await setSetting(t, 'Ask about a draft nobody touched for (hours)', old);
        },
    },
    {
        n: 150, role: 'paikari',
        did: 'Set free delivery to start at 300 taka and looked at the shop\'s order with one box (255) and with two boxes (510).',
        async run(t) {
            const old = await setSetting(t, 'Free delivery over (taka, 0 for never)', 300);
            await searchNapa(t);
            // Start from an empty basket.
            for (let i = 0; i < 10; i++) {
                const rm = t.page.getByRole('button', { name: /remove|delete|trash/i });
                if (!(await rm.count())) break;
                await rm.last().click().catch(() => {});
                await t.wait(600);
            }
            await t.click(/Box.{0,4}\d/, { wait: 1500 });
            await t.page.locator('select').last().selectOption({ label: 'Deliver it to me' });
            await t.wait(800);
            await t.shot('One box with delivery to the shop: how far from free delivery');
            await t.see(/Add ৳[\d,.]+ more for free delivery/, 'The order shows how much more to add for free delivery');
            // The offer card and the search each have a quantity box; the order row's is the one in "Your order".
            await t.page.locator('xpath=//*[normalize-space()="Your order"]/following::input[@type="number"][1]').first().fill('2');
            await t.page.keyboard.press('Tab');
            await t.wait(1500);
            await t.shot('Two boxes: the free delivery level is passed');
            await t.see(/Delivery is free on this order/, 'Once the level is passed it says delivery is free');
            await setSetting(t, 'Free delivery over (taka, 0 for never)', old);
        },
    },
    {
        n: 151, role: 'admin',
        did: 'Set "Delivery charge paid first on orders of" to 100 taka, put a medicine in the public cart (300 taka) and opened checkout.',
        async run(t) {
            const old = await setSetting(t, 'Delivery charge paid first on orders of', 100);
            await t.as('guest');
            await t.go('/medicines/1');
            await t.click('Add to cart', { wait: 1800 });
            await t.go('/checkout');
            await t.shot('Checkout for an order above the limit');
            await t.see(/This order is big, so the delivery charge is paid first/, 'An order above the limit warns that the delivery charge is paid first');
            await t.as('admin');
            await setSetting(t, 'Delivery charge paid first on orders of', old);
        },
    },
    {
        n: 116, role: 'admin',
        did: 'Opened one draft as the owner and, at the same time, as staff, and looked for any sign on the screen that someone else is working on it.',
        async run(t) {
            await newRetail(t);
            await addNapa(t);
            const url = t.page.url();
            const ownerPage = t.page;
            await t.as('staff');
            await t.go(url);
            await t.shot('Staff open the owner\'s draft while the owner has it open');
            const staffSees = await t.text();
            t.check(/is working|working on this|also open|someone else/i.test(staffSees), 'Staff are told that another person has the draft open', 'no notice appeared');
            await t.as('admin');
            await t.go(url);
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    {
        n: 122, role: 'staff',
        did: 'Sent an online order. The owner opened it to check it. While it was open, staff opened the same order.',
        async run(t) {
            await sendOrder(t);
            const href = await newestOrder(t);
            await t.go(href);
            await t.shot('The owner has the order open');
            await t.as('staff');
            await t.go(href);
            await t.wait(3000);
            await t.shot('Staff open the same order: a note says who else is working on it');
            await t.see(/Siam \(Admin\) is working on this order/, 'Staff see "Siam (Admin) is working on this order"');
            t.note('The same note was not found on counter drafts (see feature 116).');
        },
    },
    {
        n: 131, role: 'admin',
        did: 'Added a box at the printed price of 300, then changed the printed price to 320, then reopened the draft and finished the sale.',
        async run(t) {
            await ensureSync(t, true);
            await newRetail(t);
            await addNapa(t);
            const url = t.page.url();
            await t.go('/admin/stock/medicines/1/edit');
            await t.fill('Printed price of a box', '320');
            await t.page.getByRole('button', { name: 'Save', exact: true }).first().click();
            await t.wait(1500);
            await t.go(url);
            await t.shot('The draft after the printed price rose to 320');
            await t.see(/300\.00/, 'The draft keeps the rate it was started at (300)');
            await t.click('Finish sale', { wait: 2500 });
            await t.shot('The finished memo');
            await t.go('/admin/stock/medicines/1/edit');
            await t.fill('Printed price of a box', '300');
            await t.page.getByRole('button', { name: 'Save', exact: true }).first().click();
            await t.wait(1200);
        },
    },
    {
        n: 75, role: 'admin',
        did: 'Looked in the reports for the sale the owner made at a loss earlier (a box sold at 210 that cost 240).',
        async run(t) {
            await t.go('/admin/reports');
            await t.shot('The monthly report');
            const a = await t.text();
            await t.go('/admin/stock/reports');
            await t.shot('The stock profit report');
            const b = await t.text();
            t.check(/loss|sold below cost|at a loss/i.test(a + b), 'A sale forced through at a loss is counted as a loss in the reports', 'neither report shows any loss line');
        },
    },
];
