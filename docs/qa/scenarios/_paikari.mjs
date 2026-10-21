// Shared steps for the paikari ordering scenarios.

export async function closeNotices(t) {
    for (let i = 0; i < 10; i++) {
        const close = t.page.getByRole('button', { name: 'Close', exact: true }).first();
        if (!(await close.count())) break;
        await close.click().catch(() => {});
        await t.page.waitForTimeout(250);
    }
}

/** Search for Napa as the paikari shop and leave the results showing. */
export async function searchNapa(t, role = 'paikari') {
    await t.as(role);
    await t.go('/paikari');
    await closeNotices(t);
    await t.fill('Medicine name', 'napa');
    await t.wait(1800);
}

/** Put a box of Napa 500 mg (Square) on the order. Pass the price the shop should see on the button. */
export async function addBox(t, price, qty = 1) {
    if (qty !== 1) await t.page.locator('input[type=number]').first().fill(String(qty));
    // The first "Box" price button on the page. Pass a price to insist on one.
    await t.click(new RegExp(price ? `Box.{0,4}${price}` : 'Box.{0,4}\\d'), { wait: 1500 });
}

/** The whole trip: a paikari shop sends an order for Napa. Returns once the order is sent. */
export async function sendOrder(t, { qty = 1, price, collect } = {}) {
    await searchNapa(t);
    await addBox(t, price, qty);
    if (collect) await t.page.locator('select').last().selectOption({ label: collect });
    await t.click(/Send order/, { wait: 2500 });
}

/** The newest online order in the owner's list. */
export async function newestOrder(t) {
    await t.as('admin');
    await t.go('/admin/orders');
    const hrefs = await t.page.locator('main a[href*="/admin/orders/"]').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
    return hrefs.sort((x, y) => Number(y.split('/').pop()) - Number(x.split('/').pop()))[0];
}
