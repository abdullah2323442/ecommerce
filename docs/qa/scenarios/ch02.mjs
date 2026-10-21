// Pricing (features 22-35)

/** What a paikari shop is charged for a box of Napa 500 mg (Square), as it appears on their ordering screen. */
async function napaBox(t, shot) {
    await t.as('paikari');
    await t.go('/paikari');
    for (let i = 0; i < 8; i++) {
        const close = t.page.getByRole('button', { name: 'Close' }).first();
        if (!(await close.count())) break;
        await close.click().catch(() => {});
        await t.page.waitForTimeout(300);
    }
    await t.fill('Medicine name', 'napa');
    await t.wait(1800);
    await t.page.getByText('Find a medicine').first().scrollIntoViewIfNeeded().catch(() => {});
    const body = await t.text();
    const m = body.match(/Napa 500 mg\s*Square\s*Box\s*৳([\d,.]+)\s*([\d.]+)%/);
    if (shot) await t.shot(shot);
    return m ? { price: Number(m[1].replace(/,/g, '')), pct: Number(m[2]) } : null;
}

async function sectionHeadingOf(t, labelText) {
    return t.page.evaluate((txt) => {
        const lab = [...document.querySelectorAll('label')].find((l) => l.innerText.includes(txt));
        if (!lab) return null;
        let n = lab;
        while (n && !n.querySelector?.('h2,h3')) n = n.parentElement;
        const h = n?.querySelector('h2,h3');
        return h ? h.innerText.trim() : null;
    }, labelText);
}

async function setManualRate(t, mode, value) {
    await t.as('admin');
    await t.go('/admin/customers/4');
    await t.fill('Search a medicine', 'napa');
    await t.wait(1200);
    await t.click(/Napa 500 mg\s*tablet/i);
    if (mode === 'taka') await t.click('Taka price per piece');
    await t.page.locator('input[type=number]').last().fill(String(value));
    await t.shot(mode === 'taka' ? 'Typing a fixed taka price for this shop on Napa 500 mg' : `Typing ${value} percent off for this shop on Napa 500 mg`);
    await t.click('Save rate', { wait: 1500 });
}

/** Clean-up only: put the shop back on tier B. Never counts as a result. */
async function restoreTierB(t) {
    await t.as('admin');
    await t.go('/admin/customers/4');
    await t.page.locator('select').first().selectOption({ label: 'B (15%)' }).catch(() => {});
    const save = t.page.getByRole('button', { name: 'Save tier' });
    if (await save.isEnabled().catch(() => false)) {
        await save.click();
        await t.wait(1200);
    }
}

async function removeManualRates(t) {
    await t.as('admin');
    await t.preConfirm();
    await t.go('/admin/customers/4');
    for (let i = 0; i < 5; i++) {
        const del = t.page.getByRole('button', { name: /remove|delete/i }).first();
        if (!(await del.count())) break;
        await del.click().catch(() => {});
        await t.wait(900);
    }
}

