// Partly-shown pass, made to order job 2 carried to the end: the wood comes off stock, then the work is finished (414, 411, 387).
// Set-up through the screens before this file: the owner set Teak "In stock" to 10, priced job 2 (material used 2, wages 7,000, total 19,500, 30% advance),
// and the buyer ticked the checkbox and pressed Confirm.

async function teakStock(t) {
    await t.as('admin');
    await t.go('/admin/custom');
    const row = t.page.locator('input[aria-label="Name"][value="Teak"]').locator('xpath=ancestor::*[.//button[normalize-space()="Save"]][1]');
    return Number(await row.locator('input[aria-label="In stock"]').inputValue());
}

export default [
    {
        n: 414, role: 'admin',
        did: 'Read the Teak stock (10 cubic feet), gave job 2 to the craftsman, took the advance, pressed Start the work, and read the Teak stock again.',
        async run(t) {
            const before = await teakStock(t);
            await t.shot(`Teak in stock before the work starts: ${before}`);
            const card = () => t.page.locator('main').locator('div,li').filter({ hasText: 'C2026-00002' }).filter({ has: t.page.getByRole('button', { name: 'Start the work' }) }).last();
            const give = card().getByRole('button', { name: 'Give the job' });
            if (await give.count()) {
                await give.click();
                await t.wait(1800);
            }
            await card().getByPlaceholder('Amount').fill('5850');
            await card().getByRole('button', { name: 'Advance received' }).click();
            await t.wait(1800);
            await t.shot('Job given and advance taken');
            await card().getByRole('button', { name: 'Start the work' }).click();
            await t.wait(2200);
            await t.shot('The work has started');
            const after = await teakStock(t);
            await t.shot(`Teak in stock after: ${after}`);
            t.check(before - after === 2, `The 2 cubic feet of wood used came off the stock (${before} became ${after})`, `the stock went from ${before} to ${after}`);
        },
    },
    {
        n: 411, role: 'visitor',
        did: 'The craftsman finished job 2 (100%) and the owner pressed "It is ready". The buyer then wrote what was wrong and pressed "Work not done right". The owner page was checked.',
        async run(t) {
            await t.as('admin');
            await t.go('/admin/custom');
            const ready = t.page.getByRole('button', { name: 'It is ready' });
            if (await ready.count()) {
                await t.page.locator('main').locator('div,li').filter({ hasText: 'C2026-00002' }).filter({ has: ready }).last().getByRole('button', { name: 'It is ready' }).click();
                await t.wait(2000);
            }
            await t.as('visitor');
            await t.go('/custom/Lw6cFD00taRQezAUb5HrKWEK4nxqoB7iWet0Qs3x');
            await t.shot('The buyer page when the job is ready: a "Work not done right" box');
            await t.see(/Ready to collect/, 'The buyer is told the job is ready to collect');
            await t.page.getByPlaceholder('What is wrong?').fill('One drawer sticks and the top has a scratch');
            await t.click('Work not done right', { wait: 2200 });
            await t.shot('After sending the complaint');
            await t.see(/One drawer sticks and the top has a scratch/, 'The complaint is kept on the job');
            await t.as('admin');
            await t.go('/admin/custom');
            await t.shot('The owner page shows the complaint');
            await t.see(/One drawer sticks|complaint/i, 'The owner sees the complaint on the job');
        },
    },
];
