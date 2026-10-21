// Partly-shown pass: the three ways an order is cancelled, and the craftsman screen (features 160, 161, 162, 44).
import { searchNapa } from './_paikari.mjs';

/** The shop sends an order for one box. Returns the shop order page and the owner order page. */
async function newOrder(t) {
    await searchNapa(t);
    await t.click(/Box.{0,4}\d/, { wait: 1500 });
    await t.click(/Send order/, { wait: 2500 });
    const shop = t.page.url();
    await t.as('admin');
    await t.go('/admin/orders');
    const ids = (await t.page.locator('main a[href*="/admin/orders/"]').evaluateAll((a) => a.map((x) => Number(x.getAttribute('href').split('/').pop())))).filter(Boolean);
    return { shop, owner: `/admin/orders/${Math.max(...ids)}` };
}

async function check(t, owner) {
    await t.as('admin');
    await t.go(owner);
    await t.page.getByRole('button', { name: 'Full', exact: true }).click();
    await t.wait(1200);
    await t.page.getByRole('button', { name: 'Checking done' }).click();
    await t.wait(2000);
}

async function stock(t) {
    await t.as('admin');
    await t.go('/admin/stock');
    const m = (await t.text()).match(/Napa 500 mg[\s\S]{0,60}?(\d+)\s*box/i);
    return m ? Number(m[1]) : null;
}

export default [
    {
        n: 160, role: 'paikari',
        did: 'Sent two orders and cancelled each at a different moment: before the owner checked it, and after it was checked.',
        async run(t) {
            // 1. before it is checked
            let o = await newOrder(t);
            await t.as('paikari');
            await t.go(o.shop);
            await t.click('Cancel order', { wait: 2000 });
            await t.shot('1. Cancelled before it was checked: it simply goes');
            await t.see(/cancelled|Cancelled/i, 'Before it is checked the shop can cancel at once');
            // 2. after it is checked
            o = await newOrder(t);
            await check(t, o.owner);
            await t.as('paikari');
            await t.go(o.shop);
            await t.click('Cancel order', { wait: 2000 });
            await t.shot('2. Cancelled after it was checked: staff must agree');
            await t.see(/Cancellation requested\. Waiting for our staff\./, 'After checking, the shop can only ask: "Cancellation requested. Waiting for our staff."');
            await t.as('admin');
            await t.go(o.owner);
            await t.shot('The owner sees the request and decides');
            await t.see(/This shop wants to cancel\./, 'The owner is told the shop wants to cancel');
            await t.see(/Keep it/, 'And can keep the order or cancel it');
            await t.click('Cancel the order', { wait: 2000 });
            await t.shot('The owner agrees');
        },
    },
    {
        n: 161, role: 'admin',
        did: 'Checked an order the shop was going to collect. Read the Napa stock, pressed "Not collected", and read the stock again.',
        async run(t) {
            const o = await newOrder(t);
            await check(t, o.owner);
            const before = await stock(t);
            await t.go(o.owner);
            await t.shot(`A checked order waiting for the shop to collect it (stock ${before})`);
            await t.click('Not collected', { wait: 2500 });
            await t.shot('After pressing "Not collected"');
            const after = await stock(t);
            await t.shot(`The stock afterwards: ${after}`);
            t.check(before !== null && after !== null && after >= before, `The goods nobody collected are back in stock (${before} became ${after})`, `the stock went from ${before} to ${after}`);
        },
    },
    {
        n: 162, role: 'admin',
        did: 'The shop cancelled orders after they were checked, again and again (each time the owner agreed). The owner then opened the shop customer page.',
        async run(t) {
            for (let i = 0; i < 3; i++) {
                const o = await newOrder(t);
                await check(t, o.owner);
                await t.as('paikari');
                await t.go(o.shop);
                await t.click('Cancel order', { wait: 2000 });
                await t.as('admin');
                await t.go(o.owner);
                await t.click('Cancel the order', { wait: 2000 });
            }
            await t.go('/admin/customers/4');
            await t.shot('The shop customer page after repeated late cancellations');
            await t.see(/Flagged: \d+ orders cancelled after they were checked, in the last 90 days\./, 'The shop is flagged automatically after too many late cancellations, and the owner is told how many');
        },
    },
    {
        n: 44, role: 'craftsman',
        did: 'Signed in as the craftsman and looked at the list of jobs and at one job for any price or profit.',
        async run(t) {
            await t.go('/craftsman');
            await t.shot('The craftsman jobs');
            await t.see(/C2026-00001/, 'The craftsman sees the jobs given to them');
            await t.see(/Earned/, 'And what they earned');
            const list = await t.text();
            t.check(!/19,500|22,000|Profit/.test(list), 'No buyer price and no profit appear on the list', 'a price or profit showed on the list');
            await t.go('/craftsman/jobs/2');
            await t.shot('One job: measurements, photos, progress and their own wage');
            const job = await t.text();
            t.check(!/19,500|Profit/.test(job), 'No buyer price and no profit appear on the job either (the owner offer card is hidden from the craftsman)', 'a price or profit showed on the job');
            await t.see(/The wage for the whole job: ৳7,000\.00/, 'Only their own wage is shown');
        },
    },
];
