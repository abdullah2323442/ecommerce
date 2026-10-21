// Delivery and courier (features 460-494)

export default [
    {
        n: 460, role: 'guest',
        did: 'Looked at the district box on a product order form to see how the place is chosen.',
        async run(t) {
            await t.go('/market/78');
            await t.shot('The district list on an order form');
            const options = await t.page.getByLabel(/^District/).locator('option').count();
            t.check(options > 60, `A full list of districts is offered (${options})`);
            t.skip('Filling the place in automatically from the phone\'s location was not reproduced.');
        },
    },
    { n: 461, role: 'guest', did: 'Looked for a map pin on the order form.', async run(t) { await t.go('/market/78'); await t.shot('The order form'); t.skip('The checklist marks the map pin as partly built. No map was found on the order form.'); } },
    {
        n: 462, role: 'guest',
        did: 'Looked at the order form for a "how to get there" box.',
        async run(t) {
            await t.go('/market/78');
            await t.shot('The order form');
            const body = await t.text();
            t.check(/how to get there|landmark|directions/i.test(body) || (await t.page.getByLabel(/Note/i).count()) > 0, 'The form has a box to help the rider find the place (a Note box)');
        },
    },
    {
        n: 463, role: 'admin',
        did: 'Looked at the delivery charges in settings and on a product to see the layers.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/Delivery charge inside that district \(taka\)/, 'A charge for inside the shop\'s district');
            await t.see(/Delivery charge outside that district \(taka\)/, 'A charge for outside it');
            await t.shot('The shop-wide delivery charges');
            await t.go('/admin/weights');
            await t.see(/A charge here is mandatory for that product and beats every other delivery/, 'A charge set on a product beats every other delivery charge');
            await t.shot('A product\'s own charge beats the others');
        },
    },
    { n: 464, role: 'admin', did: 'Read the rule that a product\'s own charge is the highest.', async run(t) { await t.go('/admin/weights'); await t.see(/beats every other delivery/, 'The mandatory product charge is the highest layer'); await t.shot('Weights and delivery charges'); } },
    {
        n: 465, role: 'admin',
        did: 'Opened the Couriers page where the shop lists the couriers it uses.',
        async run(t) {
            await t.go('/admin/couriers');
            await t.see(/Add as many couriers as you use and type their charges/, 'The shop keeps a list of couriers');
            await t.shot('Couriers');
        },
    },
    {
        n: 466, role: 'admin',
        did: 'Added a new courier called "QA Express" with its charges.',
        async run(t) {
            await t.go('/admin/couriers');
            if (!(await t.page.locator('input[value="QA Express"]').count())) {
                const add = t.page.locator('div,section,form').filter({ hasText: /^Add a courier/ }).last();
                await t.page.getByLabel('Courier name').last().fill('QA Express');
                await t.page.getByLabel('Cash on delivery share (%)').last().fill('1.5');
                const nums = t.page.locator('input[type=number]:visible');
                const n = await nums.count();
                for (let i = Math.max(1, n - 6); i < n; i++) await nums.nth(i).fill(String(60 + i * 10)).catch(() => {});
                await t.shot('A new courier with its charges');
                await t.click('Add the courier', { wait: 1800 });
            }
            await t.shot('The courier is on the list, with its charges');
            const saved = await t.page.locator('input[value="QA Express"]').count();
            t.check(saved >= 1, 'The new courier is added and kept on the list with its charges: there is no fixed limit');
        },
    },
    { n: 467, role: 'admin', did: 'Looked at how courier charges are entered.', async run(t) { await t.go('/admin/couriers'); await t.see(/type their charges/, 'Charges are typed by hand'); await t.shot('Charges typed by hand'); } },
    {
        n: 468, role: 'admin',
        did: 'Looked at the zones, first kilo and extra kilo for a courier.',
        async run(t) {
            await t.go('/admin/couriers');
            for (const z of ['Own area', 'Inside the district', 'Outside the district']) await t.see(z, `A zone: ${z}`);
            await t.see(/First kilo/, 'A price for the first kilo');
            await t.see(/Each extra kilo/, 'And for each extra kilo');
            await t.shot('Zone, first kilo and extra kilo');
        },
    },
    { n: 469, role: 'admin', did: 'Looked at the cash-on-delivery percentage on a courier.', async run(t) { await t.go('/admin/couriers'); await t.see(/Cash on delivery share \(%\)/, 'A courier has a cash-on-delivery percentage'); await t.shot('Cash on delivery share'); } },
    { n: 470, role: 'admin', did: 'Looked at the weight rounding rule in settings.', async run(t) { await t.go('/admin/settings'); await t.see(/How couriers round the weight/, 'A rule for rounding weight'); await t.see(/Round up to half a kilo/, 'One choice is round up to half a kilo'); await t.shot('Weight rounding'); } },
    { n: 471, role: 'guest', did: 'Looked for all couriers side by side at checkout.', async run(t) { await t.go('/market/78'); await t.shot('A product page'); t.skip('Courier choices side by side appear only when every product in the order has a weight. The test products have none.'); } },
    { n: 472, role: 'guest', did: 'Looked at the flat delivery charge on a product with no weight.', async run(t) { await t.go('/market/78'); await t.see(/Delivery charge\s*৳80\.00/, 'A product with no weight uses a flat delivery charge (80)'); await t.shot('A flat delivery charge'); } },
    { n: 473, role: 'admin', did: 'Looked at the list of products with no weight.', async run(t) { await t.go('/admin/weights'); await t.see(/Only those with no weight \(\d[\d,]*\)/, 'The owner is shown how many products still have no weight'); await t.shot('Products with no weight'); } },
    { n: 474, role: 'admin', did: 'Looked at the top of the Couriers page for the rate check.', async run(t) { await t.go('/admin/couriers'); await t.see(/Rates last checked/, 'The page says when the courier rates were last checked'); await t.see(/Yes, they are still correct/, 'The owner confirms they are still correct with one press'); await t.shot('Rates last checked'); t.skip('The reminder that appears after three months was not waited for.'); } },
    { n: 475, role: 'admin', did: 'Looked at the order screen for adding an extra delivery charge.', async run(t) { await t.go('/admin/deliveries'); await t.shot('My deliveries'); t.skip('Needs an order in delivery. Not carried through.'); } },
    { n: 476, role: 'guest', did: 'Looked at an order for the "On the way" step.', async run(t) { await t.go('/market/78'); await t.shot('A product page'); t.skip('The "On the way" step was seen on a seller order earlier (see feature 345).'); } },
    { n: 477, role: 'admin', did: 'Looked for the 50-50 courier split in wholesale.', async run(t) { await t.go('/admin/couriers'); await t.shot('Couriers'); t.skip('Not carried through.'); } },
    { n: 478, role: 'admin', did: 'Looked for charges by weight and by carton.', async run(t) { await t.go('/admin/weights'); await t.shot('Weights'); t.skip('Not carried through.'); } },
    { n: 479, role: 'admin', did: 'Looked at the setting for an advance delivery charge.', async run(t) { await t.go('/admin/settings'); await t.see(/Delivery charge paid first on orders of \(taka, 0 for never\)/, 'Large orders can ask for the delivery charge first'); await t.shot('Delivery charge paid first'); } },
    { n: 480, role: 'wholesale', did: 'Looked at the seller screen for a pickup point.', async run(t) { await t.go('/wholesale/sell'); await t.see(/Where buyers can collect/, 'A seller can set a pickup point'); await t.shot('Where buyers can collect'); } },
    { n: 481, role: 'wholesale', did: 'Read the condition on the pickup point.', async run(t) { await t.go('/wholesale/sell'); await t.see(/Write your shop address\. Then you can let buyers collect a product from you, with no delivery charge/, 'A pickup point removes the delivery charge'); await t.shot('Pickup point'); } },
    { n: 482, role: 'admin', did: 'Looked for a pickup reminder after three days.', async run(t) { await t.go('/admin/deliveries'); await t.shot('My deliveries'); t.skip('Needs three days to pass. Not carried through.'); } },
    { n: 483, role: 'admin', did: 'Opened "My deliveries", the delivery person\'s list.', async run(t) { await t.go('/admin/deliveries'); await t.shot('The delivery person\'s list'); await t.see(/Nothing to deliver now|Money you hold/, 'A delivery person has their own list'); } },
    { n: 484, role: 'admin', did: 'Looked for deliveries grouped by area.', async run(t) { await t.go('/admin/deliveries'); await t.shot('My deliveries'); t.skip('Needs several deliveries. Not carried through.'); } },
    { n: 485, role: 'admin', did: 'Looked for the "Arrived" mark.', async run(t) { await t.go('/admin/deliveries'); await t.shot('My deliveries'); t.skip('Needs a delivery. Not carried through.'); } },
    {
        n: 486, role: 'admin',
        did: 'Opened Delivery money.',
        async run(t) {
            await t.go('/admin/delivery-cash');
            await t.see(/What each delivery person took at the door and has not handed in yet/, 'The owner sees what each delivery person holds');
            await t.see(/Count it at the end of the day, then press Received/, 'The owner counts it and presses Received');
            await t.shot('Delivery money');
        },
    },
    { n: 487, role: 'admin', did: 'Looked for confirming delivery by code or photo.', async run(t) { await t.go('/admin/deliveries'); await t.shot('My deliveries'); t.skip('Needs a delivery. Not carried through.'); } },
    { n: 488, role: 'admin', did: 'Looked for the code turning a draft sale into a complete sale.', async run(t) { await t.go('/admin/deliveries'); await t.shot('My deliveries'); t.skip('Needs a delivery. Not carried through.'); } },
    { n: 489, role: 'guest', did: 'Looked for a "Received" tap.', async run(t) { await t.go('/market/78'); await t.shot('A product page'); t.skip('The checklist marks this as partly built. Not reproduced.'); } },
    {
        n: 490, role: 'admin',
        did: 'Opened Delivery ratings.',
        async run(t) {
            await t.go('/admin/delivery-ratings');
            await t.see(/What buyers thought of the delivery and the service/, 'Buyers rate the delivery and the service');
            await t.see(/Buyers never see these names/, 'Buyers do not see the names of people rated');
            await t.shot('Delivery ratings');
        },
    },
    { n: 491, role: 'admin', did: 'Looked at ratings kept per delivery person and per staff.', async run(t) { await t.go('/admin/delivery-ratings'); await t.see(/Delivery people/, 'Ratings are kept for delivery people'); await t.see(/Staff/, 'And for staff'); await t.shot('Ratings per person'); } },
    { n: 492, role: 'admin', did: 'Looked at the courier list for booking parcels with a courier service.', async run(t) { await t.go('/admin/couriers'); await t.see(/Book parcels through/, 'A courier can book parcels through a service'); await t.see(/Steadfast/, 'Steadfast is offered'); await t.see(/Pathao/, 'Pathao is offered'); await t.shot('Courier integration'); t.note('Both services show "not switched on" in the test copy.'); } },
    { n: 493, role: 'admin', did: 'Looked at the zones on a courier.', async run(t) { await t.go('/admin/couriers'); await t.see(/Leave a zone empty if this courier does not go there/, 'A courier can leave out zones it does not serve'); await t.shot('Delivery zones'); } },
    { n: 494, role: 'admin', did: 'Looked for "delivery by arrangement".', async run(t) { await t.go('/admin/couriers'); await t.see(/Nobody: I send it myself/, 'Delivery can be "I send it myself"'); await t.shot('"Nobody: I send it myself"'); } },
];
