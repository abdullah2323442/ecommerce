// Eighth pass: a food order followed through (replaces "partly shown" results 420, 421, 433, 434, 437, 438, 439)
// Set-up through the screens: a shopper put one "Hot Wings, 6 pieces" from Crispy House into the order, filled in name, phone and address
// and pressed "Place the order". Order F261021-000001 was waiting for the restaurant.

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

export default [
    {
        n: 420, role: 'vendor',
        did: 'As the restaurant, opened the waiting order, wrote that it will take 20 minutes and pressed Confirm.',
        async run(t) {
            await t.go('/food/vendor');
            await t.see(/F261021-000001/, 'The new order is waiting on the restaurant\'s screen');
            await t.see(/It will take \(minutes\)/, 'The restaurant is asked how long it will take');
            await t.page.getByLabel('It will take (minutes)').first().fill('20');
            await t.shot('A cooking time of 20 minutes typed in');
            await t.click('Confirm', { wait: 2200 });
            await t.shot('After pressing Confirm');
            await t.see(/Confirmed|cooking/i, 'The order is now confirmed and being cooked');
        },
    },
    {
        n: 421, role: 'visitor',
        did: 'As the shopper, opened the food order after the restaurant confirmed it.',
        async run(t) {
            await t.go('/food-orders');
            const hrefs = await t.page.locator('main a').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
            const order = hrefs.find((h) => /\/food\/orders\/[A-Za-z0-9]{20,}/.test(h));
            await t.go(order);
            await t.shot('The shopper\'s order after confirmation');
            await t.see(/Cooking\. It will be ready in about\s*\d+:\d+/, 'The shopper sees a countdown timer: "Cooking. It will be ready in about 19:51"');
            await t.see(/Confirmed: cooking/, 'And the order moves to the "Confirmed: cooking" step');
        },
    },
    {
        n: 433, role: 'vendor',
        did: 'As the restaurant, opened the kitchen ticket of the order.',
        async run(t) {
            await t.go('/food/vendor/orders/1/print/kitchen');
            await t.shot('The kitchen ticket');
            await t.see(/F261021-000001/, 'The ticket carries the order number');
            await t.see(/Hot Wings/, 'And what to cook');
        },
    },
    {
        n: 438, role: 'vendor',
        did: 'As the restaurant, opened the memo of the order.',
        async run(t) {
            await t.go('/food/vendor/orders/1/print/memo');
            await t.shot('The memo');
            await t.see(/Crispy House/, 'The memo is in the restaurant\'s own name');
            await t.see(/৳320\.00/, 'With the total the buyer pays');
        },
    },
    {
        n: 439, role: 'vendor',
        did: 'On the restaurant\'s page, looked at who delivers.',
        async run(t) {
            await t.go('/food/vendor');
            await t.page.getByText('Who delivers').scrollIntoViewIfNeeded();
            await t.shot('Who delivers: own people or the platform\'s courier');
            await t.see(/My own people/, 'The restaurant can deliver with its own people');
            await t.see(/The platform's courier/, 'Or ask the platform\'s courier');
        },
    },
    {
        n: 437, role: 'vendor',
        did: 'On the restaurant\'s page, looked at the advance payment setting.',
        async run(t) {
            await t.go('/food/vendor');
            await t.page.getByText('Advance for orders over').scrollIntoViewIfNeeded();
            await t.shot('Advance for big orders');
            await t.see(/Advance for orders over/, 'The restaurant can ask for an advance on orders above an amount');
            await t.see(/Advance %/, 'And choose what percentage');
            t.skip('A big order with an advance was not placed, so the shopper side of paying the advance was not seen.');
        },
    },
    {
        n: 442, role: 'vendor',
        did: 'The restaurant moved the order through Packing, Out for delivery and Delivered. Then the shopper opened the order, wrote what was wrong and sent it with a photo.',
        async run(t) {
            await t.go('/food/vendor');
            for (const step of ['Packing', 'Out for delivery', 'Delivered']) {
                const b = t.page.getByRole('button', { name: step, exact: true });
                if (await b.count()) {
                    await b.first().click();
                    await t.wait(1800);
                }
            }
            await t.shot('The restaurant has delivered the order: today\'s sales show');
            await t.see(/Today[\s\S]{0,30}৳290\.00/, 'The restaurant\'s sales for today count the delivered order');
            await t.as('visitor');
            await t.go('/food-orders');
            const hrefs = await t.page.locator('main a').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
            await t.go(hrefs.find((h) => /\/food\/orders\/[A-Za-z0-9]{20,}/.test(h)));
            await t.see(/Something wrong with the food\?/, 'After delivery the shopper is asked if something was wrong with the food');
            await t.page.getByLabel('What is wrong?').fill('The wings were cold when they arrived');
            await t.page.locator('input[type=file]').first().setInputFiles({ name: 'cold-wings.png', mimeType: 'image/png', buffer: PNG });
            await t.shot('A complaint with a photo, ready to send');
            await t.click('Send with the photo', { wait: 2200 });
            await t.shot('After sending the complaint');
            await t.see(/Your complaint: The wings were cold when they arrived/, 'The complaint is shown on the order');
            await t.see(/The restaurant and we have it\. We will look at it/, 'The shopper is told both the restaurant and the platform have it');
        },
    },
    {
        n: 434, role: 'vendor',
        did: 'On the restaurant\'s page, looked at how the delivery charge is set.',
        async run(t) {
            await t.go('/food/vendor');
            await t.page.getByText('Delivery charge').first().scrollIntoViewIfNeeded();
            await t.shot('Delivery charge choices');
            await t.see(/By area: one price inside, another outside/, 'The charge can depend on the area: one price inside, another outside');
            await t.see(/I write it on each order/, 'Or the restaurant writes it on each order');
            t.note('The checklist marks the third choice as partly built.');
        },
    },
];
