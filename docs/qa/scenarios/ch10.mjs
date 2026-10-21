// Counter sale (features 171-191)
import { addNapa, ensureSync, newRetail } from './_counter.mjs';

async function saleToShop(t, who) {
    await t.as('admin');
    await t.go('/admin/counter');
    await t.fill('css=#cust-q', who);
    await t.wait(1500);
    await t.click('css=:is(a,button):text-is("Open it"), :is(a,button):text-is("Start")', { wait: 2200 });
}

export default [
    {
        n: 171, role: 'admin',
        did: 'Started a retail sale, a sale to a paikari shop, and a sale to a wholesaler, and looked at how each is labelled.',
        async run(t) {
            await newRetail(t);
            await t.see(/Retail/, 'A walk-in sale is a Retail sale');
            await t.shot('Section one: Retail');
            await saleToShop(t, 'Karim');
            await t.see(/Paikari|Shop/i, 'A sale to Karim Pharmacy is in the paikari section');
            await t.shot('Section two: a paikari shop');
            await saleToShop(t, 'Rahim');
            await t.see(/Wholesale/i, 'A sale to Rahim Traders is in the wholesale section');
            await t.shot('Section three: a wholesaler');
        },
    },
    {
        n: 172, role: 'admin',
        did: 'Sold a box of Napa to Karim Pharmacy with and without a hand-set price, to see which rate the counter uses first.',
        async run(t) {
            await t.preConfirm();
            await t.go('/admin/customers/4');
            await t.fill('Search a medicine', 'napa');
            await t.wait(1200);
            await t.click(/Napa 500 mg\s*tablet/i);
            await t.page.locator('input[type=number]').last().fill('18');
            await t.click('Save rate', { wait: 1500 });
            await saleToShop(t, 'Karim');
            await t.fill('Medicine name', 'napa');
            await t.wait(1300);
            await t.shot('With a hand-set rate of 18%, the counter offers the box at 246');
            await t.see(/Box[\s\S]{0,8}246/, 'The hand-set price for this shop (246) is used first');
            await t.click('Cancel draft', { wait: 1500 });
            await t.go('/admin/customers/4');
            await t.click(/Remove manual rate/, { wait: 1500 });
            await saleToShop(t, 'Karim');
            await t.fill('Medicine name', 'napa');
            await t.wait(1300);
            await t.shot('Without it, the shop\'s tier rate (255) is used');
            await t.see(/Box[\s\S]{0,8}255/, 'With no hand-set price the tier rate is used (255)');
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    {
        n: 173, role: 'admin',
        did: 'Typed part of a shop\'s name in the customer box and chose the shop from the suggestions.',
        async run(t) {
            await t.go('/admin/counter');
            await t.fill('css=#cust-q', 'Karim');
            await t.wait(1500);
            await t.shot('Suggestions as the owner types');
            await t.see(/Karim Pharmacy/, 'The shop appears as the owner types, to be chosen');
        },
    },
    {
        n: 174, role: 'admin',
        did: 'Sold a box to Karim Pharmacy at the counter, then opened the shop\'s profile.',
        async run(t) {
            await ensureSync(t, true);
            await saleToShop(t, 'Karim');
            await addNapa(t, 'Box', '255');
            await t.click('Finish sale', { wait: 2500 });
            await t.shot('The counter sale is finished');
            await t.go('/admin/customers/4');
            await t.shot('The shop\'s profile lists its transactions');
            await t.see(/All transactions[\s\S]*(M\d{4}-\d+|Napa|memo)/i, 'The counter sale is on the shop\'s profile under All transactions');
        },
    },
    {
        n: 175, role: 'paikari',
        did: 'Looked at the shop\'s own pages for its monthly total when the owner has not switched it on.',
        async run(t) {
            await t.as('admin');
            await t.go('/admin/customers/4');
            const box = t.page.getByRole('checkbox', { name: /Show the monthly total/ });
            if (await box.isChecked().catch(() => false)) {
                await box.click();
                await t.wait(1200);
            }
            await t.as('paikari');
            await t.go('/my-orders');
            await t.shot('The shop\'s page, with the monthly total switched off');
            await t.notSee(/monthly total|this month/i, 'The shop does not see its own monthly total unless the owner switches it on');
        },
    },
    {
        n: 176, role: 'admin',
        did: 'Opened "A new shop with no profile? Add it now" at the counter and added a shop.',
        async run(t) {
            await t.go('/admin/counter');
            await t.click(/A new shop with no profile/, { wait: 800 });
            await t.shot('The quick form for a new shop');
            const inputs = t.page.locator('details input:visible, details select:visible');
            t.note(`The quick form has ${await inputs.count()} boxes.`);
            await t.see(/phone|name/i, 'A shop can be added at the counter with its name and phone');
        },
    },
    {
        n: 177, role: 'admin',
        did: 'Looked at what shows beside each shop in the counter search.',
        async run(t) {
            await t.go('/admin/counter');
            await t.fill('css=#cust-q', 'Karim');
            await t.wait(1500);
            await t.shot('Suggestions show the shop\'s phone number');
            await t.see(/01700000003/, 'The phone number shows beside the shop\'s name');
        },
    },
    {
        n: 178, role: 'admin',
        did: 'Looked for an "active order exists" note when choosing a shop that has an online order open.',
        async run(t) {
            await saleToShop(t, 'Karim');
            await t.go('/admin/counter');
            await t.fill('css=#cust-q', 'Karim');
            await t.wait(2200);
            await t.shot('Choosing a shop that already has an order open');
            await t.see(/An order is already open for this customer/, 'The counter says "An order is already open for this customer"');
        },
    },
    {
        n: 179, role: 'admin',
        did: 'Sold a retail box with an end discount of 4% (inside the staff limit).',
        async run(t) {
            await newRetail(t);
            await addNapa(t);
            await t.fill('End discount', '4');
            await t.click('Apply', { wait: 1500 });
            await t.shot('Retail: printed price 300, end discount 4%, total 288');
            await t.see(/288/, 'A retail sale is the printed price with a discount taken off the total');
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    {
        n: 180, role: 'admin',
        did: 'Gave one item a special discount, then added an end discount, and looked at the total.',
        async run(t) {
            await newRetail(t);
            await t.fill('Special %', '5');
            await addNapa(t);
            await t.fill('End discount', '4');
            await t.click('Apply', { wait: 1500 });
            await t.shot('An item with its own special discount, plus an end discount');
            const body = await t.text();
            t.check(/285/.test(body) && !/273/.test(body), 'The end discount is not applied on top of an item that already has a special discount (total 285, not 273)');
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    {
        n: 181, role: 'admin',
        did: 'Looked for offers at the counter.',
        async run(t) {
            await t.go('/admin/offers');
            await t.shot('The Offers page');
            t.skip('Offers at the counter are covered once offers exist, in the Offers chapter.');
        },
    },
    {
        n: 182, role: 'admin',
        did: 'Searched at the counter for a medicine that has both a normal price and an offer.',
        async run(t) {
            await newRetail(t);
            await t.fill('Medicine name', 'napa');
            await t.wait(1300);
            await t.shot('Counter search results');
            t.skip('Needs a medicine on offer. Covered in the Offers chapter.');
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    { n: 183, role: 'admin', did: 'Looked at the offer section on a shop\'s profile.', async run(t) { await t.go('/admin/customers/4'); await t.shot('A shop\'s profile'); t.skip('Needs an offer sold at the counter. Covered in the Offers chapter.'); } },
    { n: 184, role: 'admin', did: 'Looked for two kinds of rate in one invoice.', async run(t) { await t.go('/admin/counter'); await t.shot('Counter'); t.skip('Needs an offer item and a normal item together. Covered in the Offers chapter.'); } },
    { n: 185, role: 'admin', did: 'Looked at invoice order: offers first, then regular.', async run(t) { await t.go('/admin/invoices'); await t.shot('Invoices'); t.skip('Needs a mixed offer and regular invoice. Covered in the Offers chapter.'); } },
    {
        n: 186, role: 'admin',
        did: 'Searched for "napa" and looked at how similar medicine names are told apart.',
        async run(t) {
            await newRetail(t);
            await t.fill('Medicine name', 'napa');
            await t.wait(1300);
            await t.shot('Similar names listed with their strength and company');
            const names = await t.page.getByText(/^Napa/).allInnerTexts();
            t.check(names.length >= 3, `Several Napa medicines are listed with their strength and company (${names.length} shown)`);
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    {
        n: 187, role: 'admin',
        did: 'Used the keyboard only: pressed "/" to reach the search, typed, moved down with the arrow key and pressed Enter.',
        async run(t) {
            await newRetail(t);
            await t.page.locator('body').click({ position: { x: 600, y: 700 } });
            await t.page.keyboard.press('/');
            await t.page.keyboard.type('napa');
            await t.wait(1300);
            await t.page.keyboard.press('ArrowDown');
            await t.page.keyboard.press('Enter');
            await t.wait(1500);
            await t.shot('A medicine added with the keyboard alone');
            await t.see(/Napa/, 'The keyboard shortcuts add a medicine without touching the mouse');
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    {
        n: 188, role: 'admin',
        did: 'Pressed the "Most sold" button on a new sale.',
        async run(t) {
            await newRetail(t);
            await t.shot('"Most sold" shows the favourite medicines');
            await t.see(/Most sold/, 'The counter lists the most-sold medicines');
            await t.click(/^Napa\s*500 mg$/, { wait: 1500 });
            await t.shot('One click puts it on the sale');
            await t.see(/Quantity/, 'One click adds the medicine to the sale');
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    {
        n: 189, role: 'staff',
        did: 'As staff, pressed "Simple screen" on a draft.',
        async run(t) {
            await t.go('/admin/counter');
            await t.click('New retail sale', { wait: 1800 });
            await t.click('Simple screen', { wait: 1500 });
            await t.shot('The simple screen for staff');
            await t.see(/Add medicine|Napa|Finish sale/i, 'A simpler screen is available');
            await t.go('/admin/counter');
        },
    },
    {
        n: 190, role: 'admin',
        did: 'Opened the "Counter without internet" page.',
        async run(t) {
            await t.go('/admin/counter/offline');
            await t.shot('The offline counter');
            await t.see(/internet|offline|sync/i, 'There is a counter that keeps working without internet');
            t.skip('Actually disconnecting the internet and selling was not done here.');
        },
    },
    {
        n: 191, role: 'admin',
        did: 'Looked at the offline counter for temporary memo numbers.',
        async run(t) {
            await t.go('/admin/counter/offline');
            await t.shot('The offline counter');
            t.skip('Temporary memo numbers and the clash alert when syncing need a real offline period, which was not reproduced.');
        },
    },
];
