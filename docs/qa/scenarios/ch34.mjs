// Re-test after the second fix round: the restaurant's custom food page (features 429-431). They live on the restaurant's own page, not the owner's.

async function row(t) {
    return t.page.locator('li').filter({ has: t.page.getByPlaceholder('Your price') }).first();
}

export default [
    {
        n: 429, role: 'vendor',
        did: 'As the restaurant, switched on one ingredient ("Bacon") at a price, then ticked "Sold out today" on it and reloaded the page.',
        async run(t) {
            await t.go('/food/vendor/custom');
            const free = await row(t);
            if (await free.count()) {
                await free.getByPlaceholder('Your price').fill('30');
                await free.getByRole('button').first().click();
                await t.wait(1800);
            }
            const mine = t.page.locator('li').filter({ hasText: 'Sold out today' }).first();
            await t.shot('An ingredient the restaurant has: price, On, Sold out today');
            await t.see(/Sold out today/, 'Each ingredient the restaurant offers has a "Sold out today" tick');
            const box = mine.getByRole('checkbox').last();
            if (!(await box.isChecked())) await box.click();
            await t.wait(1500);
            await t.go('/food/vendor/custom');
            await t.shot('After ticking it and reloading');
            const again = t.page.locator('li').filter({ hasText: 'Sold out today' }).first().getByRole('checkbox').last();
            t.check(await again.isChecked(), 'The tick stays after the page is reloaded, so the ingredient is marked sold out for today', 'the tick was lost');
            await again.click();
            await t.wait(1200);
        },
    },
    {
        n: 430, role: 'vendor',
        did: 'On the restaurant\'s custom food page, looked at the limit on how many ingredients one dish can have.',
        async run(t) {
            await t.go('/food/vendor/custom');
            await t.page.getByText('Limit and time').scrollIntoViewIfNeeded();
            await t.shot('Limit and time');
            await t.see(/Most ingredients in one dish/, 'The restaurant can set the most ingredients in one dish');
            await t.see(/Extra minutes for custom food/, 'And the extra cooking minutes custom food needs');
        },
    },
    {
        n: 431, role: 'vendor',
        did: 'On the restaurant\'s custom food page, looked at the rules for ingredients that do not go together.',
        async run(t) {
            await t.go('/food/vendor/custom');
            await t.page.getByText('These do not go together').scrollIntoViewIfNeeded();
            await t.shot('Ingredients that do not go together');
            await t.see(/These do not go together/, 'The restaurant can list ingredients that do not go together');
            await t.see(/First ingredient[\s\S]{0,200}Second ingredient/, 'By picking a first and a second ingredient');
        },
    },
];
