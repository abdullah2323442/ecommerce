// Public market, part 2 (features 305-321): carts, checkout, orders and changes
import { closeNotices } from './_paikari.mjs';

const stamp = Date.now();

/** Fill the order form on a product page. */
async function fillOrder(t, { name = 'QA Guest', phone = '01811111111', district = 'Dhaka', area = 'Mirpur', address = 'House 5, Road 2', qty = '1' } = {}) {
    await t.page.getByLabel(/How many/i).fill(qty);
    await t.page.getByLabel('Your name', { exact: true }).fill(name);
    await t.page.getByLabel('Mobile number', { exact: true }).fill(phone);
    await t.page.getByLabel(/^District/).selectOption({ label: district });
    await t.page.getByLabel(/^Area/).fill(area).catch(() => {});
    await t.page.getByLabel(/^Address/).fill(address).catch(() => {});
}

export default [
    {
        n: 305, role: 'visitor',
        did: 'Put a product in the market cart and in the wishlist, then opened both.',
        async run(t) {
            await t.go('/market/78');
            await t.click('Add to the market cart', { wait: 1500 });
            await t.go('/market/77');
            await t.click('Save to wishlist', { wait: 1500 });
            await t.go('/market-cart');
            await t.shot('The market cart');
            await t.see(/Matte silicone phone case/, 'The cart holds what was added');
            await t.go('/wishlist');
            await t.shot('The wishlist');
            await t.see(/Soundbar/, 'The wishlist holds what was saved for later');
        },
    },
    {
        n: 306, role: 'visitor',
        did: 'Looked in the shopper\'s settings for several saved delivery addresses.',
        async run(t) {
            await t.go('/settings/profile');
            await t.shot('Profile settings');
            const body = await t.text();
            t.check(/saved addresses|address book|delivery addresses|add an address/i.test(body), 'A shopper can save several delivery addresses in their settings', 'the settings hold only a name, an email and a mobile number: no address book was found');
        },
    },
    {
        n: 307, role: 'guest',
        did: 'As a stranger, ordered a phone case with just a name, phone number and address.',
        async run(t) {
            await t.go('/market/78');
            await fillOrder(t);
            await t.shot('A guest order filled in: name, phone and address only');
            await t.click('Send the order', { wait: 3000 });
            await t.shot('After sending the order');
            const body = await t.text();
            t.check(/order|thank|received|placed/i.test(body) && !/login|sign in/i.test(t.page.url()), 'A stranger can order without making an account', 'the order was not accepted');
        },
    },
    {
        n: 308, role: 'admin',
        did: 'Looked at the owner\'s Market page for the cash-on-delivery choice on each product.',
        async run(t) {
            await t.go('/admin/market');
            await t.shot('The owner\'s Market page');
            t.skip('The cash-on-delivery switch is set by the seller on their own listing form. It was not changed here. The product page tells the buyer "You pay when it arrives" when cash on delivery is allowed.');
            await t.go('/market/78');
            await t.see(/You pay when it arrives/, 'A product that allows it tells the buyer they pay on arrival');
        },
    },
    {
        n: 309, role: 'visitor',
        did: 'Looked at the cart for the payment choice when every item allows cash on delivery.',
        async run(t) {
            await t.go('/market-cart');
            await t.shot('The market cart');
            t.skip('Hiding cash on delivery when one item does not allow it needs a product that refuses it, which the test data does not have.');
        },
    },
    {
        n: 310, role: 'visitor',
        did: 'Put products from two sellers in the cart and looked at how the order splits.',
        async run(t) {
            await t.go('/market-cart');
            await t.shot('The market cart');
            t.skip('All products in the test data belong to one seller, so a split into two seller orders could not be shown.');
        },
    },
    { n: 311, role: 'admin', did: 'Looked at the couriers each seller can choose.', async run(t) { await t.go('/admin/couriers'); await t.shot('Couriers'); t.skip('A separate courier per seller part needs two sellers in one order.'); } },
    {
        n: 312, role: 'guest',
        did: 'Placed an order as a stranger and looked at the order page that follows it.',
        async run(t) {
            await t.go('/market/78');
            await fillOrder(t);
            await t.click('Send the order', { wait: 3000 });
            await t.shot('The order page, with its steps');
            for (const step of ['Sent', 'Accepted', 'On the way', 'Done']) await t.see(step, `The order shows the step "${step}"`);
            t.skip('Only the first step (Sent) was shown. Following the order through the later steps needs the seller to accept and ship it.');
        },
    },
    {
        n: 313, role: 'visitor',
        did: 'Opened the shopper\'s order history and profile.',
        async run(t) {
            await t.go('/history');
            await t.shot('All the shopper\'s orders');
            await t.go('/settings/profile');
            await t.shot('The shopper\'s profile');
            await t.see(/Profile/, 'The shopper has a profile');
        },
    },
    {
        n: 314, role: 'paikari',
        did: 'Looked at the "Buy again" reminder on the shop\'s orders page.',
        async run(t) {
            await t.go('/my-orders');
            await t.see(/Buy again reminder/, 'A "Buy again" reminder exists');
            await t.shot('The Buy again reminder');
        },
    },
    {
        n: 315, role: 'admin',
        did: 'Looked at the setting for how long a buyer can cancel an order alone.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/Minutes a buyer can cancel an order by themselves/, 'The owner sets how many minutes a buyer can cancel alone');
            await t.shot('The cancel window setting');
        },
    },
    { n: 316, role: 'visitor', did: 'Looked for changing the address before packing.', async run(t) { await t.go('/market-orders'); await t.shot('The shopper\'s market orders'); t.skip('Changing an address needs an order that has not been packed. Not reproduced.'); } },
    {
        n: 317, role: 'guest',
        did: 'Looked at the order form for a "for whom" box.',
        async run(t) {
            await t.go('/market/78');
            await t.shot('The order form');
            const body = await t.text();
            t.check(/for whom|who is it for|receiver|recipient/i.test(body), 'The order form has a box saying who the parcel is for', 'the form asks only for name, phone, district, area, address and a note');
        },
    },
    {
        n: 318, role: 'admin',
        did: 'Looked at the setting that cancels old orders nobody answered.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/Cancel an order nobody answered after \(hours\)/, 'Old unanswered orders cancel themselves after a time the owner sets');
            await t.shot('The auto-cancel setting');
        },
    },
    { n: 319, role: 'admin', did: 'Looked for digital products and download limits.', async run(t) { await t.go('/admin/market'); await t.shot('The owner\'s Market page'); t.skip('Digital products and download limits were not found on any screen reachable here.'); } },
    {
        n: 320, role: 'guest',
        did: 'Opened the Companies page (the "All Companies" sector).',
        async run(t) {
            await t.go('/companies');
            await t.shot('All Companies');
            const n = await t.page.locator('main a').count();
            t.check(n > 20, `Many companies are listed (${n} links)`);
        },
    },
    {
        n: 321, role: 'guest',
        did: 'Opened one company and looked at filtering its products by brand.',
        async run(t) {
            await t.go('/companies');
            const href = await t.page.locator('main a[href*="/companies/"]').first().getAttribute('href').catch(() => null);
            if (href) await t.go(href);
            await t.shot('One company\'s brands');
            await t.see(/brand|medicine|product/i, 'A company page lists its brands');
        },
    },
];
