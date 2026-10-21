// Visibility (features 36-44)

const card = (t) => t.block('Matte silicone phone case', "Show this seller's own name");

async function setSellerName(t, on) {
    await t.as('admin');
    await t.go('/admin/market');
    const c = await card(t);
    const box = c.getByRole('checkbox');
    const state = async () => (await box.getAttribute('aria-checked')) === 'true' || (await box.isChecked().catch(() => false));
    if ((await state()) !== on) await box.click();
    await c.getByRole('button', { name: 'Save' }).click();
    await t.wait(1200);
}

async function soldBy(t, shot) {
    await t.as('guest');
    await t.go('/market/78');
    const body = await t.text();
    if (shot) await t.shot(shot);
    const m = body.match(/Sold by\s*([^\n]+)/i);
    return m ? m[1].trim() : null;
}

async function turnedAway(t, url, label) {
    await t.go(url);
    const body = await t.text();
    const away = !new URL(t.page.url()).pathname.startsWith(url);
    t.check(away, `${label} cannot open ${url} (they were sent to ${new URL(t.page.url()).pathname})`);
    t.check(!/403|forbidden|not authorized|unauthorized/i.test(body), `${label} gets no scary error page, just a quiet redirect`);
}

export default [
    {
        n: 36, role: 'paikari',
        did: 'Signed in as a paikari shop and tried to open the wholesale screen. Then signed in as a wholesaler and tried to open the paikari screen.',
        async run(t) {
            await t.go('/paikari');
            await t.shot('A paikari shop on its own ordering screen');
            await turnedAway(t, '/wholesale', 'A paikari shop');
            await t.shot('After typing the wholesale address, the paikari shop is sent back');
            await t.as('wholesale');
            await t.go('/wholesale');
            await t.shot('A wholesaler on the wholesale screen');
            await turnedAway(t, '/paikari', 'A wholesaler');
            await t.shot('After typing the paikari address, the wholesaler is sent back');
        },
    },
    {
        n: 37, role: 'admin',
        did: 'As the owner, took the "Medicine" category away from the wholesaler Rahim Traders. Then signed in as Rahim to see if the medicine ordering list was still there. Finally gave the category back.',
        async run(t) {
            await t.go('/admin/customers/5');
            await t.shot('The owner\'s "Categories they can see" for Rahim Traders');
            const box = t.page.getByRole('checkbox', { name: 'Medicine', exact: true }).first();
            await box.setChecked(false);
            await t.page.getByRole('button', { name: 'Save', exact: true }).first().click();
            await t.wait(1200);
            await t.as('wholesale');
            await t.go('/wholesale');
            const body = await t.text();
            await t.shot('Rahim\'s screen without the Medicine category');
            t.check(!/Abbott Laboratories/.test(body), 'With the Medicine category removed, the list of medicine companies is gone');
            await t.as('admin');
            await t.go('/admin/customers/5');
            await t.page.getByRole('checkbox', { name: 'Medicine', exact: true }).first().setChecked(true);
            await t.page.getByRole('button', { name: 'Save', exact: true }).first().click();
            await t.wait(1200);
            await t.as('wholesale');
            await t.go('/wholesale');
            await t.see('Abbott Laboratories', 'Giving the category back brings the medicine list back');
            await t.shot('The category is given back and the list returns');
        },
    },
    {
        n: 38, role: 'guest',
        did: 'As a stranger, opened a direct link to a public product, then a direct link to a restricted page.',
        async run(t) {
            await t.go('/market/78');
            await t.see('Matte silicone phone case', 'A direct link to a public product opens straight to it');
            await t.shot('A direct product link works for everyone allowed to see it');
            await turnedAway(t, '/wholesale', 'A stranger');
            await t.shot('A direct link to a restricted page quietly goes elsewhere');
        },
    },
    {
        n: 39, role: 'visitor',
        did: 'As an ordinary shopper, typed the addresses of three restricted pages.',
        async run(t) {
            for (const u of ['/wholesale', '/paikari', '/admin/stock']) await turnedAway(t, u, 'A shopper');
            await t.shot('Where the shopper ends up: back on an ordinary page, with no error message');
        },
    },
    {
        n: 40, role: 'guest',
        did: 'As a stranger, looked at who the seller of a product is, with the seller\'s own name switched off and then on.',
        async run(t) {
            await setSellerName(t, false);
            const off = await soldBy(t, 'The product page with the seller\'s own name switched off');
            await setSellerName(t, true);
            const on = await soldBy(t, 'The product page with the seller\'s own name switched on');
            t.note(`With the switch off the page said "Sold by ${off}". With it on it said "Sold by ${on}".`);
            t.check(off !== on, `Switching changes who the shopper sees as the seller (off: "${off}", on: "${on}")`);
            t.check(off && !/Rahim/i.test(off), `With the switch off, the real seller's name is not shown (it showed "${off}")`);
        },
    },
    {
        n: 41, role: 'admin',
        did: 'Opened the owner\'s Market page and found the switch beside each product: "Show this seller\'s own name instead of ours".',
        async run(t) {
            await t.go('/admin/market');
            await t.see(/Show this seller's own name instead of ours/, 'Each product has a switch for showing the seller\'s own name');
            await (await card(t)).scrollIntoViewIfNeeded();
            await t.shot('The switch on each product in the owner\'s Market page');
        },
    },
    {
        n: 42, role: 'staff',
        did: 'Signed in as counter staff and tried to open the reports and the stock-value pages, and looked at the counter for profit figures.',
        async run(t) {
            await t.go('/admin/counter');
            await t.notSee(/profit/i, 'The counter shows no profit figures');
            await t.shot('The counter, as staff see it');
            for (const u of ['/admin/reports', '/admin/stock/reports']) {
                await t.go(u);
                t.check(!new URL(t.page.url()).pathname.startsWith(u), `Staff cannot open ${u}`);
            }
            await t.shot('Reports are closed to staff');
            await t.go('/admin/stock');
            await t.notSee(/৳7,200|Purchase prices are shown here only/, 'Staff cannot see what stock cost the shop');
            await t.shot('The stock page, as staff see it');
        },
    },
    {
        n: 43, role: 'staff',
        did: 'To be shown together with the counter features, where a sale is made.',
        async run(t) {
            await t.go('/admin/counter');
            await t.shot('The counter, where loss warnings appear');
            t.skip('A loss warning appears when a sale would lose money. That needs a sale in progress, which is covered with the counter features.');
        },
    },
    {
        n: 44, role: 'craftsman',
        did: 'Signed in as a craftsman and looked at everything on their screen for prices or profit.',
        async run(t) {
            await t.go('/craftsman');
            await t.notSee(/profit|margin|commission/i, 'The craftsman\'s screen shows no profit, margin or commission');
            await t.shot('The craftsman\'s screen');
            t.skip('There was no job assigned in the test data, so the job list itself was empty. Prices on a real job were not shown.');
        },
    },
];
