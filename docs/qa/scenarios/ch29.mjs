// Seventh pass: an MPO product on the market, bid on by a wholesaler (replaces "partly shown" results 233-235)
// Set-up through the screens: MPO Kabir chose Napa 500 mg (Square) under his company, sent 20 boxes "in stock"; the owner approved it for
// bulk buyers at 280 a box from "Products to look at".
import { ACCOUNTS } from '../runner/lib.mjs';

ACCOUNTS.mpo2 = 'mpo.kabir@siamsaqua.test';

async function wholesaleSearch(t) {
    await t.as('wholesale');
    await t.go('/wholesale');
    await t.page.getByLabel('Medicine name').fill('napa');
    await t.wait(1800);
}

export default [
    {
        n: 233, role: 'wholesale',
        did: 'As a wholesaler, found the MPO product among "Other offers", asked for a 12% discount on it and sent the offer.',
        async run(t) {
            await wholesaleSearch(t);
            await t.see(/Other offers[\s\S]{0,80}Anonymous 1/, 'The MPO product is listed as "Other offers", under an anonymous name');
            await t.page.getByRole('button', { name: 'Offer my price' }).first().click();
            await t.wait(900);
            await t.page.getByLabel('Discount I ask for (%)').fill('12');
            await t.shot('A wholesaler asks for a 12% discount');
            await t.click('Send my offer', { wait: 2200 });
            await t.shot('After sending the offer');
            await t.see(/Your offer|12%|offer.{0,30}(sent|waiting)/i, 'The wholesaler sees that the offer was sent');
        },
    },
    {
        n: 234, role: 'wholesale',
        did: 'Looked at the offer again after sending the bid, and read what the wholesaler sees about it.',
        async run(t) {
            await wholesaleSearch(t);
            await t.shot('The wholesaler sees only their own offer');
            await t.see(/Your offer: 12% off, 1 boxes\. Waiting\./, 'The wholesaler sees their own offer and that it is waiting');
            await t.notSee(/Anonymous 2/, 'No other bidder appears anywhere');
            await t.page.getByRole('button', { name: 'Offer my price' }).first().click().catch(() => {});
            t.skip('Only one wholesaler account exists in this copy, so a second bidder could not be set up to prove that bids are hidden from each other. The screen shows only the wholesaler\'s own offer.');
        },
    },
    {
        n: 235, role: 'mpo2',
        did: 'As the MPO, opened the portal, found the bid on the product and pressed Accept.',
        async run(t) {
            await t.go('/mpo');
            await t.page.getByText('What you sent').scrollIntoViewIfNeeded();
            await t.shot('The MPO\'s portal: the bid under the product');
            const body = await t.text();
            t.check(/12\s*%/.test(body) && /Accept/i.test(body), 'The MPO sees the bid (12%) and a button to accept it', 'no bid or no Accept button was shown');
            await t.page.getByRole('button', { name: /^Accept/ }).first().click();
            await t.wait(2200);
            await t.shot('After accepting');
            await t.see(/Napa 500 mg\s*1 boxes\s*Confirmed/, 'The accepted bid becomes a confirmed order in the MPO\'s own record');
            await t.see(/No offers on this yet/, 'And the bid is no longer waiting');
        },
    },
];
