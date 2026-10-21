// Re-test: the referral part of the wallet (feature 603). It is switched off until the owner sets a reward.

async function setSetting(t, label, value) {
    await t.as('admin');
    await t.go('/admin/settings');
    const box = t.page.getByLabel(label, { exact: false }).first();
    const old = await box.inputValue();
    await box.fill(String(value));
    const save = box.locator('xpath=ancestor::*[.//button[normalize-space()="Save"]][1]').getByRole('button', { name: 'Save', exact: true }).first();
    await save.click();
    await t.wait(1500);
    return old;
}

export default [
    {
        n: 603, role: 'visitor',
        did: 'The owner set the token for a referral to 50 taka. A shopper then opened the wallet. The owner switched it off again afterwards.',
        async run(t) {
            const old = await setSetting(t, 'Token for a friend who joins by a referral and orders', 50);
            await t.as('visitor');
            await t.go('/wallet');
            await t.shot('The wallet with the referral switched on');
            await t.see(/Invite a friend/, 'The wallet offers "Invite a friend"');
            await t.see(/you get a token of ৳50\.00/, 'It says what the shopper earns when the friend orders');
            await t.see(/Joined by your link: \d+/, 'And counts the friends who joined by the shopper\'s link');
            await setSetting(t, 'Token for a friend who joins by a referral and orders', old);
            t.skip('Points earned on the shopper\'s own purchases are not built. The checklist marks this feature as partly built: the referral token works, the purchase points do not exist yet.');
        },
    },
];
