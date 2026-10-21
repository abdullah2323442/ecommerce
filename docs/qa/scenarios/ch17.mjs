// Wholesale and commission (features 322-365)

export default [
    {
        n: 322, role: 'admin',
        did: 'Opened the wholesaler\'s customer page and looked at the categories the owner gives them.',
        async run(t) {
            await t.go('/admin/customers/5');
            await t.see(/Categories they can see/, 'The owner chooses which categories a wholesale buyer can see');
            await t.shot('"Categories they can see"');
        },
    },
    {
        n: 323, role: 'wholesale',
        did: 'Looked at the wholesaler\'s account page for their minimum order.',
        async run(t) {
            await t.go('/wholesale/account');
            await t.see(/Minimum per item/, 'The wholesaler has a minimum quantity per item');
            await t.shot('Minimum per item');
        },
    },
    {
        n: 324, role: 'wholesale',
        did: 'Looked at how this wholesaler may pay.',
        async run(t) {
            await t.go('/wholesale/account');
            await t.see(/You can pay[\s\S]*Pay when it arrives/, 'The account says how this buyer may pay (on arrival, in advance)');
            await t.shot('Payment choices set by the owner for this buyer');
        },
    },
    {
        n: 325, role: 'wholesale',
        did: 'Opened the wholesaler\'s account page and looked for their grade and month-by-month buying.',
        async run(t) {
            await t.go('/wholesale/account');
            await t.see(/Your grade/, 'The wholesaler sees their own grade');
            await t.see(/Your buying, month by month/, 'And a month-by-month record of their buying');
            await t.shot('The wholesale dashboard');
        },
    },
    {
        n: 326, role: 'admin',
        did: 'Looked at the Pre-orders page and the pre-order setting.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/Advance share asked on a pre-order/, 'The owner sets the share asked up front on a pre-order');
            await t.shot('The pre-order setting');
            t.skip('A time window for wholesale pre-orders was not found as a separate setting.');
        },
    },
    { n: 327, role: 'wholesale', did: 'Looked for a bulk order upload.', async run(t) { await t.go('/wholesale'); await t.shot('The wholesale screen'); t.skip('No bulk upload control was found on the wholesale screen.'); } },
    {
        n: 328, role: 'wholesale',
        did: 'Asked for a price on a bulk purchase using the quotation form, then looked at the owner\'s Quotes page.',
        async run(t) {
            await t.go('/wholesale/quotes');
            await t.fill('What do you want?', 'Napa 500 mg, 200 boxes');
            await t.fill('How much?', '200 boxes monthly');
            await t.shot('A custom quotation request');
            await t.click('Ask', { wait: 1800 });
            await t.shot('The request is sent');
            await t.as('admin');
            await t.go('/admin/quotes');
            await t.shot('The owner sees the request');
            await t.see(/Napa 500 mg, 200 boxes/, 'The owner sees the quotation request');
        },
    },
    {
        n: 329, role: 'wholesale',
        did: 'Opened the seller screen a wholesaler uses to sell to the public.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.see(/Add a listing/, 'A wholesaler can add products to sell');
            await t.shot('The seller screen');
        },
    },
    {
        n: 330, role: 'wholesale',
        did: 'Looked at what a wholesaler may sell, to see if medicine for paikari shops is left out.',
        async run(t) {
            await t.go('/wholesale/sell');
            const options = await t.page.locator('select').allInnerTexts();
            const text = options.join(' ');
            t.note(`The categories offered when adding a listing include: ${text.replace(/\n/g, ', ').slice(0, 200)}`);
            t.check(!/Medicine/i.test(text), 'Medicine for paikari shops cannot be listed from the seller screen', 'Medicine is offered');
            await t.shot('The categories a wholesaler may sell in');
        },
    },
    {
        n: 331, role: 'admin',
        did: 'Compared a product\'s seller price in the owner\'s Market page with the price the public pays.',
        async run(t) {
            await t.go('/admin/market');
            await t.see(/Public ৳1,200\.00/, 'The seller\'s price for the phone case is 1,200');
            await t.go('/market/78');
            await t.see(/৳1,224\.00/, 'The public pays 1,224: the commission of 24 is added on top');
            await t.shot('The public price includes the commission');
        },
    },
    {
        n: 332, role: 'admin',
        did: 'Looked at the commission box on each product in the owner\'s Market page.',
        async run(t) {
            await t.go('/admin/market');
            t.check((await t.page.locator('input[type=number]').count()) > 5, 'Each product has its own commission box');
            await t.shot('Each product has its own commission');
        },
    },
    {
        n: 333, role: 'admin',
        did: 'Looked at the choice between percent and taka on the commission.',
        async run(t) {
            await t.go('/admin/market');
            const options = await t.page.locator('select').first().locator('option').allInnerTexts();
            t.note(`The choices are: ${options.join(' / ')}`);
            t.check(options.some((o) => /percent/i.test(o)) && options.some((o) => /taka/i.test(o)), 'A commission can be a percent or a fixed taka amount');
            await t.shot('Percent or taka');
        },
    },
    {
        n: 334, role: 'admin',
        did: 'Opened the owner\'s commission page.',
        async run(t) {
            await t.go('/admin/market/commissions');
            await t.shot('Who owes how much, and how many were sold');
            await t.see(/commission/i, 'The page shows commission owed');
        },
    },
    {
        n: 335, role: 'admin',
        did: 'Looked at the "Asking for less commission" list on the owner\'s Market page.',
        async run(t) {
            await t.go('/admin/market');
            await t.see(/Asking for less commission/, 'Sellers can ask for less commission, and the owner sees the requests');
            await t.shot('Requests for less commission');
        },
    },
    {
        n: 336, role: 'admin',
        did: 'Looked at the "Waiting to be read" list, where a new product waits before it is published.',
        async run(t) {
            await t.go('/admin/market');
            await t.see(/Waiting to be read/, 'New products wait for the owner before they go live');
            await t.shot('Waiting to be read');
        },
    },
    {
        n: 337, role: 'admin',
        did: 'Read the number beside "Waiting to be read".',
        async run(t) {
            await t.go('/admin/market');
            await t.see(/Waiting to be read \(?\d+\)?|Waiting to be read\s*\d+/, 'The owner sees how many products are waiting');
            await t.shot('The pending count');
        },
    },
    { n: 338, role: 'admin', did: 'Looked at the Market page for a flag on products with no photo.', async run(t) { await t.go('/admin/market'); await t.shot('The Market page'); t.skip('The page has a "No photo" flag for a waiting product that has no picture. Every product in this copy has a photo, so the flag never had a chance to show.'); } },
    {
        n: 339, role: 'admin',
        did: 'Looked at the monthly commission statement from both sides.',
        async run(t) {
            await t.go('/admin/market/statements');
            await t.shot('The owner\'s statements page');
            await t.see(/statement/i, 'The owner can make the monthly statements');
            await t.as('wholesale');
            await t.go('/wholesale/sell/statements');
            await t.shot('The seller\'s statements page: answer Paid or Problem');
            await t.see(/Answer Paid, or Problem with the reason/, 'The seller answers each statement with Paid, or Problem with a reason');
        },
    },
    {
        n: 340, role: 'wholesale',
        did: 'Read the rule about a seller who does not answer a statement.',
        async run(t) {
            await t.go('/wholesale/sell/statements');
            await t.see(/If there is no answer in 10/, 'The page says what happens if a seller does not answer within 10 days');
            await t.shot('The rule on unanswered statements');
        },
    },
    { n: 341, role: 'admin', did: 'Looked for commission being removed on cancellation.', async run(t) { await t.go('/admin/market/commissions'); await t.shot('Commissions'); t.skip('Cancelling a sale and watching its commission disappear was not carried through.'); } },
    {
        n: 342, role: 'wholesale',
        did: 'Read how money moves between buyer and seller.',
        async run(t) {
            await t.go('/wholesale/sell/statements');
            await t.see(/Buyers pay you directly/, 'Buyers pay the seller directly, outside the platform');
            await t.shot('Money is paid outside the platform');
        },
    },
    {
        n: 343, role: 'wholesale',
        did: 'Looked at the seller\'s own dashboard.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.shot('The seller\'s own dashboard');
            await t.see(/Orders for your products/, 'The seller has a dashboard of orders for their products');
        },
    },
    {
        n: 344, role: 'wholesale',
        did: 'Opened the seller\'s list of orders and looked at what is shown about the buyer.',
        async run(t) {
            await t.go('/wholesale/sell/orders');
            await t.shot('An order as the seller sees it');
            await t.see(/Phone: /, 'The seller sees the buyer\'s phone');
            await t.see(/Address: /, 'And the delivery address');
            await t.notSee(/QA Guest/, 'But not the buyer\'s name');
        },
    },
    {
        n: 345, role: 'wholesale',
        did: 'Accepted an order and marked it sent, then looked at the buyer\'s phone and address again.',
        async run(t) {
            await t.go('/wholesale/sell/orders');
            await t.click('Accept', { wait: 1500 });
            await t.click('I have sent it', { wait: 1500 });
            await t.shot('The order just sent: still "On the way", so the buyer\'s details are still shown');
            await t.see(/Phone: \*\*\*/, 'On a finished order, the buyer\'s phone shows as stars');
            await t.see(/Address: \*\*\*/, 'And the address shows as stars');
            await t.see(/The buyer details are hidden once an order is finished/, 'The page says why: the details are hidden once an order is finished');
            t.note('While an order is still on its way, the seller needs the phone and address, so they are shown in full. They are masked only when the order is finished.');
        },
    },
    { n: 346, role: 'admin', did: 'Looked for notifications that come from the owner\'s site.', async run(t) { await t.go('/admin/messages'); await t.shot('Messages'); t.skip('Not reproduced.'); } },
    { n: 347, role: 'wholesale', did: 'Read the package rule.', async run(t) { await t.go('/wholesale/sell/orders'); await t.see(/Do not put cards, leaflets or your page address in the package/, 'Sellers are told not to put cards or leaflets in the package'); await t.shot('The package rule'); t.skip('The checklist marks this rule only partly built: it is a notice, with no way to check what is in a package.'); } },
    { n: 348, role: 'admin', did: 'Looked at the cases page for penalty steps.', async run(t) { await t.go('/admin/market/cases'); await t.shot('Market cases'); t.skip('The checklist marks penalty steps as partly built. Applying a penalty was not carried through.'); } },
    { n: 349, role: 'wholesale', did: 'Looked at the return page for money handling.', async run(t) { await t.go('/wholesale/returns'); await t.shot('Returns'); t.skip('Return money for cash on delivery versus online was not reproduced.'); } },
    { n: 350, role: 'admin', did: 'Looked at where returned goods go.', async run(t) { await t.go('/admin/market/cases'); await t.shot('Market cases'); t.skip('Goods going straight back to the seller was not reproduced.'); } },
    { n: 351, role: 'admin', did: 'Looked at online payments held until delivery.', async run(t) { await t.go('/admin/payments'); await t.shot('Online payments'); t.skip('The checklist marks holding online payments as partly built. Not reproduced.'); } },
    { n: 352, role: 'admin', did: 'Looked for sellers gaining trust over time.', async run(t) { await t.go('/market/78'); await t.see(/Trusted seller/, 'A seller with a clean record wears a "Trusted seller" mark'); await t.shot('The "Trusted seller" mark'); } },
    { n: 353, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('A security deposit has not been built yet.'); } },
    { n: 354, role: 'admin', did: 'Looked at the cases page that both sides can see.', async run(t) { await t.go('/admin/market/cases'); await t.shot('Complaints'); t.skip('Raising a complaint and seeing it from both sides was not carried through.'); } },
    { n: 355, role: 'admin', did: 'Looked for a separate place for serious complaints.', async run(t) { await t.go('/admin/market/cases'); await t.shot('Complaints'); t.skip('Not reproduced.'); } },
    { n: 356, role: 'wholesale', did: 'Looked at the seller\'s record, which shows returns.', async run(t) { await t.go('/wholesale/sell/orders'); await t.see(/Sent back: \d+/, 'The seller\'s record counts orders sent back'); await t.shot('The seller\'s record'); } },
    {
        n: 357, role: 'wholesale',
        did: 'Opened the seller\'s reports page.',
        async run(t) {
            await t.go('/wholesale/sell/reports');
            await t.see(/Your rank/, 'The seller sees their own rank');
            await t.see(/Sells the most/, 'And which products sell the most');
            await t.shot('Seller reports');
        },
    },
    {
        n: 358, role: 'wholesale',
        did: 'Looked on the reports for "viewed but not bought".',
        async run(t) {
            await t.go('/wholesale/sell/reports');
            await t.shot('Seller reports');
            await t.see(/Looked at by \d+ people · \d+ orders · Sold \d+/, 'Each product shows how many people looked at it, how many ordered and how many sold, so "looked but did not buy" can be seen');
        },
    },
    {
        n: 359, role: 'wholesale',
        did: 'Read the seller\'s rank and what it depends on.',
        async run(t) {
            await t.go('/wholesale/sell/reports');
            await t.see(/Your rank/, 'The seller has a rank');
            await t.see(/Sellers who send fast and have few returns are shown higher/, 'The rank rewards fast sending and few returns');
            await t.shot('Supplier rank');
        },
    },
    {
        n: 360, role: 'wholesale',
        did: 'Looked at the seller reports for "Buyer said it did not arrive".',
        async run(t) {
            await t.go('/wholesale/sell/reports');
            await t.see(/Buyer said it did not arrive/, 'A "not received" figure is kept');
            await t.shot('The "did not arrive" figure');
        },
    },
    { n: 361, role: 'wholesale', did: 'Looked for blocking a seller from buying their own product.', async run(t) { await t.go('/market/78'); await t.shot('Rahim Traders looking at their own product'); t.skip('Trying to order one\'s own product was not carried through.'); } },
    {
        n: 362, role: 'wholesale',
        did: 'Looked at the "I am going away" holiday mode.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.see(/Holiday mode/, 'The seller has a Holiday mode');
            await t.see(/Going away\? Turn off new orders/, 'It turns off new orders while the seller is away');
            await t.shot('Holiday mode');
        },
    },
    { n: 363, role: 'wholesale', did: 'Looked for the rule on deleting a product with active orders.', async run(t) { await t.go('/wholesale/sell'); await t.shot('The seller screen'); t.skip('Trying to delete a product with an active order was not carried through.'); } },
    { n: 364, role: 'visitor', did: 'Looked at the "Report this as a copy" link on a product.', async run(t) { await t.go('/market/78'); await t.see(/Report this as a copy/, 'Anyone can report a copied product'); await t.shot('Report this as a copy'); } },
    {
        n: 365, role: 'wholesale',
        did: 'Looked at the seller\'s orders for a count of orders not yet seen.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.shot('The seller screen with its orders count');
            await t.see(/Orders for your products\s*\d+|\d+/, 'The tab shows how many orders there are');
            t.skip('The checklist marks the text-message alert as partly built. No text message was sent.');
        },
    },
];
