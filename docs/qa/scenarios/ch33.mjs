// Re-test after the first fix round: returns (feature 279). The mark lives on the owner's Returns screen, not on the shop's page.

export default [
    {
        n: 279, role: 'paikari',
        did: 'As a paikari shop, asked to return one strip from its newest memo. Then, as the owner, looked the same memo up on the Returns screen.',
        async run(t) {
            await t.as('paikari');
            await t.go('/paikari/returns');
            // Ask for a return on the first memo that still allows one (an earlier run may already have used the newest).
            const blocks = t.page.locator('li').filter({ hasText: 'Ask to return' });
            let memo = null;
            for (let i = 0; i < (await blocks.count()) && memo === null; i++) {
                const memoBlock = blocks.nth(i);
                const line = memoBlock.locator('ul > li').first();
                if (!(await line.locator('select option', { hasText: 'Strip' }).count())) continue;
                const text = await memoBlock.innerText();
                if (!/Napa 500 mg · \d+ Strip/.test(text) && !/Strip/.test(text)) continue;
                await line.locator('select').first().selectOption({ label: 'Strip' });
                await line.getByPlaceholder('How much').fill('1');
                await memoBlock.locator('select').nth(2).selectOption({ label: 'Damaged' });
                await memoBlock.locator('select').nth(3).selectOption({ label: 'Credit on my next memo' });
                await memoBlock.getByPlaceholder('Reason (required)').fill('Strip is torn');
                memo = text.match(/M\d{4}-\d{6}/)[0];
                await t.shot('The shop asks to return one strip');
                await memoBlock.getByRole('button', { name: 'Ask to return' }).click();
                await t.wait(2000);
            }
            await t.as('admin');
            await t.go('/admin/returns');
            await t.fill('Memo number', memo);
            await t.click('Search', { wait: 2000 });
            await t.page.locator('main button, main a').filter({ hasText: memo }).first().click();
            await t.wait(1500);
            await t.shot(`The owner opens memo ${memo}`);
            await t.see(/Previously returned/, 'The line that already has a return is marked "Previously returned"');
        },
    },
];
