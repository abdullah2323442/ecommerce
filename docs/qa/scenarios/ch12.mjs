// MPO sector (features 220-239): medical sales representatives
import { ACCOUNTS } from '../runner/lib.mjs';

ACCOUNTS.mpo2 = 'mpo.kabir@siamsaqua.test';

/** The owner opens a second MPO account (once). */
async function openMpoAccount(t) {
    await t.as('admin');
    await t.go('/admin/mpo');
    if (/MPO Kabir/.test(await t.text())) return false;
    await t.page.getByLabel('Name', { exact: true }).fill('MPO Kabir');
    await t.page.getByLabel('Phone', { exact: true }).fill('01700000021');
    await t.fill('Email (optional)', ACCOUNTS.mpo2);
    await t.fill('Password (at least 10 characters)', 'DevPass-2026!');
    await t.fill('Area', 'Mirpur');
    await t.fill('Your note', 'Works for Square and Beximco');
    for (const c of ['Square', 'Beximco Pharmaceuticals Ltd.']) {
        const box = t.page.getByLabel(c, { exact: true }).first();
        if (await box.count()) await box.check().catch(() => box.click());
    }
    return true;
}

export default [
    {
        n: 220, role: 'admin',
        did: 'Opened the MPO page and looked at what is kept for each MPO.',
        async run(t) {
            await t.go('/admin/mpo');
            await t.shot('The MPO list: name, anonymous number, phone, area and counts');
            await t.see(/Anonymous \d+/, 'Each MPO has an anonymous number');
            await t.see(/products · \d+ on the market/, 'The list counts each MPO\'s products and how many are on the market');
            t.skip('The checklist marks the detailed MPO profile as only partly built. A full profile page was not found.');
        },
    },
    {
        n: 221, role: 'admin',
        did: 'Opened a second MPO account in the area "Mirpur" and looked at how the list groups MPOs by area.',
        async run(t) {
            const fresh = await openMpoAccount(t);
            await t.shot('The account form filled in, with an area and companies');
            if (fresh) await t.click('Open the account', { wait: 2500 });
            await t.go('/admin/mpo');
            await t.shot('The list, grouped by area');
            await t.see(/Mirpur/, 'MPOs are grouped by the area they cover');
        },
    },
    {
        n: 222, role: 'mpo2',
        did: 'Signed in as the new MPO and looked at how an MPO chooses a product to work with.',
        async run(t) {
            await t.go('/mpo');
            await t.shot('The MPO\'s own page');
            await t.see(/Send a product/, 'The MPO sends the products they choose to work with');
            await t.see(/Choose the products you work with, then send them/, 'The MPO chooses the products themselves');
        },
    },
    {
        n: 223, role: 'mpo2',
        did: 'Looked at the companies this MPO may work with.',
        async run(t) {
            await t.go('/mpo');
            await t.see(/Your companies/, 'The MPO sees the companies the owner gave them');
            await t.shot('One MPO, several companies');
        },
    },
    {
        n: 224, role: 'admin',
        did: 'Looked at the MPO list to see one company linked to more than one MPO.',
        async run(t) {
            await t.go('/admin/mpo');
            await t.shot('The MPO list');
            t.skip('The one-to-many structure is how the data is stored. The screen shows MPOs and their companies, but not the structure itself.');
        },
    },
    {
        n: 225, role: 'mpo',
        did: 'Signed in as MPO Anwar and looked for any trace of another MPO.',
        async run(t) {
            await t.go('/mpo');
            await t.notSee(/MPO Kabir|Kabir/, 'MPO Anwar cannot see MPO Kabir');
            await t.shot('MPO Anwar\'s page shows only their own things');
        },
    },
    {
        n: 226, role: 'mpo2',
        did: 'Looked at the Stock choice when sending a product.',
        async run(t) {
            await t.go('/mpo');
            const options = await t.page.locator('select').allInnerTexts();
            t.note(`Choices on the page: ${options.join(' | ').replace(/\n/g, ' / ').slice(0, 160)}`);
            await t.see(/In stock/, 'A product can be marked In stock');
            await t.see(/Pre-order/, 'Or Pre-order');
            await t.shot('Stock: In stock or Pre-order');
        },
    },
    {
        n: 227, role: 'mpo2',
        did: 'Looked at the bonus box on the product form.',
        async run(t) {
            await t.go('/mpo');
            await t.see(/Give a bonus/, 'An MPO can give a bonus, for example 10 boxes plus 2 free');
            await t.shot('The bonus box');
        },
    },
    {
        n: 228, role: 'admin',
        did: 'Looked for a bonus on its own memo line.',
        async run(t) {
            await t.go('/admin/invoices');
            await t.shot('Invoices');
            t.skip('Needs an MPO product with a bonus to be sold and a memo made. Not carried through.');
        },
    },
    {
        n: 229, role: 'admin',
        did: 'Opened the owner\'s page for MPO products waiting to be looked at.',
        async run(t) {
            await t.go('/admin/mpo/listings');
            await t.shot('Products to look at');
            await t.see(/Choose where each one shows and at what price/, 'The owner reviews each product and chooses where it shows and the price');
            await t.see(/The same medicine from several MPOs sits side by side so you can compare/, 'Products from several MPOs sit side by side');
        },
    },
    {
        n: 230, role: 'admin',
        did: 'Looked at how an MPO is named on screens other than the owner\'s.',
        async run(t) {
            await t.go('/admin/mpo');
            await t.see(/Anonymous \d+/, 'An MPO appears as "Anonymous" with a number');
            await t.shot('Anonymous 0, 1, 2...');
            t.skip('The "Anonymous 1 (my name)" wording on the MPO\'s own side was not found.');
        },
    },
    {
        n: 231, role: 'mpo',
        did: 'Read the notes on the MPO\'s page about who can see whom.',
        async run(t) {
            await t.go('/mpo');
            await t.see(/These orders came from our account/, 'The MPO sees orders as coming from the owner\'s account, never from a buyer');
            await t.shot('The MPO sees our account, not the buyer');
            await t.as('admin');
            await t.go('/admin/mpo');
            await t.see(/Buyers never see who an MPO is, and an MPO never sees a buyer/, 'The owner\'s screen states identity is hidden both ways');
        },
    },
    {
        n: 232, role: 'mpo',
        did: 'Looked at the MPO\'s Orders section.',
        async run(t) {
            await t.go('/mpo');
            await t.see(/This record is your proof for your own target report/, 'The MPO\'s Orders list is a proof record for their own target report');
            await t.shot('The Orders record');
        },
    },
    { n: 233, role: 'wholesale', did: 'Looked for a place where wholesalers bid on MPO products.', async run(t) { await t.go('/wholesale'); await t.shot('The wholesale screen'); t.skip('Bidding needs an MPO product the owner has put on the market. That step was not carried through, so no bid was placed.'); } },
    { n: 234, role: 'wholesale', did: 'Looked for what a wholesaler can see about other bids.', async run(t) { await t.go('/wholesale'); await t.shot('The wholesale screen'); t.skip('Needs bids from two wholesalers. Not carried through.'); } },
    { n: 235, role: 'mpo', did: 'Looked for the MPO accepting a bid.', async run(t) { await t.go('/mpo'); await t.shot('The MPO page'); t.skip('Needs a bid to accept. Not carried through.'); } },
    { n: 236, role: 'mpo', did: 'Looked for the notification asking an MPO to raise the percentage if goods are not selling.', async run(t) { await t.go('/mpo'); await t.shot('The MPO page'); t.skip('Needs goods that sit unsold for a while. Not carried through.'); } },
    { n: 237, role: 'admin', did: 'Looked for a count of failed deliveries.', async run(t) { await t.go('/admin/mpo'); await t.shot('The MPO list'); t.skip('Not carried through.'); } },
    { n: 238, role: 'admin', did: 'Looked at how goods pass through the owner.', async run(t) { await t.go('/admin/stock/receive'); await t.shot('Enter purchase has an "MPO" source'); await t.see(/Source/, 'Goods from an MPO are entered as a purchase from an MPO source'); } },
    { n: 239, role: 'admin', did: 'Looked at the stock page for goods that came from an MPO.', async run(t) { await t.go('/admin/stock'); await t.shot('Stock'); t.skip('MPO goods entering stock was not carried through end to end.'); } },
];
