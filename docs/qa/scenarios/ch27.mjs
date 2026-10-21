// Fifth pass: offers sold at the counter (replaces "partly shown" results 181-185, 199, 203, 205, 206)
// An offer is already running (Napa 500 mg, Square: 10% off, minimum 3 boxes, 8 boxes available), started on the owner's Offers page.

/** Open a sale to the shop "Karim" and clear anything left in that draft. */
async function shopSale(t) {
    await t.as('admin');
    await t.go('/admin/counter');
    await t.fill('css=#cust-q', 'Karim');
    await t.wait(1500);
    await t.click('css=:is(a,button):text-is("Open it"), :is(a,button):text-is("Start")', { wait: 2200 });
}

async function cancelDraft(t) {
    const c = t.page.getByRole('button', { name: 'Cancel draft', exact: true });
    if (await c.count()) {
        await c.first().click().catch(() => {});
        await t.wait(1500);
    }
}

async function searchNapa(t) {
    await t.page.getByLabel('Medicine name').fill('napa');
    await t.wait(1700);
}

/** Put N boxes of the offer into the draft with its Boxes box and Order button. */
async function orderOffer(t, boxes) {
    await t.page.getByLabel('Boxes').first().fill(String(boxes));
    await t.page.getByRole('button', { name: 'Order', exact: true }).first().click();
    await t.wait(1800);
}

export default [
    {
        n: 181, role: 'admin',
        did: 'Started a sale to a shop and searched for Napa 500 mg, then started a retail sale and searched again.',
        async run(t) {
            await shopSale(t);
            await searchNapa(t);
            await t.shot('Sale to a shop: the offer card appears in the search');
            await t.see(/OFFER/, 'The sale to a shop shows the offer');
            await cancelDraft(t);
            await t.go('/admin/counter');
            await t.click('New retail sale', { wait: 1800 });
            await searchNapa(t);
            await t.shot('Retail sale: no offer in the search');
            await t.notSee(/OFFER/, 'A retail sale does not show the offer');
            await cancelDraft(t);
        },
    },
    {
        n: 182, role: 'admin',
        did: 'In a sale to a shop, searched for Napa 500 mg (Square) and read the normal price and the offer.',
        async run(t) {
            await shopSale(t);
            await searchNapa(t);
            await t.shot('The normal rate and the offer, side by side');
            await t.see(/Box\s*৳247\.50/, 'The normal box rate is shown');
            await t.see(/৳247\.50 a box · minimum 3 boxes/, 'And the offer, with its price and minimum');
            await t.see(/8 boxes available/, 'The offer shows how many boxes are left');
            await cancelDraft(t);
        },
    },
    {
        n: 205, role: 'admin',
        did: 'At the counter, searched for the medicine that is on offer.',
        async run(t) {
            await shopSale(t);
            await searchNapa(t);
            await t.shot('The offer card points out the offer');
            await t.see(/OFFER/, 'The search points out that this medicine is on offer');
            await t.see(/Order/, 'And has its own Order button');
            await cancelDraft(t);
        },
    },
    {
        n: 203, role: 'admin',
        did: 'Ordered 2 boxes through the offer, below its minimum of 3, and looked at the line in the draft.',
        async run(t) {
            await shopSale(t);
            await searchNapa(t);
            await orderOffer(t, 2);
            await t.shot('Two boxes: below the minimum');
            const body = await t.text();
            const items = body.slice(body.indexOf('Items'), body.indexOf('Subtotal'));
            t.check(!/OFFER/.test(items), 'Below the minimum the line is not an offer line, so it is sold at the normal rate', 'the line is still marked OFFER');
            t.check(/৳247\.50/.test(items), 'The rate on the line is the normal rate (247.50)');
            await cancelDraft(t);
        },
    },
    {
        n: 199, role: 'admin',
        did: 'Tried to order 9 boxes of an offer that has only 8 boxes available.',
        async run(t) {
            await shopSale(t);
            await searchNapa(t);
            await orderOffer(t, 9);
            await t.shot('9 boxes asked for, 8 available');
            const body = await t.text();
            const items = body.slice(body.indexOf('Items'), body.indexOf('Subtotal'));
            t.check(!/OFFER/.test(items) || /8/.test(items), 'More boxes than the offer holds are not given at the offer rate', 'nine boxes went through at the offer rate');
            await t.see(/only 8|8 boxes|not enough|available|left/i, 'The screen tells the owner how many boxes the offer has');
            await cancelDraft(t);
        },
    },
    {
        n: 184, role: 'admin',
        did: 'In a sale to a shop, added one strip of Napa at its normal rate first, and then 3 boxes through the offer.',
        async run(t) {
            await shopSale(t);
            await cancelDraft(t);
            await shopSale(t);
            await searchNapa(t);
            await t.page.getByRole('button', { name: /Strip.{0,4}82/ }).first().click();
            await t.wait(1500);
            await searchNapa(t);
            await orderOffer(t, 3);
            await t.shot('One draft with two kinds of rate');
            const body = await t.text();
            const items = body.slice(body.indexOf('Items'), body.indexOf('Subtotal'));
            t.check(/Box · OFFER/.test(items), 'The offer line is marked OFFER');
            t.check(/Strip[\s\S]*৳82\.50/.test(items), 'The ordinary line keeps its own rate (a strip at 82.50)');
            t.check(/৳742\.50/.test(items), 'The offer line is priced at 3 boxes at 247.50 (742.50)');
        },
    },
    {
        n: 185, role: 'admin',
        did: 'Looked at the order of the lines in the draft where the ordinary strip was added first and the offer after it.',
        async run(t) {
            await shopSale(t);
            const body = await t.text();
            const items = body.slice(body.indexOf('Items'), body.indexOf('Subtotal'));
            await t.shot('Order of the lines');
            const o = items.indexOf('OFFER');
            const r = items.indexOf('Strip');
            t.check(o >= 0 && r >= 0 && o < r, 'The offer lines come first, the ordinary lines after, and one total at the bottom', 'the strip, added first, stays above the offer line');
            await t.see(/Total/, 'One combined total');
        },
    },
    {
        n: 206, role: 'admin',
        did: 'Finished the shop sale that held an offer line and an ordinary line, paying on credit, and opened the memo.',
        async run(t) {
            await shopSale(t);
            await t.page.getByRole('button', { name: 'On credit (due)', exact: true }).click().catch(() => {});
            await t.click('Finish sale', { wait: 2500 });
            await t.shot('After pressing Finish sale');
            const body = await t.text();
            t.check(/Sale finished/.test(body) && /Box · OFFER/.test(body) && /Strip/.test(body), 'The memo is made with both kinds of line: the offer box line and the ordinary strip line', 'the sale could not be finished: ' + body.slice(0, 160).replace(/\s+/g, ' '));
            t.check(/Offer items return at the same rule on the offer price/.test(body), 'The memo tells the buyer how offer items are returned');
        },
    },
    {
        n: 183, role: 'admin',
        did: 'Opened the shop profile after selling it offer boxes at the counter.',
        async run(t) {
            await t.go('/admin/customers/4');
            await t.shot('The shop profile: Offers taken');
            await t.see(/Offers taken/, 'The shop profile has an offers section');
            await t.see(/Napa · 3 Box\s*৳247\.50/, 'It lists the offer boxes sold at the counter with the offer price');
            await t.see(/M2026-\d+/, 'With the memo number');
        },
    },
];
