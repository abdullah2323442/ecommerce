// Shared steps for scenarios that use the counter and the stock screens.

/** How many boxes of Napa 500 mg (Square) the shop holds, read from the owner's stock page. */
export async function napaStock(t) {
    await t.as('admin');
    await t.go('/admin/stock');
    const body = await t.text();
    const m = body.match(/Napa 500 mg[\s\S]{0,60}?(\d+)\s*box/i);
    return m ? Number(m[1]) : null;
}

export async function newRetail(t) {
    await t.as('admin');
    await t.go('/admin/counter');
    await t.click('New retail sale', { wait: 1800 });
}

/** Search for Napa and add one unit (Box, Strip or Piece) by clicking its price. */
export async function addNapa(t, unit = 'Box', price = '300') {
    await t.fill('Medicine name', 'napa');
    await t.wait(1300);
    await t.click(new RegExp(`${unit}.{0,4}${price}`), { wait: 1200 });
}

export async function finishSale(t) {
    await t.click('Finish sale', { wait: 2000 });
}

/** Put Master Stock Sync in a known state before a test (clean-up, not a result). */
export async function ensureSync(t, on) {
    await t.as('admin');
    await t.go('/admin/stock');
    const body = await t.text();
    const isOn = /Master Stock Sync\s*On/i.test(body);
    if (isOn !== on) {
        await t.page.getByRole('button', { name: on ? 'Turn on' : 'Turn off' }).click();
        await t.wait(1200);
    }
}
