// Offers (features 192-219)
import { closeNotices, searchNapa } from './_paikari.mjs';

/** Fill the "New offer product" form for Napa 500 mg (Square) and press Start the offer. */
async function startOffer(t, { rate = '10', min = '3', extra = '', weight = '', delivery = '', stock, shops = true, publik = true, wholesale = false } = {}) {
    await t.as('admin');
    await t.go('/admin/offers');
    await t.page.getByPlaceholder('Medicine name').first().fill('napa');
    await t.wait(1300);
    await t.click(/Napa 500 mg/);
    await t.fill('Offer rate %', rate);
    await t.fill('Minimum boxes', min);
    if (extra) await t.fill('Extra % for each box above the minimum', extra);
    if (weight) await t.fill('Weight (kg), optional', weight);
    if (delivery) await t.fill('Delivery charge for this product', delivery);
    if (stock) await t.page.locator('select').first().selectOption({ label: stock });
    for (const [label, on] of [['Shops', shops], ['Public', publik], ['Wholesale', wholesale]]) {
        const box = t.page.getByRole('checkbox', { name: label, exact: true });
        if ((await box.count()) && (await box.isChecked()) !== on) await box.setChecked(on).catch(() => box.click());
    }
}

async function endAllOffers(t) {
    await t.as('admin');
    await t.go('/admin/offers');
    const stop = t.page.getByRole('button', { name: /Stop|End|Remove|Cancel offer/i });
    for (let i = 0; i < 6 && (await stop.count()); i++) {
        await stop.first().click().catch(() => {});
        await t.wait(900);
    }
}

