// Built after the first fix round: customer discounts set for a whole company and for one medicine (features 23, 24).

async function pickCompany(t, name) {
    await t.page.locator('#sr-company').fill(name);
    await t.wait(500);
    await t.page.locator('ul button', { hasText: name }).first().click();
}

export default [
    {
        n: 23, role: 'admin',
        did: 'On the Tiers page, set a 10% rate for the whole company "Square", then looked at what a Square medicine costs a shop, then removed the rate.',
        async run(t) {
            await t.preConfirm();
            await t.as('admin');
            await t.go('/admin/tiers');
            await t.see(/Rates by company and by medicine/, 'The Tiers page has a place for rates by company');
            await pickCompany(t, 'Square');
            await t.page.locator('#sr-company-pct').fill('10');
            await t.shot('A 10% rate for the company Square');
            await t.click('Save company rate', { wait: 2200 });
            await t.shot('The company rate is saved and listed');
            await t.see(/Square[\s\S]{0,40}10% off/, 'The company rate is saved: every Square medicine is 10% off for customers without a rate of their own');
            await t.page.locator('li', { hasText: 'Square' }).filter({ hasText: '10% off' }).getByRole('button', { name: 'Remove' }).first().click();
            await t.wait(1800);
            await t.shot('Removed again');
        },
    },
    {
        n: 24, role: 'admin',
        did: 'On the Tiers page, set a 12% rate for one medicine (Napa 500 mg), then removed it.',
        async run(t) {
            await t.preConfirm();
            await t.as('admin');
            await t.go('/admin/tiers');
            await t.fill('Search a medicine', 'napa');
            await t.wait(1200);
            await t.click(/Napa 500 mg\s*tablet/i);
            await t.page.locator('#sr-med-value').fill('12');
            await t.shot('A 12% rate for one medicine');
            await t.click('Save medicine rate', { wait: 2200 });
            await t.shot('The medicine rate is saved and listed');
            await t.see(/Napa[\s\S]{0,60}12% off/, 'The rate for one medicine is saved');
            await t.page.locator('li', { hasText: 'Napa' }).filter({ hasText: '12% off' }).getByRole('button', { name: 'Remove' }).first().click();
            await t.wait(1800);
            await t.shot('Removed again');
        },
    },
];
