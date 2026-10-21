// Tenth pass: sales, memos and orders compared before and after (replaces "partly shown" results 35, 43, 48, 141)
import { addNapa, ensureSync, newRetail } from './_counter.mjs';
import { closeNotices } from './_paikari.mjs';


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

async function monthCommission(t) {
    await t.as('admin');
    await t.go('/admin/market/commissions');
    const m = (await t.text()).match(/Month\s*৳([\d,]+(?:\.\d+)?)/);
    return m ? Number(m[1].replace(/,/g, '')) : NaN;
}

/** The buyer orders one phone case. Returns the buyer's order page. */
async function buyPhoneCase(t) {
    await t.as('visitor');
    await t.go('/market/78');
    await t.page.getByLabel(/How many/i).fill('1');
    await t.page.getByLabel('Your name', { exact: true }).fill('QA Buyer');
    await t.page.getByLabel('Mobile number', { exact: true }).fill('01822222222');
    await t.page.getByLabel(/^District/).selectOption({ label: 'Dhaka' });
    await t.page.getByLabel(/^Area/).fill('Mirpur').catch(() => {});
    await t.page.getByLabel(/^Address/).fill('House 9, Road 3').catch(() => {});
    await t.click('Send the order', { wait: 2500 });
    return t.page.url();
}

async function salesToday(t) {
    await t.as('admin');
    await t.go('/admin/reports');
    const m = (await t.text()).match(/Sales today\s*৳([\d,]+(?:\.\d+)?)/);
    return m ? Number(m[1].replace(/,/g, '')) : NaN;
}

