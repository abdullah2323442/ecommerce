// Ninth pass: a public market order followed from the buyer to the seller and back (replaces "partly shown" results 296, 312, 350, 354)
// The seller is "Rahim Traders" and the product is the "Matte silicone phone case". The buyer is the signed-in shopper.

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

/** The buyer orders one phone case and the seller accepts and sends it. Returns the buyer's order page. */
async function orderAndSend(t, { send = true } = {}) {
    await t.as('visitor');
    await t.go('/market/78');
    await t.page.getByLabel(/How many/i).fill('1');
    await t.page.getByLabel('Your name', { exact: true }).fill('QA Buyer');
    await t.page.getByLabel('Mobile number', { exact: true }).fill('01822222222');
    await t.page.getByLabel(/^District/).selectOption({ label: 'Dhaka' });
    await t.page.getByLabel(/^Area/).fill('Mirpur').catch(() => {});
    await t.page.getByLabel(/^Address/).fill('House 9, Road 3').catch(() => {});
    await t.click('Send the order', { wait: 2500 });
    const order = t.page.url();
    if (send) {
        await t.as('wholesale');
        await t.go('/wholesale/sell/orders');
        await t.page.getByRole('button', { name: 'Accept', exact: true }).first().click();
        await t.wait(1800);
        await t.page.getByRole('button', { name: 'I have sent it', exact: true }).first().click();
        await t.wait(1800);
    }
    await t.as('visitor');
    await t.go(order);
    return order;
}

