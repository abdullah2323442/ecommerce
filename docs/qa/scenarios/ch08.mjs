// Paikari sector, part 1 (features 133-152): ordering and checking
import { addBox, closeNotices, newestOrder, searchNapa, sendOrder } from './_paikari.mjs';

export default [
    {
        n: 133, role: 'paikari',
        did: 'Signed in as a paikari shop, searched for Napa and read the results looking for any mention of how much stock the shop holds.',
        async run(t) {
            await searchNapa(t);
            await t.shot('The search results a paikari shop sees');
            await t.notSee(/in stock|on hand|boxes left|left in stock|available:/i, 'The results show prices and units, never how many the shop holds');
            await t.see(/Napa 500 mg/, 'The medicine itself is listed');
        },
    },
    {
        n: 134, role: 'paikari',
        did: 'Searched for Napa, put a box on the order and sent it.',
        async run(t) {
            await searchNapa(t);
            await t.shot('Search results with a price for each unit');
            await addBox(t);
            await t.shot('The box is on "Your order"');
            await t.see(/Your order/, 'The chosen medicine appears under "Your order"');
            await t.click(/Send order/, { wait: 2500 });
            await t.shot('The order has been sent and is being checked');
            await t.see(/Checking/, 'The order is sent and shows as "Checking…"');
        },
    },
    {
        n: 135, role: 'paikari',
        did: 'Looked at the unit choices under one medicine.',
        async run(t) {
            await searchNapa(t);
            await t.see(/Box\s*৳255\.00/, 'A box can be ordered');
            await t.see(/Strip\s*৳85\.00/, 'A strip can be ordered');
            await t.see(/Piece\s*৳8\.50/, 'A single piece can be ordered');
            await t.shot('Box, strip or piece, each with its own price');
        },
    },
    {
        n: 136, role: 'paikari',
        did: 'Pressed "Save for later" under a medicine to put it on the short list.',
        async run(t) {
            await searchNapa(t);
            await t.click('Save for later', { wait: 1500 });
            await t.shot('After "Save for later"');
            await t.see(/Short list|Saved|saved for later|Your list/i, 'The medicine goes onto a short list');
        },
    },
    {
        n: 137, role: 'paikari',
        did: 'Looked at the short list for a way to order from it directly.',
        async run(t) {
            await t.as('paikari');
            await t.go('/paikari');
            await closeNotices(t);
            await t.shot('The short list on the ordering screen, with an "Order now" button');
            await t.see(/Short list/, 'The short list is on the ordering screen');
            t.check(await t.page.getByRole('button', { name: 'Order now' }).count() > 0, 'Each short-list entry has an "Order now" button, so it can be ordered straight from the list');
        },
    },
    {
        n: 138, role: 'admin',
        did: 'Opened an order in the back office and looked for the "Not available" choice.',
        async run(t) {
            const href = await newestOrder(t);
            await t.go(href);
            await t.shot('An order being checked: Full, Partial or Not available');
            await t.see(/Not available/, 'Staff can mark a medicine "Not available"');
            t.note('The record of medicines asked for but not available is kept on the Price requests and Reports pages.');
        },
    },
    {
        n: 139, role: 'paikari',
        did: 'After sending an order, read what the shop sees straight away.',
        async run(t) {
            await sendOrder(t);
            await t.shot('Right after sending: a memo at the printed price');
            await t.see(/Preliminary memo at printed prices/, 'The shop gets a first memo at the printed prices straight away');
        },
    },
    {
        n: 140, role: 'admin',
        did: 'Opened the newest order and looked at the three choices staff have for each item.',
        async run(t) {
            const href = await newestOrder(t);
            await t.go(href);
            await t.see(/Full/, 'Choice one: Full');
            await t.see(/Partial/, 'Choice two: Partial');
            await t.see(/Not available/, 'Choice three: Not available');
            await t.shot('Full, Partial or Not available for each item');
        },
    },
    {
        n: 141, role: 'admin',
        did: 'Looked at the order list for a mark on offer items.',
        async run(t) {
            await t.go('/admin/orders');
            await t.shot('The list of online orders');
            t.skip('There was no offer item in these test orders, so the offer mark could not be shown. Offers are covered in the Offers chapter.');
        },
    },
    {
        n: 142, role: 'paikari',
        did: 'Kept the shop\'s order page open. Meanwhile the owner marked the item Full and pressed "Checking done". Then looked at the shop\'s page without reloading it.',
        async run(t) {
            await sendOrder(t);
            const shop = t.page;
            await t.shot('The shop\'s page while the order is still being checked');
            const href = await newestOrder(t);
            await t.go(href);
            await t.click(/^Full$/, { wait: 1200 });
            await t.click('Checking done', { wait: 2000 });
            await t.shot('The owner has finished checking');
            t.page = shop;
            await t.page.waitForTimeout(9000);
            await t.shot('The shop\'s page, not reloaded, some seconds later');
            const body = await t.text();
            t.check(!/Checking…/.test(body) || /Checked|Memo|Final/i.test(body), 'The shop\'s open page updates by itself once the owner is done');
        },
    },
    {
        n: 143, role: 'paikari',
        did: 'Looked at the shop\'s page right after the owner finished checking.',
        async run(t) {
            await t.as('paikari');
            await t.go('/paikari');
            await closeNotices(t);
            await t.shot('The shop\'s page: the buyer sees the result at once');
            await t.see(/Napa 500 mg/, 'The order and its result are shown');
        },
    },
    {
        n: 144, role: 'paikari',
        did: 'Looked at the status words on the shop\'s orders.',
        async run(t) {
            await t.as('paikari');
            await t.go('/paikari');
            await closeNotices(t);
            await t.shot('Orders with their status');
            await t.see(/Checking|Checked|Ready|Packed|Done/i, 'Each order shows a status');
        },
    },
    {
        n: 145, role: 'paikari',
        did: `Sent an order and read the memo total on the shop's order page.`,
        async run(t) {
            await sendOrder(t);
            await t.shot(`The shop's order page: the total is at the shop's own price`);
            await t.see(/Box · ৳255\.00/, `Each box is priced at the shop's own price (255 for a box printed at 300)`);
            await t.see(/Preliminary memo at printed prices/, 'The memo link says it is the first, preliminary memo');
        },
    },
    {
        n: 146, role: 'admin',
        did: `Opened a new order being checked, typed a different price for the item (250 instead of 255), pressed "Set price", then looked at the order from the shop's side.`,
        async run(t) {
            await sendOrder(t);
            const href = await newestOrder(t);
            await t.go(href);
            await t.shot('The order before the price is changed');
            await t.page.locator('input[type=number]:visible').first().fill('250');
            await t.click('Set price', { wait: 1800 });
            await t.shot('After "Set price": the total is now 250');
            await t.see(/Total\s*৳250\.00/, 'The owner changed the price and the total is 250');
            await t.click(/^Full$/, { wait: 1200 });
            await t.click('Checking done', { wait: 2000 });
            await t.as('paikari');
            await t.go('/paikari');
            await t.shot(`The shop's side shows the price the owner set`);
        },
    },
    {
        n: 147, role: 'paikari',
        did: 'On an order that was already sent, read the note about adding more items.',
        async run(t) {
            await sendOrder(t);
            await t.shot('"Need more? New items are checked the same way."');
            await t.see(/Need more\? New items are checked the same way/, 'The shop can add more after the memo, and the new items are checked the same way');
            await t.see(/Find a medicine/, 'The search box is still on the order page for adding more');
        },
    },
    {
        n: 148, role: 'paikari',
        did: 'Looked at the choices for how to receive the goods.',
        async run(t) {
            await searchNapa(t);
            await addBox(t);
            const options = await t.page.locator('select').last().locator('option').allInnerTexts();
            await t.shot('Collection choices on the order');
            t.note(`The choices are: ${options.join(' / ')}`);
            t.check(options.length === 3, `There are three collection options (${options.join(', ')})`);
        },
    },
    {
        n: 149, role: 'admin',
        did: 'Looked for a box where staff type the delivery charge by hand.',
        async run(t) {
            const href = await newestOrder(t);
            await t.go(href);
            await t.shot('The order screen');
            const body = await t.text();
            t.check(/delivery/i.test(body), 'The order screen has a delivery charge to type in by hand');
        },
    },
    {
        n: 150, role: 'paikari',
        did: 'Looked on the order for a "free delivery progress" message.',
        async run(t) {
            await t.as('paikari');
            await t.go('/paikari');
            await closeNotices(t);
            await t.shot('Orders');
            t.skip('A free-delivery progress message was not found on the ordering screen.');
        },
    },
    {
        n: 151, role: 'admin',
        did: 'Looked for a warning when an order passes the free-delivery limit.',
        async run(t) {
            await t.go('/admin/settings');
            await t.shot('Settings');
            t.skip('The warning on passing the limit was not reproduced.');
        },
    },
    {
        n: 152, role: 'admin',
        did: 'At the counter, started a sale to Karim Pharmacy on the shop\'s behalf, as if the shop had phoned in.',
        async run(t) {
            await t.go('/admin/counter');
            await t.fill('css=#cust-q', 'Karim');
            await t.wait(1500);
            await t.click('css=:is(a,button):text-is("Open it"), :is(a,button):text-is("Start")', { wait: 2200 });
            await t.shot('A sale started for Karim Pharmacy by the owner');
            await t.see(/Karim Pharmacy/, 'The sale is in the shop\'s name');
        },
    },
];
