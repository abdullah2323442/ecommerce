// Start or reopen a counter sale for a shop. The suggestion says "Start" for a new sale and "Open it" when a draft already exists.
export async function openShopSale(t, who) {
    await t.as('admin');
    await t.go('/admin/counter');
    await t.fill('css=#cust-q', who);
    await t.wait(1600);
    const reopen = t.page.getByRole('button', { name: 'Open it', exact: true });
    if (await reopen.count()) await t.click('css=button:text-is("Open it")', { wait: 2200 });
    else await t.click('css=button:text-is("Start")', { wait: 2200 });
}
