// Re-test: the medicine checkout already has "for whom" and saved addresses (features 306, 317). Earlier tests looked on the wrong screens.

async function toCheckout(t) {
    await t.as('visitor');
    await t.go('/medicines/1');
    await t.click('Add to cart', { wait: 1500 });
    await t.go('/checkout');
}

export default [
    {
        n: 317, role: 'visitor',
        did: 'Put a medicine in the cart and opened checkout, looking for a box saying who the parcel is for.',
        async run(t) {
            await toCheckout(t);
            await t.shot('Checkout: "Ordering for someone else? Their name"');
            await t.see(/Ordering for someone else\? Their name/, 'The order form asks who the parcel is for, if it is not the buyer');
        },
    },
    {
        n: 306, role: 'visitor',
        did: 'Ordered with "Save this address for next time" ticked, then started a new order and looked for the saved address.',
        async run(t) {
            await toCheckout(t);
            await t.page.locator('#co-name').fill('QA Shopper');
            await t.page.locator('#co-phone').fill('01711111111');
            await t.page.locator('#co-district').selectOption({ label: 'Dhaka' });
            await t.page.locator('#co-area').fill('Mirpur');
            await t.page.locator('#co-address').fill('House 12, Road 4');
            await t.page.getByLabel('Save this address for next time').check();
            await t.shot('Saving the address with the order');
            await t.click('Send order', { wait: 2500 });
            await toCheckout(t);
            await t.shot('A new checkout offers the saved address');
            await t.see(/Your saved addresses/, 'A shopper who saved an address sees it the next time they order');
            await t.see(/House 12, Road 4/, 'With its details, ready to use');
        },
    },
];