export default [
    {
        n: 22,
        did: 'Opened the Tiers page to see the discount levels. Then signed in as a paikari shop on tier B and looked at what it is charged for Napa 500 mg. Finally added a new tier "D".',
        async run(t) {
            await t.go('/admin/tiers');
            await t.see('A', 'Tier A exists');
            await t.see('10%', 'Tier A gives 10%');
            await t.see('15%', 'Tier B gives 15%');
            await t.see('20%', 'Tier C gives 20%');
            await t.shot('The owner\'s Tiers page: three discount levels');
            const r = await napaBox(t, 'A paikari shop on tier B: the box printed at 300 is charged 255');
            t.check(r && r.price === 255 && r.pct === 15, `A tier B shop pays 255 for a box printed at 300, which is 15% off (saw ${r ? r.price + ' at ' + r.pct + '%' : 'nothing'})`);
            await t.as('admin');
            await t.go('/admin/tiers');
            await t.fill('Name, for example A', 'D');
            await t.fill('Discount %', '25');
            await t.fill('Monthly target', '500000');
            await t.shot('Adding a fourth tier, D, at 25%');
            await t.click('Add tier', { wait: 1500 });
            await t.see('25%', 'The new tier D appears with its 25% discount');
            await t.shot('Tier D is now on the list: the number of tiers is not fixed');
        },
    },
    {
        n: 23,
        did: 'Opened the medicine\'s screen looking for a place where the owner sets a discount that applies to a whole company.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            const head = await sectionHeadingOf(t, 'For every medicine of');
            await t.shot('The only company-wide percentage on the medicine screen');
            t.check(head && !/purchase/i.test(head), 'A company-wide discount for customers can be set from a screen', `the only company-wide percentage is under "${head}", which is what the shop pays its supplier, not what a customer gets`);
            t.note('The pricing rule for company-wide discounts exists in the system and is covered by automated tests, but no screen lets the owner set one.');
        },
    },
    {
        n: 24,
        did: 'Looked for a place where the owner sets a discount for one single medicine that overrides the company discount.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            const head = await sectionHeadingOf(t, 'For this medicine');
            await t.shot('The only per-medicine percentage on the medicine screen');
            t.check(head && !/purchase/i.test(head), 'A per-medicine discount for customers can be set from a screen', `the only per-medicine percentage is under "${head}", which is the shop's buying price`);
            t.note('The pricing rule exists and is tested, but no screen lets the owner set it.');
        },
    },
    {
        n: 25,
        did: 'Gave Karim Pharmacy a hand-set 18% price on Napa 500 mg, then signed in as Karim to see the price.',
        async run(t) {
            await t.preConfirm();
            await setManualRate(t, 'percent', 18);
            await t.see(/Napa 500 mg/, 'The special rate shows in the shop\'s Manual rates list');
            await t.shot('The special rate is saved on the shop\'s profile');
            const r = await napaBox(t, 'Karim now sees the box at 246, not 255');
            t.check(r && r.price === 246 && r.pct === 18, `The shop pays 246 for a box printed at 300 (18% off) instead of the tier price (saw ${r ? r.price + ' at ' + r.pct + '%' : 'nothing'})`);
        },
    },
    {
        n: 26,
        did: 'Set a hand-set rate, saw it beat the tier rate, then removed it and watched the price fall back to the tier rate.',
        async run(t) {
            await t.preConfirm();
            await setManualRate(t, 'percent', 18);
            let r = await napaBox(t, 'With the hand-set rate: 246');
            t.check(r && r.price === 246, `Hand-set rate wins over the tier rate (saw ${r?.price})`);
            await removeManualRates(t);
            await t.shot('The hand-set rate has been removed');
            r = await napaBox(t, 'After removing it the shop is back on its tier price: 255');
            t.check(r && r.price === 255, `Without the hand-set rate the tier rate applies again (saw ${r?.price})`);
            t.skip('Only two of the four layers could be shown (hand-set and tier). The company and medicine layers have no screen to set them.');
        },
    },
    {
        n: 27,
        did: 'Set a hand-set 18% rate, moved the shop from tier B to tier C, and checked the hand-set price did not change.',
        async run(t) {
            await t.preConfirm();
            await setManualRate(t, 'percent', 18);
            await t.go('/admin/customers/4');
            await t.pick('Tier', 'C (20%)');
            await t.click('Save tier', { wait: 1500 });
            await t.shot('The shop is moved to tier C');
            const r = await napaBox(t, 'After the tier change the hand-set price is still 246');
            t.check(r && r.price === 246 && r.pct === 18, `The hand-set price stays at 246 after the tier change (saw ${r?.price} at ${r?.pct}%)`);
        },
    },
    {
        n: 28,
        did: 'Gave the shop a fixed taka price on Napa 500 mg. First typed 250, thinking of the price of a box. Then typed 8.2, the price of one piece.',
        async run(t) {
            await t.preConfirm();
            await setManualRate(t, 'taka', 250);
            await t.shot('Typed 250, a box price, into the "Taka price per piece" box: the system explains the mistake');
            await t.see(/A taka price is for one piece, and this medicine is printed at/, 'A box price typed by mistake is refused, and the message says a taka price is for one piece and what the printed piece price is');
            let r;
            await setManualRate(t, 'taka', 8.2);
            r = await napaBox(t, 'Typed 8.2 as the price of one piece: the box is now 246');
            t.check(r && r.price === 246, `A fixed taka price typed per piece works (8.2 per piece gives ${r ? r.price : 'nothing'} for a box)`);
        },
    },
    {
        n: 29,
        did: 'On the rate form, flipped the switch between "Percent off" and "Taka price".',
        async run(t) {
            await t.as('admin');
            await t.go('/admin/customers/4');
            await t.click('Percent off');
            await t.shot('Percent mode of the rate form');
            await t.see(/Percent off/, 'The form can be set to percent');
            await t.click('Taka price per piece');
            await t.shot('Taka mode of the rate form');
            await t.see(/Taka price/, 'The same form can be switched to a fixed taka price');
            await removeManualRates(t);
            await restoreTierB(t);
        },
    },
    {
        n: 30,
        did: 'On the medicine screen, added a step to "Buy more, pay less": from 5 boxes, 10% off each. Saved it and reloaded to see it was kept.',
        async run(t) {
            await t.go('/admin/stock/medicines/1/edit');
            await t.shot('The "Buy more, pay less" section before any step is added');
            await t.click('Add a step');
            await t.pick('css=select[aria-label="Unit"]', 'Box');
            await t.fill('css=input[aria-label="From how many"]', '5');
            await t.fill('css=input[aria-label="Discount"]', '10');
            await t.shot('A step typed in: from 5 boxes, 10 percent off each');
            await t.page.getByRole('button', { name: 'Save' }).last().click();
            await t.wait(1500);
            await t.go('/admin/stock/medicines/1/edit');
            const v = await t.page.locator('input[aria-label="From how many"]').first().inputValue().catch(() => '');
            t.check(v === '5', `The step is kept after reloading the page (the field shows "${v}")`);
            await t.shot('After reloading, the step is still there');
        },
    },
    {
        n: 31, role: 'guest',
        did: 'Opened the public page for Napa 500 mg as a stranger and looked for the quantity discount.',
        async run(t) {
            await t.go('/medicines/1');
            await t.see(/5\s*(or more|boxes|\+)?.*10\s*%|10\s*%.*5/is, 'The public page shows that buying 5 or more boxes earns 10% off');
            await t.shot('The public medicine page shows the quantity discount steps');
            t.note('The discount is the same for every public buyer.');
        },
    },
    {
        n: 32, role: 'guest',
        did: 'As a stranger, put 5 boxes of Napa 500 mg in the cart to see the quantity discount applied in the price.',
        async run(t) {
            await t.go('/medicines/1');
            await t.page.locator('input[type=number]').last().fill('5');
            await t.click('Add to cart', { wait: 1500 });
            await t.go('/cart');
            await t.shot('The cart with 5 boxes: the quantity discount is already in the price');
            await t.see(/1,350/, 'Five boxes cost 1,350 (270 each, 10% off the printed 300), not 1,500');
            t.skip('Only the quantity step was shown in the cart. The full order (price, then quantity, then token, then credit) happens inside the system and was not shown screen by screen.');
        },
    },
    {
        n: 33, role: 'admin',
        did: 'Tried to change the printed price of a box from 300 to 30, a drop that looks like a typing mistake.',
        async run(t) {
            await t.go('/admin/stock/medicines/1/edit');
            await t.fill('Printed price of a box', '30');
            await t.shot('Typing 30 where 300 was');
            await t.click('css=button:has-text("Save")', { wait: 1500 });
            await t.shot('What the system says');
            await t.see(/mistake|check|sure|drop|lower|warn/i, 'The system warns about an unusually big price drop');
        },
    },
    {
        n: 34, role: 'admin',
        did: 'Changed the shop\'s tier and looked at the Tier changes list on the customer page.',
        async run(t) {
            await t.go('/admin/customers/4');
            await t.pick('Tier', 'C (20%)');
            await t.click('Save tier', { wait: 1500 });
            await t.go('/admin/customers/4');
            await t.see(/Tier changes/, 'The customer page has a Tier changes list');
            await t.see(/B.*C|C.*B/, 'The change between tiers is written down');
            await t.shot('The customer page lists every tier change');
            await restoreTierB(t);
        },
    },
    {
        n: 35, role: 'admin',
        did: 'To be completed in the counter chapter, where invoices are made.',
        async run(t) {
            await t.go('/admin/invoices');
            await t.shot('Invoices');
            t.skip('Needs a sale first. Covered with the counter features.');
        },
    },
];
