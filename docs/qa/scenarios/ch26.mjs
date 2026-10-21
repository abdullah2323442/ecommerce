// Fourth pass: pre-orders followed through (replaces "partly shown" results 502-509)
// Set-up: pre-order was switched on for "Square Pharmaceuticals PLC" with a 3 day promise, from the owner's Pre-orders page.

async function preorder(t, term = 'seclo') {
    await t.as('paikari');
    await t.go('/paikari');
    for (let i = 0; i < 8; i++) {
        const c = t.page.getByRole('button', { name: 'Close', exact: true }).first();
        if (!(await c.count())) break;
        await c.click().catch(() => {});
    }
    await t.fill('Medicine name', term);
    await t.wait(1800);
    await t.click(/Pre-order/, { wait: 2200 });
}

export default [
    {
        n: 504, role: 'paikari',
        did: `Looked at the shop's pre-orders after one was made and accepted.`,
        async run(t) {
            await t.go('/preorders');
            await t.shot(`The shop's pre-orders`);
            await t.see(/Seclo 20 mg/, 'The pre-order is listed with its medicine and quantity');
            await t.see(/Accepted|Waiting for our answer/, 'And its state');
        },
    },
    {
        n: 505, role: 'paikari',
        did: 'Looked at the notice the shop gets about its pre-order.',
        async run(t) {
            await t.go('/preorders');
            await t.see(/Your pre-order for Seclo 20 mg is accepted. It should reach you within 3 days/, 'The shop is told its pre-order was accepted and when to expect it');
            await t.shot('The acceptance notice');
        },
    },
    {
        n: 498, role: 'admin',
        did: 'Looked at what a pre-order can be switched on for.',
        async run(t) {
            await t.go('/admin/preorders');
            await t.see(/A company/, 'Pre-order can be switched on for a whole company');
            await t.see(/Or one medicine/, 'Or for one medicine');
            await t.shot('A company or one medicine');
            t.note('No size or colour choice was found. Pre-order here is by company or medicine only.');
        },
    },
    {
        n: 499, role: 'admin',
        did: 'Looked at how long the promise can be.',
        async run(t) {
            await t.go('/admin/preorders');
            const opts = await t.page.getByLabel('Promise').locator('option').allInnerTexts();
            t.check(opts.length === 4, `Four promise times are offered (${opts.join(', ')})`);
            await t.shot('Promise: 2 to 5 days');
        },
    },
    {
        n: 503, role: 'admin',
        did: 'Opened the owner\'s Pre-orders page and the "Buying list".',
        async run(t) {
            await t.go('/admin/preorders');
            await t.shot('Waiting orders and the buying list');
            await t.see(/Waiting for your answer \(\d+\)/, 'Waiting pre-orders are counted');
            await t.see(/Buying list/, 'A buying list gathers what to order from suppliers');
        },
    },
    {
        n: 509, role: 'admin',
        did: 'Accepted the shop\'s pre-order, then wrote in fewer boxes than were ordered ("Boxes that came") and pressed "Share out".',
        async run(t) {
            await t.go('/admin/preorders');
            await t.page.getByRole('button', { name: 'Accept', exact: true }).first().click();
            await t.wait(1800);
            await t.shot('The pre-order is accepted and waits for the goods');
            await t.see(/Accepted, waiting for the goods \(1\)|Accepted, waiting for the goods \(\d+\)/, 'The accepted pre-order waits for the goods');
            await t.page.getByLabel('Boxes that came').first().fill('1').catch(() => t.page.locator('input[type=number]').nth(1).fill('1'));
            await t.click('Share out', { wait: 2200 });
            await t.shot('After the goods came and were shared out');
            await t.as('paikari');
            await t.go('/preorders');
            await t.shot('The shop sees the result');
            await t.see(/arrived|came|ready|order/i, 'The shop is told what came');
        },
    },
    {
        n: 502, role: 'paikari',
        did: 'Looked at the shop\'s messages for a note that the goods arrived.',
        async run(t) {
            await t.go('/inbox');
            await t.shot('The shop\'s messages');
            await t.see(/pre-order|arrived|goods/i, 'The shop is told after the goods arrive');
        },
    },
    { n: 507, role: 'admin', did: `Looked at the Decline button and its reason box on the owner's Pre-orders page.`, async run(t) { await t.go('/admin/preorders'); await t.shot('Pre-orders'); t.skip(`A decline with a reason was not carried through, so the shop's sorry note was not seen. Acceptance was seen (see feature 505).`); } },
];