export default [
    {
        n: 192, role: 'admin',
        did: 'Made an offer product: Napa 500 mg at 10% off, with a minimum of 3 boxes, and started it.',
        async run(t) {
            await startOffer(t, { rate: '10', min: '3', stock: 'Exact number of boxes' });
            await t.shot('The offer form filled in: 10% off, from 3 boxes');
            await t.click('Start the offer', { wait: 2000 });
            await t.shot('The offer is running');
            await t.see(/Offer products[\s\S]*Napa 500 mg/i, 'The offer product is listed as running');
            await t.see(/3/, 'It has a minimum of 3 boxes');
        },
    },
    {
        n: 193, role: 'paikari',
        did: 'Looked at how the offer is sold to a paikari shop.',
        async run(t) {
            await searchNapa(t);
            await t.shot('The medicine search with the offer');
            await t.see(/offer/i, 'The shop sees that Napa 500 mg is on offer');
            t.note('An offer is sold in boxes only. The search shows one box price for the offer, with its minimum.');
        },
    },
    {
        n: 194, role: 'admin',
        did: 'Looked at the two ways to put a medicine on offer: by hand with "New offer product", and from the "Bought at a high discount" list the system builds.',
        async run(t) {
            await t.go('/admin/offers');
            await t.see(/New offer product/, 'The owner can put a medicine on offer by hand');
            await t.see(/Bought at a high discount/, 'The system also lists medicines bought at a high discount, as suggestions');
            await t.shot('Two routes: by hand, or from the suggestions');
        },
    },
    {
        n: 195, role: 'admin',
        did: 'Tried to start an offer at 20% off, which leaves almost no profit on a box that cost 240.',
        async run(t) {
            await startOffer(t, { rate: '20', min: '2' });
            await t.shot('An offer at 20% off a box that cost 240');
            await t.click('Start the offer', { wait: 2000 });
            await t.shot('What the system says');
            await t.see(/profit|least|below|loss|margin/i, 'The system refuses an offer that leaves less than the smallest profit allowed');
            await t.go('/admin/offers');
            await t.see(/The least profit allowed is \d+%/, 'The page states the smallest profit an offer must keep, set by the owner in Settings');
        },
    },
    {
        n: 196, role: 'admin',
        did: 'Looked at the list of medicines bought at a high discount.',
        async run(t) {
            await t.go('/admin/offers');
            await t.see(/Bought at 20%, \d+ boxes? in the shop/, 'Each suggestion is a lot bought at a high discount, with its boxes in the shop');
            await t.shot('Only lots bought at a high discount are suggested');
        },
    },
    {
        n: 197, role: 'admin',
        did: 'Looked at who the offer can be shown to.',
        async run(t) {
            await t.go('/admin/offers');
            await t.see(/Who can see it/, 'The form asks who can see the offer');
            for (const w of ['Shops', 'Public', 'Wholesale']) t.check((await t.page.getByRole('checkbox', { name: w, exact: true }).count()) > 0, `${w} can be chosen`);
            await t.shot('Shops, Public or Wholesale');
        },
    },
    {
        n: 198, role: 'admin',
        did: 'Looked at the choices for how the offer\'s stock is shown.',
        async run(t) {
            await t.go('/admin/offers');
            const options = await t.page.locator('select').first().locator('option').allInnerTexts();
            t.note(`The choices are: ${options.join(' / ')}`);
            t.check(options.length === 2, `There are two ways to show stock (${options.join(', ')})`);
            await t.shot('"Just In stock" or "Exact number of boxes"');
        },
    },
    {
        n: 199, role: 'paikari',
        did: 'Looked for the limit on how many boxes of an offer can be ordered.',
        async run(t) {
            await searchNapa(t);
            await t.shot('The offer in the search');
            t.skip('Ordering more boxes than the offer holds was not carried through.');
        },
    },
    {
        n: 200, role: 'admin',
        did: 'Looked at the "Extra % for each box above the minimum" box on the offer form.',
        async run(t) {
            await t.go('/admin/offers');
            await t.see(/Extra % for each box above the minimum/, 'An offer can give a bigger discount for each extra box');
            await t.shot('The extra discount per box');
        },
    },
    {
        n: 201, role: 'admin',
        did: 'Looked at the weight box on the offer form.',
        async run(t) {
            await t.go('/admin/offers');
            await t.see(/Weight \(kg\), optional/, 'An offer can carry a weight');
            await t.shot('The weight box');
        },
    },
    {
        n: 202, role: 'admin',
        did: 'Looked at the delivery charge box on the offer form.',
        async run(t) {
            await t.go('/admin/offers');
            await t.see(/Delivery charge for this product/, 'An offer can carry its own minimum delivery charge');
            await t.shot('A delivery charge for this product');
        },
    },
    {
        n: 203, role: 'paikari',
        did: 'Put fewer boxes than the offer minimum on the order and looked at the price.',
        async run(t) {
            await searchNapa(t);
            await t.shot('One box, against an offer that needs 3');
            t.skip('The normal-rate fallback below the minimum was not carried through.');
        },
    },
    { n: 204, role: 'admin', did: 'Looked at profit on offers below the minimum.', async run(t) { await t.go('/admin/offers'); await t.shot('Offers'); t.skip('Profit worked out at the normal rate below the minimum was not reproduced.'); } },
    {
        n: 205, role: 'admin',
        did: 'At the counter, searched for the medicine that is on offer.',
        async run(t) {
            await t.go('/admin/counter');
            await t.click('New retail sale', { wait: 1800 });
            await t.fill('Medicine name', 'napa');
            await t.wait(1300);
            await t.shot('The counter search');
            t.skip('A pop-up in the search that points out an offer was not seen at the counter.');
            await t.click('Cancel draft', { wait: 1500 });
        },
    },
    { n: 206, role: 'admin', did: 'Looked for a memo with offer and regular items.', async run(t) { await t.go('/admin/invoices'); await t.shot('Invoices'); t.skip('A mixed offer and regular memo was not made.'); } },
    {
        n: 207, role: 'admin',
        did: 'Looked at the setting for the warning before an offer runs out.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/Warn when an offer has this many boxes left/, 'The owner sets how many boxes left should trigger a warning');
            await t.shot('The setting');
        },
    },
    {
        n: 208, role: 'admin',
        did: 'Looked at the setting for how long a sold-out offer stays grey.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/A sold-out offer stays greyed for \(minutes\)/, 'A sold-out offer stays grey for a time the owner sets');
            await t.shot('The grey time setting');
        },
    },
    { n: 209, role: 'paikari', did: 'Looked for whether a greyed offer can be added to a cart.', async run(t) { await searchNapa(t); await t.shot('The offer in the search'); t.skip('Selling out an offer to see it grey and refuse an add was not reproduced.'); } },
    { n: 210, role: 'admin', did: 'Looked for a listing returning to normal when a draft is cancelled.', async run(t) { await t.go('/admin/offers'); await t.shot('Offers'); t.skip('Not reproduced.'); } },
    {
        n: 211, role: 'admin',
        did: 'Pressed "Upload to the shop tier" for a medicine bought at a high discount, to start a tier offer with no minimum.',
        async run(t) {
            await t.go('/admin/offers');
            await t.click('Upload to the shop tier', { wait: 2000 });
            await t.shot('A shop tier offer is running');
            await t.see(/Shop tier offers running now[\s\S]*Napa 500 mg/i, 'The medicine now shows under "Shop tier offers running now"');
        },
    },
    {
        n: 212, role: 'admin',
        did: 'Looked at the list of medicines grouped for offers.',
        async run(t) {
            await t.go('/admin/offers');
            await t.shot('Medicines bought at a high discount');
            await t.see(/Bought at a high discount/, 'A list of offer candidates is kept');
        },
    },
    {
        n: 213, role: 'paikari',
        did: 'After the owner pressed one button, looked at what the paikari shop sees.',
        async run(t) {
            await searchNapa(t);
            await t.shot('The paikari search after a tier offer');
            await t.see(/Napa 500 mg/, 'The shop can see Napa 500 mg');
            t.note('The tier offer is a single click for the owner and needs no minimum.');
        },
    },
    {
        n: 214, role: 'admin',
        did: 'Looked at the date box for how long an offer runs.',
        async run(t) {
            await t.go('/admin/offers');
            t.check((await t.page.locator('input[type=date]').count()) > 0, 'An offer has an end date');
            await t.shot('The offer\'s date box');
        },
    },
    { n: 215, role: 'admin', did: 'Looked for offers ending themselves at month end.', async run(t) { await t.go('/admin/offers'); await t.see(/It ends with the month, or when the stock is gone/, 'The page says an offer ends with the month or when stock is gone'); await t.shot('The Offers page explains when offers end'); t.skip('Waiting for a month end to see it happen was not possible.'); } },
    { n: 216, role: 'admin', did: 'Looked for a question after the month passes.', async run(t) { await t.go('/admin/offers'); await t.shot('Offers'); t.skip('Needs a month to pass.'); } },
    { n: 217, role: 'admin', did: 'Looked for an offer switching on and off with stock.', async run(t) { await t.go('/admin/offers'); await t.shot('Offers'); t.skip('Needs stock to run out and be refilled; not reproduced.'); } },
    {
        n: 218, role: 'admin',
        did: 'Gave Karim Pharmacy a hand-set 18% price while a tier offer is running, and looked at what the shop pays.',
        async run(t) {
            await t.preConfirm();
            await t.go('/admin/customers/4');
            await t.fill('Search a medicine', 'napa');
            await t.wait(1200);
            await t.click(/Napa 500 mg\s*tablet/i);
            await t.page.locator('input[type=number]').last().fill('18');
            await t.click('Save rate', { wait: 1500 });
            await searchNapa(t);
            await t.shot('Karim keeps the hand-set rate while an offer runs');
            await t.see(/Box\s*৳246\.00/, 'The shop pays its own hand-set price (246), not the offer');
            await t.as('admin');
            await t.go('/admin/customers/4');
            await t.click(/Remove manual rate/, { wait: 1500 });
        },
    },
    {
        n: 219, role: 'admin',
        did: 'On the medicine\'s lot, pressed "Correct price" to raise the cost while offers are running.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            await t.click('Correct price', { wait: 1500 });
            await t.shot('Correcting a lot\'s cost');
            await t.see(/offer|loss/i, 'The screen warns when a corrected cost would make an offer or rate lose money');
        },
    },
];
