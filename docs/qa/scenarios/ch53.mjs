// Partly-shown pass, money when goods are not taken (features 274, 275).
// Spec ch 12: a delivery charge paid first is half refunded when the order is cancelled before it is sent.
// The owner sets "free delivery" off and "delivery charge paid first" to a small amount, so one box of Napa needs the charge first.

async function setSetting(t, label, value) {
    await t.as('admin');
    await t.go('/admin/settings');
    const box = t.page.getByLabel(label, { exact: false }).first();
    const old = await box.inputValue();
    await box.fill(String(value));
    await box.locator('xpath=ancestor::*[.//button[normalize-space()="Save"]][1]').getByRole('button', { name: 'Save', exact: true }).first().click();
    await t.wait(1500);
    return old;
}

export default [
    {
        n: 274, role: 'visitor',
        did: 'With "delivery charge paid first" switched on for this order size, a shopper ordered a box of Napa to be delivered. The owner saw the charge had to come first, marked it received, then cancelled the order before sending. The shopper\'s order page was read.',
        async run(t) {
            const free = await setSetting(t, 'Free delivery over (taka, 0 for never)', 0);
            const first = await setSetting(t, 'Delivery charge paid first on orders of', 10);
            await t.as('visitor');
            await t.go('/medicines/1');
            await t.click('Add to cart', { wait: 1500 });
            await t.go('/checkout');
            await t.see(/the delivery charge is paid first/, 'Checkout says a big order pays the delivery charge first');
            await t.page.locator('#co-name').fill('QA Shopper');
            await t.page.locator('#co-phone').fill('01711111111');
            await t.page.locator('#co-district').selectOption({ label: 'Dhaka' });
            await t.page.locator('#co-area').fill('Mirpur');
            await t.page.locator('#co-address').fill('House 12, Road 4');
            await t.click('Send order', { wait: 2500 });
            const buyer = t.page.url();
            await t.shot('The shopper\'s order: the delivery charge comes first');
            await t.as('admin');
            await t.go('/admin/orders');
            const ids = (await t.page.locator('main a[href*="/admin/orders/"]').evaluateAll((a) => a.map((x) => Number(x.getAttribute('href').split('/').pop())))).filter(Boolean);
            const owner = `/admin/orders/${Math.max(...ids)}`;
            await t.go(owner);
            const buttons = await t.page.evaluate(() => [...document.querySelectorAll('main button')].filter((x) => x.offsetWidth).map((x) => x.innerText.trim()).join(' | '));
            t.note(`The owner's buttons: ${buttons}`);
            await t.shot('The owner order page');
            await t.page.locator('main input[type=number]').last().fill('50');
            await t.page.getByRole('button', { name: 'Save', exact: true }).click();
            await t.wait(1500);
            await t.click('Delivery charge received', { wait: 2000 });
            await t.shot('The owner marks the delivery charge as received');
            await t.as('visitor');
            await t.go(buyer);
            await t.click('Cancel order', { wait: 2500 });
            await t.shot('The shopper cancels before it was sent');
            await t.see(/Half of the delivery charge you paid goes back to you \(৳25\.00\)\. The other half stays for the handling\./, 'Cancelled before sending: half of the delivery charge paid goes back, and the page says the other half stays for the handling');
            await setSetting(t, 'Free delivery over (taka, 0 for never)', free);
            await setSetting(t, 'Delivery charge paid first on orders of', first);
            t.note(`Order page of the shopper: ${buyer}; the owner's: ${owner}`);
        },
    },
];