export default [
    {
        n: 312, role: 'visitor',
        did: 'As the buyer, placed an order, then watched its steps change as the seller accepted it and sent it, and finally pressed "Yes, I got it".',
        async run(t) {
            await t.as('visitor');
            await t.go('/market/78');
            await t.page.getByLabel(/How many/i).fill('1');
            await t.page.getByLabel('Your name', { exact: true }).fill('QA Buyer');
            await t.page.getByLabel('Mobile number', { exact: true }).fill('01822222222');
            await t.page.getByLabel(/^District/).selectOption({ label: 'Dhaka' });
            await t.page.getByLabel(/^Area/).fill('Mirpur').catch(() => {});
            await t.page.getByLabel(/^Address/).fill('House 9, Road 3').catch(() => {});
            await t.click('Send the order', { wait: 2500 });
            const order = t.page.url();
            await t.shot('Step 1: the order is sent to the seller');
            await t.see(/Your order is sent to the seller\. You pay when it arrives\./, 'The buyer is told the order went to the seller, and that payment is on arrival');
            await t.see(/Sent\s*Accepted\s*On the way\s*Done/, 'The order shows its four steps: Sent, Accepted, On the way, Done');
            await t.as('wholesale');
            await t.go('/wholesale/sell/orders');
            await t.page.getByRole('button', { name: 'Accept', exact: true }).first().click();
            await t.wait(1800);
            await t.as('visitor');
            await t.go(order);
            await t.shot('Step 2: the seller accepted it');
            await t.as('wholesale');
            await t.go('/wholesale/sell/orders');
            await t.page.getByRole('button', { name: 'I have sent it', exact: true }).first().click();
            await t.wait(1800);
            await t.as('visitor');
            await t.go(order);
            await t.shot('Step 3: the seller sent it, so the buyer is asked "Did it arrive?"');
            await t.see(/Did it arrive\?/, 'Once it is on the way the buyer is asked if it arrived');
            await t.click('Yes, I got it', { wait: 2000 });
            await t.shot('Step 4: the buyer confirms it arrived');
            await t.see(/Thank you\. Glad it arrived\./, 'The buyer confirms it arrived and the order is done');
        },
    },
    {
        n: 296, role: 'visitor',
        did: 'On the delivered order, gave four stars, wrote a few words, attached a photo and pressed Send my review. Then looked at the product page.',
        async run(t) {
            await t.as('visitor');
            await t.go('/market-orders');
            const hrefs = await t.page.locator('main a').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
            const order = hrefs.find((h) => /\/market\/orders\/[A-Za-z0-9]{20,}/.test(h));
            await t.go(order);
            await t.see(/How was it\?/, 'After delivery the buyer is asked "How was it?"');
            await t.page.locator('main button[type=button]').filter({ hasText: '★' }).nth(3).click();
            await t.page.getByPlaceholder('Tell others what you think (optional)').fill('Fits well and the colour matches the picture.');
            await t.page.locator('input[type=file]').first().setInputFiles({ name: 'case.png', mimeType: 'image/png', buffer: PNG });
            await t.shot('A four-star review with a photo, ready to send');
            await t.click('Send my review', { wait: 2200 });
            await t.shot('After sending the review');
            await t.go('/market/78');
            await t.shot('The review on the product page');
            await t.see(/Fits well and the colour matches the picture/, 'The review shows on the product page');
            await t.see(/Verified buyer/i, 'Marked as a verified buyer, with no name');
        },
    },
    {
        n: 350, role: 'visitor',
        did: 'On a delivered order, read what happens when the buyer wants to send the goods back.',
        async run(t) {
            await t.go('/market-orders');
            const hrefs = await t.page.locator('main a').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
            await t.go(hrefs.find((h) => /\/market\/orders\/[A-Za-z0-9]{20,}/.test(h)));
            await t.shot('"I want to send it back"');
            await t.see(/within 7 days of getting it\. It goes straight to the seller, and you pay nothing for the delivery/, 'The buyer is told returned goods go straight to the seller, within 7 days, with no delivery charge');
            t.note('A return was not carried through to the seller receiving the goods, so only the rule is shown here.');
            t.skip('Only the rule was shown. The goods were not actually posted back to the seller.');
        },
    },
    {
        n: 354, role: 'visitor',
        did: 'Ordered again, the seller sent it and the buyer confirmed it arrived. Then the buyer wrote what went wrong and sent the complaint. Then looked at it from the seller\'s side and the owner\'s side.',
        async run(t) {
            await orderAndSend(t);
            await t.click('Yes, I got it', { wait: 2000 });
            await t.page.getByRole('button', { name: 'I have a complaint', exact: true }).click();
            await t.wait(800);
            await t.page.getByPlaceholder(/What went wrong/).fill('The case has a scratch along one edge');
            await t.shot('A complaint typed on the buyer\'s order');
            await t.page.getByRole('button', { name: 'Send', exact: true }).click();
            await t.wait(2500);
            await t.shot('After the complaint is sent');
            await t.see(/The case has a scratch along one edge/, 'The complaint is kept on the order');
            await t.as('wholesale');
            await t.go('/wholesale/sell/orders');
            await t.shot('The seller\'s orders after the complaint');
            await t.see(/Complaints: [1-9]/, 'The seller\'s record counts the complaint');
            await t.as('admin');
            await t.go('/admin/market/cases');
            await t.shot('The owner\'s cases page');
            await t.see(/The case has a scratch along one edge/, 'The owner sees the same complaint in one place');
        },
    },
    {
        n: 319, role: 'wholesale',
        did: 'On the seller\'s listing, pressed "Add a file to download" and looked at what the seller can set for a digital product.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.page.getByRole('button', { name: 'Add a file to download' }).first().click();
            await t.wait(1200);
            await t.shot('A digital file with download limits');
            await t.see(/Downloads allowed/, 'The seller can say how many times the file may be downloaded');
            await t.see(/Days to download/, 'And for how many days');
        },
    },
    {
        n: 361, role: 'wholesale',
        did: 'As the seller, tried to order the seller\'s own phone case.',
        async run(t) {
            await t.go('/market/78');
            await t.page.getByLabel(/How many/i).fill('1');
            await t.page.getByLabel('Your name', { exact: true }).fill('Rahim');
            await t.page.getByLabel('Mobile number', { exact: true }).fill('01833333333');
            await t.page.getByLabel(/^District/).selectOption({ label: 'Dhaka' });
            await t.page.getByLabel(/^Area/).fill('Mirpur').catch(() => {});
            await t.page.getByLabel(/^Address/).fill('Shop 1').catch(() => {});
            await t.click('Send the order', { wait: 2200 });
            await t.shot('The seller trying to buy their own product');
            await t.see(/You cannot buy your own product\./, 'The seller is stopped: "You cannot buy your own product."');
        },
    },
    {
        n: 355, role: 'visitor',
        did: 'After a complaint on a delivered order, the buyer pressed "Ask us to look into it". Then the owner\'s Complaints page was opened.',
        async run(t) {
            await t.as('visitor');
            await t.go('/market-orders');
            const hrefs = await t.page.locator('main a').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
            await t.go(hrefs.find((h) => /\/market\/orders\/[A-Za-z0-9]{20,}/.test(h)));
            await t.click('Ask us to look into it', { wait: 2200 });
            await t.shot('The complaint is brought to the owner');
            await t.as('admin');
            await t.go('/admin/market/cases');
            await t.shot('Serious complaints have their own place');
            await t.see(/Serious: brought to us/, 'The owner\'s page has a separate place for serious complaints');
            await t.see(/The case has a scratch along one edge/, 'The buyer\'s complaint is now listed there');
        },
    },
    {
        n: 363, role: 'wholesale',
        did: 'On the seller\'s listings, pressed Remove on the phone case, which has several orders still on the way.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.page.getByRole('button', { name: 'Remove', exact: true }).first().click();
            await t.wait(2000);
            await t.shot('After pressing Remove on a product with live orders');
            await t.see(/This listing has orders on the way\. Pause it instead\./, 'The seller is told the listing has orders on the way and to pause it instead');
            await t.go('/market/78');
            await t.see(/Matte silicone phone case/, 'The product is still on sale: it was not removed');
        },
    },
];
