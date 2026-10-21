// Built after the first fix round: blocking an address (568) and suspending an account for a set number of days (569).

export default [
    {
        n: 568, role: 'admin',
        did: 'Opened "Blocked addresses" in the owner\'s menu, blocked the address 203.0.113.7 for 2 days with a reason, then lifted the block.',
        async run(t) {
            await t.preConfirm();
            await t.as('admin');
            await t.go('/admin/blocked-ips');
            await t.shot('Blocked addresses');
            await t.page.locator('#bip-ip').fill('203.0.113.7');
            await t.page.locator('#bip-reason').fill('Password guessing');
            await t.page.locator('#bip-days').fill('2');
            await t.shot('An address, a reason and 2 days');
            await t.click('Block', { wait: 2200 });
            await t.shot('The address is blocked');
            await t.see(/203\.0\.113\.7/, 'The address is listed as blocked');
            await t.see(/Until /, 'With the day the block ends');
            await t.go('/admin/blocked-ips');
            await t.page.locator('li', { hasText: '203.0.113.7' }).getByRole('button', { name: 'Unblock' }).click();
            await t.wait(1800);
            await t.shot('The block is lifted');
            await t.notSee(/203\.0\.113\.7/, 'After unblocking the address is gone from the list');
            await t.page.locator('#bip-ip').fill('127.0.0.1');
            await t.click('Block', { wait: 1800 });
            await t.see(/lock you out/, 'The owner cannot block the address they are using');
        },
    },
    {
        n: 569, role: 'admin',
        did: 'On a customer, wrote a reason and 3 days in the Suspend box and pressed Suspend account, then looked at the status.',
        async run(t) {
            await t.preConfirm();
            await t.as('admin');
            await t.go('/admin/customers/6');
            await t.page.locator('#reason').fill('Rude to staff');
            await t.page.locator('#suspend-days').fill('3');
            await t.shot('A reason and a number of days');
            await t.click('Suspend account', { wait: 2200 });
            await t.shot('The account is suspended until a date');
            await t.see(/Suspended since .{3,40}, until /, 'The status says the suspension lasts until a date');
            await t.click('Reactivate account', { wait: 1800 });
            await t.shot('Reactivated again');
        },
    },
];
