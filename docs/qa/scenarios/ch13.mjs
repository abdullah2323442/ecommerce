// Medicine database (features 240-252)
// Note: no medicine in the sample data carries a prescription, cold-chain, controlled or inactive flag, and no screen sets them.
// For this chapter, Napa 500 mg by Beximco (id 4864) was flagged prescription, cold-chain and controlled, and Napa 80 mg (id 4865) was
// switched to inactive, directly in the test database, only to show how those flags are displayed.

export default [
    {
        n: 240, role: 'guest',
        did: 'As a stranger, searched the public medicine database for a generic name and looked at how many brands came back.',
        async run(t) {
            await t.go('/medicines?q=paracetamol');
            await t.shot('Searching the medicine database for "paracetamol"');
            const count = await t.page.locator('a[href*="/medicines/"]').count();
            t.check(count >= 20, `A large database answers: ${count} brands listed for one generic name`);
        },
    },
    {
        n: 241, role: 'guest',
        did: 'Opened one medicine\'s page and looked for its brand, generic name, company, strength and printed price.',
        async run(t) {
            await t.go('/medicines/1');
            await t.shot('One medicine\'s page');
            await t.see(/Napa/, 'The brand name');
            await t.see(/Square/, 'The company');
            await t.see(/500 mg/, 'The strength');
            await t.see(/৳300\.00/, 'The printed price (MRP)');
            await t.see(/Printed retail price/i, 'The page says it is the printed retail price');
            await t.see(/tablet/i, 'The form of the medicine');
        },
    },
    {
        n: 242, role: 'guest',
        did: 'Opened a medicine page and read the list "Same medicine, other brands".',
        async run(t) {
            await t.go('/medicines/4864');
            await t.see(/Same medicine, other brands/, 'The page offers other brands of the same medicine');
            await t.see(/Same generic, strength and form\. Cheapest first/, 'They share the generic, strength and form, with the cheapest first');
            await t.page.getByText('Same medicine, other brands').scrollIntoViewIfNeeded();
            await t.shot('Cheaper brands of the same generic medicine');
        },
    },
    {
        n: 243, role: 'admin',
        did: 'On the purchase form, typed a medicine name that is not in the database.',
        async run(t) {
            await t.go('/admin/stock/receive');
            const away = t.page.getByText('Throw it away');
            if (await away.count()) await away.click();
            await t.fill('Search a medicine', 'zzzqqxx made up medicine');
            await t.wait(1500);
            await t.shot('A made-up name finds nothing to choose');
            const choices = await t.page.getByRole('button', { name: /zzzqqxx/i }).count();
            t.check(choices === 0, 'A name that is not in the database cannot be chosen, so no stray medicine names get in');
        },
    },
    {
        n: 244, role: 'paikari',
        did: 'Opened the company list on the ordering screen.',
        async run(t) {
            await t.go('/paikari');
            const options = await t.page.locator('select').first().locator('option').count();
            await t.shot('A preset list of companies');
            t.check(options > 100, `A preset list of companies is offered (${options} companies)`);
        },
    },
    {
        n: 245, role: 'guest',
        did: 'Searched with a spelling mistake ("paracetmol") and with "napaa".',
        async run(t) {
            await t.go('/medicines?q=paracetmol');
            await t.shot('A misspelled search');
            const a = await t.page.locator('a[href*="/medicines/"]').count();
            t.check(a > 0, `"paracetmol" still finds paracetamol medicines (${a} found)`);
            await t.go('/medicines?q=napaa');
            const b = await t.page.locator('a[href*="/medicines/"]').count();
            t.check(b > 0, `"napaa" still finds Napa (${b} found)`);
        },
    },
    {
        n: 246, role: 'guest',
        did: 'Searched by the generic name and by the Bangla spelling of Napa.',
        async run(t) {
            await t.go('/medicines?q=paracetamol');
            await t.see(/Napa/, 'Searching the generic name finds the Napa brand');
            await t.go('/medicines?q=' + encodeURIComponent('নাপা'));
            await t.shot('Searching in Bangla');
            const n = await t.page.locator('a[href*="/medicines/"]').count();
            t.check(n > 0, `The Bangla spelling finds the medicine too (${n} found)`, 'no result for the Bangla spelling');
        },
    },
    {
        n: 247, role: 'guest',
        did: 'Looked for Napa 80 mg, which was switched to inactive in the test data.',
        async run(t) {
            await t.go('/medicines?q=napa');
            const body = await t.text();
            await t.shot('Napa search: the inactive medicine');
            const listed = /80 mg/.test(body);
            t.check(!listed || /inactive|not sold|discontinued/i.test(body), 'An inactive medicine is either hidden or clearly marked as inactive', 'it is listed like any other');
            t.note('No screen lets the owner switch a medicine to inactive. It was done in the test database.');
        },
    },
    {
        n: 248, role: 'guest',
        did: 'Opened the page of a medicine flagged as needing a prescription.',
        async run(t) {
            await t.go('/medicines/4864');
            await t.see(/Prescription required/, 'The page carries a "Prescription required" mark');
            await t.shot('The "Prescription required" mark');
        },
    },
    {
        n: 249, role: 'guest',
        did: 'Opened the same medicine, flagged as cold-chain.',
        async run(t) {
            await t.go('/medicines/4864');
            await t.see(/Keep refrigerated/, 'The page says "Keep refrigerated"');
            await t.see(/Must be kept cold, in a refrigerator/, 'And explains it in plain words');
            await t.shot('The "Keep refrigerated" mark');
        },
    },
    {
        n: 250, role: 'guest',
        did: 'Added the cold-chain medicine to the cart and opened the cart.',
        async run(t) {
            await t.go('/medicines/4864');
            await t.click('Add to cart', { wait: 1800 });
            await t.go('/checkout');
            await t.shot('Checkout with a cold-chain medicine');
            await t.see(/Something in your cart must stay cold/, 'Checkout says something in the cart must stay cold');
            await t.see(/our own delivery, packed with ice|cannot go by courier/, 'And that it goes by our own delivery, or cannot go by courier, so no normal courier is offered');
        },
    },
    {
        n: 251, role: 'guest',
        did: 'Opened the medicine flagged as controlled.',
        async run(t) {
            await t.go('/medicines/4864');
            await t.see(/Controlled/, 'The page carries a "Controlled" mark');
            await t.shot('The "Controlled" mark');
        },
    },
    {
        n: 252, role: 'guest',
        did: 'Searched for "napa" on the public site and looked at where the one medicine the shop actually holds appears.',
        async run(t) {
            await t.go('/medicines?q=napa');
            await t.shot('The public search for "napa"');
            const hrefs = await t.page.locator('a[href*="/medicines/"]').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
            const pos = hrefs.findIndex((h) => h.endsWith('/medicines/1'));
            t.check(pos === 0, 'The medicine in stock (Napa 500 mg, Square) comes first in the results', `it is number ${pos + 1} in the list`);
            await t.as('paikari');
            await t.go('/paikari');
            await t.fill('Medicine name', 'napa');
            await t.wait(1600);
            await t.shot('The paikari search puts the in-stock one first');
            const first = (await t.page.getByText(/^Napa/).first().innerText()).trim();
            t.note(`On the paikari ordering screen the first result is "${first}".`);
        },
    },
];
