// Partly-shown pass, returns: whose mistake it was, and the warnings that follow (features 277, 278).
// Each round: the shop asks to return one strip on a memo that still allows it, the owner approves it and names whose mistake it was.

/** The shop asks to return one strip of Napa for the given reason. Returns the memo number, or null if no memo allowed it. */
async function askReturn(t, reasonLabel) {
    await t.as('paikari');
    await t.go('/paikari/returns');
    const blocks = t.page.locator('li').filter({ hasText: 'Ask to return' });
    for (let i = 0; i < (await blocks.count()); i++) {
        const memoBlock = blocks.nth(i);
        const line = memoBlock.locator('ul > li').first();
        if (!(await line.locator('select option', { hasText: 'Strip' }).count()) || !(await line.getByText('Returnable').count())) continue;
        const memo = (await memoBlock.innerText()).match(/M\d{4}-\d{6}/)?.[0];
        if (!memo) continue;
        await line.locator('select').first().selectOption({ label: 'Strip' });
        await line.getByPlaceholder('How much').fill('1');
        await memoBlock.locator('select').filter({ hasText: 'Wrong item' }).selectOption({ label: reasonLabel });
        await memoBlock.locator('select').filter({ hasText: 'Credit on my next memo' }).selectOption({ label: 'Credit on my next memo' });
        await memoBlock.getByPlaceholder('Reason (required)').fill('Ordered the wrong thing');
        await memoBlock.getByRole('button', { name: 'Ask to return' }).click();
        await t.wait(2000);
        return memo;
    }
    return null;
}

/** The owner approves the first waiting return and names whose mistake it was ('shop', 'staff' or ''). */
async function approve(t, fault) {
    await t.as('admin');
    await t.go('/admin/returns');
    await t.page.getByLabel('Whose mistake').first().selectOption(fault);
    await t.page.getByRole('button', { name: 'Approve', exact: true }).first().click();
    await t.wait(2000);
}

export default [
    {
        n: 277, role: 'admin',
        did: 'The shop asked to return a strip "Ordered by mistake". The owner approved it and named it the shop\'s mistake. Then the same for a return that was the staff\'s mistake. The shop\'s and the staff member\'s messages were read.',
        async run(t) {
            await askReturn(t, 'Ordered by mistake');
            await t.as('admin');
            await t.go('/admin/returns');
            await t.shot('The owner picks whose mistake it was');
            await t.see(/Whose mistake|The shop.s mistake/, 'The owner is asked whose mistake a return was');
            await approve(t, 'shop');
            await t.as('paikari');
            await t.go('/inbox');
            await t.shot('The shop is warned');
            const shop = await t.text();
            t.check(/counted as your own mistake/.test(shop), 'The shop gets a warning that the return was its own mistake', 'no warning reached the shop');
            t.note(`What the shop sees: ${shop.replace(/\s+/g, ' ').slice(0, 300)}`);
            // A sale made by the staff member, so the warning has someone to go to.
            await t.as('staff');
            await t.go('/admin/counter');
            await t.fill('css=#cust-q', 'Karim');
            await t.wait(1500);
            await t.click('css=:is(a,button):text-is("Open it"), :is(a,button):text-is("Start")', { wait: 2200 });
            await t.page.getByLabel('Medicine name').fill('napa');
            await t.wait(1500);
            await t.page.getByRole('button', { name: /Box.{0,4}\d/ }).first().click();
            await t.wait(1500);
            await t.click('Finish sale', { wait: 2500 });
            await askReturn(t, 'Wrong item');
            await approve(t, 'staff');
            await t.as('staff');
            await t.go('/admin/counter');
            await t.shot('The staff member who made the sale is warned');
            const staff = await t.text();
            t.check(/A warning for you[\s\S]*counted as your mistake/.test(staff), 'The staff member who made the sale gets a warning', 'no warning reached the staff member');
        },
    },
    {
        n: 278, role: 'admin',
        did: 'The shop returned a strip "Ordered by mistake" three times in a row, and the owner named each one the shop\'s mistake. The shop\'s messages were read.',
        async run(t) {
            for (let i = 0; i < 3; i++) {
                await askReturn(t, 'Ordered by mistake');
                await approve(t, 'shop');
            }
            await t.as('paikari');
            await t.go('/inbox');
            await t.shot('After the same mistake three times');
            const body = await t.text();
            t.check(/has now happened three times in 90 days/.test(body), 'After the same mistake three times the shop gets a stronger "happening repeatedly" warning on its own', 'no repeat warning appeared: ' + body.replace(/\s+/g, ' ').slice(0, 300));
        },
    },
];