export default [
    {
        n: 48, role: 'admin',
        did: 'Read "Sales today" in the report, then started a retail sale with one box in it and read it again, then finished the sale and read it a third time.',
        async run(t) {
            await ensureSync(t, true);
            const before = await salesToday(t);
            await t.shot(`"Sales today" before: ${before}`);
            await newRetail(t);
            await addNapa(t);
            const draftUrl = t.page.url();
            const during = await salesToday(t);
            await t.shot(`"Sales today" while the sale is only a draft: ${during}`);
            t.check(during === before, `A sale that is only a draft is not counted (${before} before, ${during} with the draft open)`, `the figure moved from ${before} to ${during}`);
            await t.go(draftUrl);
            await t.click('Finish sale', { wait: 2500 });
            const after = await salesToday(t);
            await t.shot(`"Sales today" after the sale is finished: ${after}`);
            t.check(Math.abs(after - before - 300) < 0.01, `Once finished the sale is counted: ${before} became ${after}`, `the figure went from ${before} to ${after}, not up by 300`);
        },
    },
    {
        n: 35, role: 'admin',
        did: 'Finished a retail sale of a box of Napa at 300, then raised the printed price to 320 and opened the memo again.',
        async run(t) {
            await ensureSync(t, true);
            await newRetail(t);
            await addNapa(t);
            await t.click('Finish sale', { wait: 2500 });
            const memo = t.page.url();
            await t.shot('The finished memo at 300');
            await t.see(/৳300\.00/, 'The memo shows the box at 300');
            await t.go('/admin/stock/medicines/1/edit');
            await t.fill('Printed price of a box', '320');
            await t.page.getByRole('button', { name: 'Save', exact: true }).first().click();
            await t.wait(1500);
            await t.go(memo);
            await t.shot('The same memo after the printed price rose to 320');
            await t.see(/৳300\.00/, 'The memo still shows 300: it keeps that day\'s price');
            await t.notSee(/৳320\.00/, 'It does not change to the new price');
            await t.go('/admin/stock/medicines/1/edit');
            await t.fill('Printed price of a box', '300');
            await t.page.getByRole('button', { name: 'Save', exact: true }).first().click();
            await t.wait(1200);
        },
    },
    {
        n: 43, role: 'staff',
        did: 'The owner raised the discount staff may give from 5% to 50%. Then, as a staff member, a retail sale was started with a Special % of 40 on a box of Napa that cost 240 and is printed at 300.',
        async run(t) {
            const old = await setSetting(t, 'Discount staff can give without approval (%)', 50);
            await t.as('staff');
            await t.go('/admin/counter');
            await t.click('New retail sale', { wait: 1800 });
            await t.page.getByLabel('Special %').fill('40');
            await t.page.getByLabel('Medicine name').fill('napa');
            await t.wait(1500);
            await t.page.getByRole('button', { name: /Box.{0,4}\d/ }).first().click();
            await t.wait(1800);
            await t.shot('A box at 40% below the printed price, which is below cost');
            const body = await t.text();
            t.check(/would be a loss\. Only the admin can approve it/.test(body), 'Staff are told "Selling Napa at that price would be a loss. Only the admin can approve it." and the line is not added', 'no loss warning appeared: ' + body.slice(0, 200).replace(/\s+/g, ' '));
            await setSetting(t, 'Discount staff can give without approval (%)', old);
        },
    },
    {
        n: 141, role: 'paikari',
        did: 'As a paikari shop, ordered 3 boxes through the offer and sent the order. Then the owner opened the online orders list.',
        async run(t) {
            await t.as('paikari');
            await t.go('/paikari');
            await closeNotices(t);
            await t.fill('Medicine name', 'napa');
            await t.wait(1800);
            await t.page.getByLabel('Boxes').first().fill('3');
            await t.page.getByRole('button', { name: 'Order', exact: true }).first().click();
            await t.wait(1800);
            await t.shot('The offer boxes are in the shop\'s order');
            await t.click(/Send order/, { wait: 2500 });
            await t.as('admin');
            await t.go('/admin/orders');
            await t.shot('The owner\'s list of online orders');
            await t.see(/OFFER|Offer/, 'The order with an offer item is marked on the owner\'s list');
        },
    },
    {
        n: 47, role: 'admin',
        did: 'Read the month\'s commission, then a buyer ordered a phone case and the seller accepted and sent it, and the commission was read again. Then the buyer pressed "Yes, I got it" and it was read a third time.',
        async run(t) {
            const start = await monthCommission(t);
            await t.shot(`Commission before the order: ${start}`);
            const order = await buyPhoneCase(t);
            await t.as('wholesale');
            await t.go('/wholesale/sell/orders');
            await t.page.getByRole('button', { name: 'Accept', exact: true }).first().click();
            await t.wait(1800);
            await t.page.getByRole('button', { name: 'I have sent it', exact: true }).first().click();
            await t.wait(1800);
            const sent = await monthCommission(t);
            await t.shot(`Commission with the order on its way: ${sent}`);
            t.check(sent === start, `An order that is only on its way is not counted (${start} before, ${sent} after)`, `the commission went from ${start} to ${sent}`);
            await t.as('visitor');
            await t.go(order);
            await t.click('Yes, I got it', { wait: 2000 });
            const done = await monthCommission(t);
            await t.shot(`Commission after delivery: ${done}`);
            t.check(Math.abs(done - start - 24) < 0.01, `Once the buyer has it, the commission is counted: ${start} became ${done} (2% of 1,200)`, `the commission went from ${start} to ${done}, not up by 24`);
        },
    },
    {
        n: 341, role: 'admin',
        did: 'A buyer ordered a phone case, the seller accepted it and then cancelled it. The month\'s commission was read before and after.',
        async run(t) {
            const start = await monthCommission(t);
            await buyPhoneCase(t);
            await t.as('wholesale');
            await t.go('/wholesale/sell/orders');
            await t.page.getByRole('button', { name: 'Accept', exact: true }).first().click();
            await t.wait(1800);
            await t.page.getByRole('button', { name: 'Cancel', exact: true }).first().click();
            await t.wait(600);
            await t.page.getByPlaceholder('Why (the buyer is told)').first().fill('Out of stock after all');
            await t.shot('The seller writes why and cancels');
            await t.page.getByRole('button', { name: 'Cancel the order', exact: true }).first().click();
            await t.wait(2000);
            await t.shot('The seller cancelled the order');
            await t.see(/Cancelled/i, 'The order now shows as cancelled');
            const after = await monthCommission(t);
            await t.shot(`Commission after the cancel: ${after}`);
            t.check(after === start, `A cancelled sale carries no commission (${start} before, ${after} after)`, `the commission went from ${start} to ${after}`);
        },
    },
];
