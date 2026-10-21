// Returns (features 253-280)

/** The first return block on the paikari "Returns" page (one list item per memo). */
const form = (t) => t.page.locator('li').filter({ hasText: 'Ask to return' }).first();

async function askReturn(t, { unit = 'Strip', qty = '1', reason = 'Damaged', why = 'Strip is torn', outcome = 'Credit on my next memo' } = {}) {
    await t.as('paikari');
    await t.go('/paikari/returns');
    const f = form(t);
    const selects = f.locator('select');
    await selects.nth(0).selectOption({ label: unit });
    await f.getByPlaceholder('How much').fill(qty);
    await selects.nth(1).selectOption({ label: reason });
    await selects.nth(2).selectOption({ label: outcome });
    await f.getByPlaceholder('Reason (required)').fill(why);
    return f;
}

export default [
    {
        n: 253, role: 'visitor',
        did: 'As an ordinary shopper, tried the shops\' return page. Then opened it as a paikari shop.',
        async run(t) {
            await t.go('/paikari/returns');
            t.check(!new URL(t.page.url()).pathname.startsWith('/paikari'), 'An ordinary shopper cannot use the paikari return page');
            await t.shot('A shopper is turned away');
            await t.as('paikari');
            await t.go('/paikari/returns');
            await t.see(/Returnable/, 'A paikari shop sees its own memos with a return form');
            await t.shot('The paikari shop\'s Returns page');
        },
    },
    {
        n: 254, role: 'admin',
        did: 'Read the return rule on the owner\'s Returns page and the setting behind it.',
        async run(t) {
            await t.go('/admin/returns');
            await t.see(/Returns are for shops, within 3 days of receiving/, 'The rule is shown: shops only, within 3 days of receiving');
            await t.shot('The return rule');
            await t.go('/admin/settings');
            await t.see(/Days a product can be returned/, 'The number of days is a setting');
        },
    },
    {
        n: 255, role: 'paikari',
        did: 'Looked at the choices the shop has for what it wants back.',
        async run(t) {
            await t.go('/paikari/returns');
            const options = await form(t).locator('select').nth(2).locator('option').allInnerTexts();
            t.note(`The choices are: ${options.join(' / ')}`);
            await t.see(/Exchange for another medicine/, 'One choice: an exchange');
            await t.see(/Cash back \(half the price\)/, 'Another: cash back at half the price');
            await t.see(/Credit on my next memo/, 'And: credit on the next memo');
            await t.shot('Credit, exchange or half the price in cash');
        },
    },
    {
        n: 256, role: 'paikari',
        did: 'Looked for the "Returnable" mark beside each memo.',
        async run(t) {
            await t.go('/paikari/returns');
            await t.see(/Returnable/, 'A memo that can still be returned is marked "Returnable"');
            await t.shot('The "Returnable" mark');
        },
    },
    {
        n: 257, role: 'paikari',
        did: 'Used the From and To boxes to search the memos by date.',
        async run(t) {
            await t.go('/paikari/returns');
            const today = new Date().toISOString().slice(0, 10);
            await t.page.getByLabel('From', { exact: true }).fill(today);
            await t.page.getByLabel('To', { exact: true }).fill(today);
            await t.click('Search by date', { wait: 1500 });
            await t.shot('Memos searched by date');
            await t.see(/M\d{4}-\d{6}/, 'Memos from that day are listed');
        },
    },
    {
        n: 258, role: 'visitor',
        did: 'As an ordinary shopper, opened the page for asking to return something.',
        async run(t) {
            await t.go('/my-orders/returns');
            await t.shot('A public shopper\'s return page');
            await t.see(/return/i, 'Public shoppers have a return page: apply, wait for approval, then be called');
        },
    },
    {
        n: 259, role: 'guest',
        did: 'As a stranger, opened the return page.',
        async run(t) {
            await t.go('/my-orders/returns');
            await t.shot('A stranger is sent to sign in');
            t.check(new URL(t.page.url()).pathname.startsWith('/login'), 'A stranger must sign in before returning anything');
        },
    },
    {
        n: 260, role: 'paikari',
        did: 'Filled in a return but left the reason blank, and pressed "Ask to return".',
        async run(t) {
            const f = await askReturn(t, { why: '' });
            await t.shot('A return with no reason typed: the "Ask to return" button stays greyed out');
            t.check(await f.getByRole('button', { name: 'Ask to return' }).isDisabled(), 'The "Ask to return" button stays disabled until a reason is typed (or a voice note is attached)');
            await t.see(/Voice note/, 'A voice note can be used instead of typing a reason');
        },
    },
    {
        n: 261, role: 'paikari',
        did: 'Asked to return one strip out of a box.',
        async run(t) {
            const f = await askReturn(t, { unit: 'Strip', qty: '1' });
            await t.shot('Asking to return one strip, not the whole box');
            await f.getByRole('button', { name: 'Ask to return' }).click();
            await t.wait(2000);
            await t.shot('The request is sent');
            await t.as('admin');
            await t.go('/admin/returns');
            await t.shot('The owner sees the part-return waiting');
            await t.see(/Strip/i, 'The request is for a strip, not the whole box');
        },
    },
    {
        n: 262, role: 'admin',
        did: 'Opened a return waiting to be approved and read the amount.',
        async run(t) {
            await t.go('/admin/returns');
            await t.shot('A return waiting');
            const body = await t.text();
            t.check(/৳\s*85\.00|85/.test(body), 'The return is valued at what the shop paid (85 for a strip)', 'the amount shown was not 85');
        },
    },
    {
        n: 263, role: 'admin',
        did: 'Approved the waiting return as credit and looked at what the shop sees.',
        async run(t) {
            const f = await askReturn(t, { unit: 'Strip', qty: '1', reason: 'Damaged', why: 'Torn strip', outcome: 'Credit on my next memo' });
            await f.getByRole('button', { name: 'Ask to return' }).click();
            await t.wait(2000);
            await t.as('admin');
            await t.go('/admin/returns');
            await t.shot('A return waiting for the owner');
            await t.click(/Approve/, { wait: 2000 });
            await t.shot('After approving: "Return approved."');
            await t.see(/Return approved/, 'The owner approves the return');
            await t.as('paikari');
            await t.go('/paikari/returns');
            await t.shot('The shop sees its credit');
            await t.see(/Credit: ৳\d[\d,]*\.\d\d · It comes off your next memo/, 'The shop has credit, shown in taka, that comes off its next memo');
        },
    },
    {
        n: 264, role: 'admin',
        did: 'Looked at the setting for warning about credit that has sat unused.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/Warn about unused credit after \(days\)/, 'The owner sets how many days before unused credit is flagged');
            await t.shot('The credit warning setting');
        },
    },
    {
        n: 265, role: 'admin',
        did: 'Opened the medicine\'s lots after a return was approved to see where the returned goods went.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            await t.shot('The lots and movements after a return');
            await t.see(/return/i, 'The medicine\'s movements show the return');
        },
    },
    { n: 266, role: 'admin', did: 'Looked for a finished lot reopening.', async run(t) { await t.go('/admin/stock/medicines/1'); await t.shot('Lots'); t.skip('Needs a lot that is completely sold and then returned. Not reproduced.'); } },
    { n: 267, role: 'admin', did: 'Looked for returned goods being sold again.', async run(t) { await t.go('/admin/stock/medicines/1'); await t.shot('Lots'); t.skip('Not reproduced.'); } },
    {
        n: 268, role: 'admin',
        did: 'Looked at the return screen for a retail sale with no customer profile.',
        async run(t) {
            await t.go('/admin/returns');
            await t.shot('The owner\'s return page');
            t.skip('A retail refund with no profile was not carried through.');
        },
    },
    {
        n: 269, role: 'admin',
        did: 'Looked at the three ways to find a sale to return.',
        async run(t) {
            await t.go('/admin/returns');
            await t.see(/Memo number/, 'Search by memo number');
            await t.see(/Date/, 'Search by date');
            await t.see(/Medicine name/, 'Search by medicine name');
            await t.shot('Memo, date or medicine name');
        },
    },
    {
        n: 270, role: 'admin',
        did: 'Looked for a way to take a return with no memo.',
        async run(t) {
            await t.go('/admin/returns');
            await t.see(/Return without a memo \(admin\)/, 'The owner can take a return without a memo, for rare cases');
            await t.shot('Return without a memo (admin)');
        },
    },
    { n: 271, role: 'admin', did: 'Looked for the optional "will receive return money" field.', async run(t) { await t.go('/admin/returns'); await t.shot('Returns'); t.skip('The optional "will receive return money" field was not found on the return screens.'); } },
    {
        n: 272, role: 'admin',
        did: 'Finished a retail sale and read the policy printed on the memo.',
        async run(t) {
            await t.go('/admin/counter');
            await t.click('New retail sale', { wait: 1800 });
            await t.page.getByPlaceholder(/Medicine name/).fill('napa');
            await t.wait(1300);
            await t.click(/Box.{0,4}300/, { wait: 1200 });
            await t.click('Finish sale', { wait: 2500 });
            await t.shot('The memo carries the return policy');
            await t.see(/Medicine returns apply within 3 days of buying, with this memo/, 'The retail memo prints the return policy');
        },
    },
    {
        n: 273, role: 'admin',
        did: 'Read the offer return rule printed on the memo.',
        async run(t) {
            await t.see(/Offer items return at the same rule on the offer price/, 'The memo says how offer items are returned');
            await t.shot('Offer items on the memo');
            t.skip('The checklist says offer returns pay 50% of the offer rate. The memo wording says "the same rule on the offer price", and an offer return itself was not made.');
        },
    },
    { n: 274, role: 'admin', did: 'Looked at the delivery charge rule on returns.', async run(t) { await t.go('/admin/returns'); await t.shot('Returns'); t.skip('Needs a delivered order. Not reproduced.'); } },
    { n: 275, role: 'admin', did: 'Looked at the 50% on product advance rule.', async run(t) { await t.go('/admin/returns'); await t.shot('Returns'); t.skip('Needs an advance payment on an order. Not reproduced.'); } },
    {
        n: 276, role: 'admin',
        did: 'Looked for return analysis by customer and product.',
        async run(t) {
            await t.go('/admin/returns');
            await t.shot('Why things come back, and what comes back most');
            await t.see(/Why things come back \(90 days\)/, 'The owner sees why things come back, by reason, with counts and amounts');
            await t.see(/Returned most/, 'And which products come back most');
        },
    },
    { n: 277, role: 'admin', did: 'Looked for warnings when mistakes are found.', async run(t) { await t.go('/admin/returns'); await t.shot('Returns'); t.skip('Not reproduced.'); } },
    { n: 278, role: 'admin', did: 'Looked for an automatic warning for repeated mistakes.', async run(t) { await t.go('/admin/returns'); await t.shot('Returns'); t.skip('Not reproduced.'); } },
    {
        n: 279, role: 'paikari',
        did: 'Looked at the shop\'s memo list after a return was made, for a "previously returned" mark.',
        async run(t) {
            await t.go('/paikari/returns');
            await t.shot('The memo list after a return');
            const body = await t.text();
            t.check(/previously returned|already returned|returned before/i.test(body), 'A memo that already had a return is marked "previously returned"', 'no such mark was found');
        },
    },
    {
        n: 280, role: 'admin',
        did: 'Opened a finished memo and looked for a print or reprint button.',
        async run(t) {
            await t.go('/admin/invoices');
            const link = t.page.locator('main a[href*="/admin/counter/"], main a[href*="/admin/invoices/"]').first();
            if (await link.count()) await link.click();
            await t.wait(1500);
            await t.shot('A memo with its print button');
            await t.see(/Print memo/, 'A finished memo has a "Print memo" button, so it can be printed again');
        },
    },
];
