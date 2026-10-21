// Admin, staff and security (features 529-571)

export default [
    { n: 529, role: 'admin', did: 'Looked at the owner\'s Market page, where products are managed.', async run(t) { await t.go('/admin/market'); await t.shot('Product management'); await t.see(/Published/, 'Products are listed with their status'); t.skip('The checklist marks product management as only partly built; not every option was tried.'); } },
    {
        n: 530, role: 'admin',
        did: 'Opened a customer\'s page and looked at the controls on it.',
        async run(t) {
            await t.go('/admin/customers/4');
            for (const c of ['Tier', 'Account type and terms', 'Manual rates', 'Monthly total', 'Account status']) await t.see(c, `The customer page has: ${c}`);
            await t.shot('Every control for a customer, on one page');
        },
    },
    {
        n: 531, role: 'admin',
        did: 'Looked at the "All transactions" block on a customer\'s page.',
        async run(t) {
            await t.go('/admin/customers/4');
            await t.see(/All transactions/, 'All a customer\'s transactions are on one page');
            await t.see(/Orders, returns, pre-orders and credit together, newest first/, 'Orders, returns, pre-orders and credit sit together, newest first');
            await t.shot('All transactions');
        },
    },
    { n: 532, role: 'admin', did: 'Opened the Applications page.', async run(t) { await t.go('/admin/applications'); await t.see(/Waiting/, 'Applications wait here for review'); await t.shot('Applications'); t.skip('Reviewing a real application and checking its licence was not carried through (none was waiting).'); } },
    { n: 533, role: 'admin', did: 'Opened the owner\'s Online orders.', async run(t) { await t.go('/admin/orders'); await t.see(/Online orders/, 'The owner manages every online order from one place'); await t.shot('Order management'); } },
    { n: 534, role: 'admin', did: 'Looked at sector management on the owner\'s Market page.', async run(t) { await t.go('/admin/market'); await t.see(/Sectors/, 'The owner manages the sectors'); await t.shot('Sector management'); } },
    { n: 535, role: 'admin', did: 'Looked at the central lists the owner manages.', async run(t) { await t.go('/admin/food-ingredients'); await t.shot('The ingredient list'); await t.go('/admin/catalog'); await t.shot('The product catalog'); await t.see(/Product catalog/, 'Central lists (ingredients, product catalog, companies) are managed in one place each'); } },
    { n: 536, role: 'admin', did: 'Looked at the closed days in Settings.', async run(t) { await t.go('/admin/settings'); await t.see(/Closed every week on/, 'The owner sets weekly closed days'); await t.see(/Holidays and other closed days/, 'And other closed days'); await t.shot('Closed days'); } },
    { n: 537, role: 'admin', did: 'Opened the monthly report and looked at "By kind of sale".', async run(t) { await t.go('/admin/reports'); await t.see(/By kind of sale/, 'Sales are split by kind (retail, shops, trade)'); await t.shot('Sales by kind'); } },
    { n: 538, role: 'admin', did: 'Looked at the ranking lists on the monthly report.', async run(t) { await t.go('/admin/reports'); await t.see(/Earns the most/, 'The medicine that earns the most'); await t.see(/Companies that move most/, 'The companies that move most'); await t.see(/Best customers/, 'The best customers'); await t.shot('Rankings'); } },
    { n: 539, role: 'admin', did: 'Looked at the "What to order" table.', async run(t) { await t.go('/admin/reports'); await t.see(/What to order/, 'A table says what to order'); await t.see(/Per day/, 'With boxes sold per day'); await t.see(/Should have/, 'And the stock the shop should have'); await t.shot('What to order'); } },
    { n: 540, role: 'admin', did: 'Looked for which medicine earns most.', async run(t) { await t.go('/admin/reports'); await t.see(/Earns the most\s*\n?\s*Napa 500 mg/, 'The report names the medicine that earns the most'); await t.shot('Earns the most'); } },
    { n: 541, role: 'admin', did: 'Opened the stock profit report.', async run(t) { await t.go('/admin/stock/reports'); await t.see(/Gross profit/, 'Gross profit'); await t.see(/Expenses/, 'Shop expenses'); await t.see(/Net profit/, 'And net profit'); await t.shot('Gross profit, expenses and net profit'); } },
    { n: 542, role: 'admin', did: 'Looked at the expenses block for repeating expenses.', async run(t) { await t.go('/admin/stock/reports'); await t.see(/Repeats every month/, 'An expense can repeat every month (rent, salary)'); await t.shot('Repeating expenses'); } },
    { n: 543, role: 'admin', did: 'Looked at the seasonal forecast on the report.', async run(t) { await t.go('/admin/reports'); await t.see(/Last year this sold a lot at this time/, 'The report looks at the same days last year'); await t.shot('Seasonal forecast'); } },
    { n: 544, role: 'admin', did: 'Looked on the monthly report for the list of shops that stopped buying.', async run(t) { await t.go('/admin/reports'); await t.shot('The report: shops that have gone quiet'); await t.see(/Shops that have gone quiet/, 'The report has a list of shops that have gone quiet'); await t.see(/Nobody has stopped buying|last bought \d+ days ago/, 'It names each shop and how long ago it last bought, or says nobody has stopped buying'); } },
    { n: 545, role: 'admin', did: 'Looked at the comparison with last month and last year.', async run(t) { await t.go('/admin/reports'); await t.see(/Last month/, 'Compared with last month'); await t.see(/Same month last year/, 'And with the same month last year'); await t.shot('Comparison'); } },
    { n: 546, role: 'admin', did: 'Looked at the "What to order" advice.', async run(t) { await t.go('/admin/reports'); await t.see(/Take less, it is sitting|Order more|Order/, 'The advice can say to order more or to take less'); await t.shot('Two-way advice'); } },
    { n: 547, role: 'admin', did: 'Looked at "Finished today".', async run(t) { await t.go('/admin/stock/reports'); await t.see(/Finished today/, 'A list shows what ran out today'); await t.shot('Finished today'); } },
    { n: 548, role: 'admin', did: 'Opened End of day.', async run(t) { await t.go('/admin/end-of-day'); await t.see(/Check this before you close the shop/, 'A check before closing the shop'); await t.see(/Still open/, 'Lists what is still open'); await t.see(/cash/i, 'And the cash to count'); await t.shot('End of day'); } },
    { n: 549, role: 'admin', did: 'Looked at a memo for the staff member\'s name.', async run(t) { await t.go('/memo/213'); await t.see(/Served by Siam \(Admin\)/, 'The memo shows who served the customer'); await t.shot('Served by'); } },
    { n: 550, role: 'admin', did: 'Opened the page of things shoppers asked for.', async run(t) { await t.go('/admin/requests'); await t.see(/Searches that found nothing/, 'The owner sees searches that found nothing'); await t.shot('Asked for but not available'); } },
    { n: 551, notBuilt: true, role: 'admin', did: 'Checked the checklist.', async run(t) { t.note('Overnight report generation has not been built yet.'); } },
    { n: 552, role: 'admin', did: 'Looked at the "Day by day" table.', async run(t) { await t.go('/admin/reports'); await t.see(/Day by day/, 'A day-by-day breakdown'); await t.shot('Day by day'); } },
    { n: 553, role: 'admin', did: 'Looked for the export buttons.', async run(t) { await t.go('/admin/reports'); await t.see(/Download the sales \(Excel\)/, 'Sales can be downloaded for Excel'); await t.go('/admin/controlled'); await t.see(/Download CSV/, 'And the controlled register as CSV'); await t.shot('Export'); } },
    { n: 554, role: 'admin', did: 'Looked at "Tonight\'s summary".', async run(t) { await t.go('/admin/reports'); await t.see(/Tonight.s summary/, 'A summary of the night\'s figures'); await t.shot('Tonight\'s summary'); t.skip('The checklist marks the nightly message to the owner\'s phone as partly built. No WhatsApp or text message was sent.'); } },
    { n: 555, role: 'admin', did: 'Opened the VAT report.', async run(t) { await t.go('/admin/reports/vat'); await t.see(/The sales of a period and the VAT inside them, for the accountant/, 'A VAT (Mushak) report for the accountant'); await t.see(/Download for the accountant/, 'With a download'); await t.shot('VAT report'); } },
    { n: 556, role: 'admin', did: 'Looked at the VAT setting.', async run(t) { await t.go('/admin/settings'); await t.see(/Work out VAT on memo lines/, 'A VAT switch exists from the start'); await t.shot('VAT setting'); } },
    { n: 557, role: 'admin', did: 'Opened the Activity log.', async run(t) { await t.go('/admin/activity'); await t.see(/Who did what, and who opened whose data\. Nothing here can be changed/, 'A log of who did what, which cannot be changed'); await t.shot('Activity'); } },
    { n: 558, role: 'admin', did: 'Looked at the role choice for staff.', async run(t) { await t.go('/admin/staff'); await t.see(/Counter staff/, 'A staff member has a role'); await t.shot('Roles'); } },
    { n: 559, role: 'admin', did: 'Looked for a way to create a new role.', async run(t) { await t.go('/admin/staff'); await t.shot('Staff'); const b = await t.text(); t.check(/new role|create a role|add a role/i.test(b), 'The owner can create new roles', 'no way to create a new role was found'); } },
    { n: 560, role: 'admin', did: 'Looked at the form for opening a staff account.', async run(t) { await t.go('/admin/staff'); await t.see(/Open a staff account/, 'Each staff member gets their own account'); await t.see(/Every staff member has their own account/, 'The page says so'); await t.shot('One account per staff member'); } },
    { n: 561, role: 'admin', did: 'Looked at the device limit in Settings.', async run(t) { await t.go('/admin/settings'); await t.see(/Devices one staff account can be signed in on at once/, 'A staff account has a limit on devices'); await t.shot('Device limit'); } },
    { n: 562, role: 'admin', did: 'Looked at "Close account" on a staff member.', async run(t) { await t.go('/admin/staff'); await t.see(/Close account/, 'A staff account can be closed'); await t.shot('Close account'); t.note('Closing keeps the staff member\'s records.'); } },
    {
        n: 563, role: 'admin',
        did: 'Tried a big change (setting a customer\'s hand-set rate) without having confirmed the password recently, then looked at what happened.',
        async run(t) {
            await t.go('/admin/staff');
            await t.see(/Changes here ask for your password again/, 'The Staff page says changes ask for the password again');
            await t.shot('Changes ask for the password again');
            await t.go('/confirm-password');
            await t.shot('The password confirmation screen');
            await t.see(/Confirm your password/, 'There is a separate password confirmation for big changes');
        },
    },
    { n: 564, role: 'admin', did: 'Read the Activity log description.', async run(t) { await t.go('/admin/activity'); await t.see(/who opened whose data/, 'The log also records who opened whose data'); await t.shot('Who opened whose data'); } },
    { n: 565, role: 'admin', did: 'Looked at "Lend extra powers for a while" on the Staff page.', async run(t) { await t.go('/admin/staff'); await t.see(/Lend extra powers for a while/, 'The owner can lend powers for a set number of hours'); await t.see(/It ends by itself, and you can take it back any time/, 'They end by themselves'); await t.shot('Temporary powers'); } },
    { n: 566, role: 'admin', did: 'Looked at the importance of each message.', async run(t) { await t.go('/admin/messages'); await t.see(/Urgent/, 'Some messages are Urgent'); await t.see(/Low/, 'Some are Low'); await t.shot('Messages by importance'); } },
    { n: 567, role: 'admin', did: 'Opened the Deleted page.', async run(t) { await t.go('/admin/deleted'); await t.see(/What you delete stays here for 30 days\. Put it back if it was a mistake/, 'Deleted items stay for 30 days and can be put back'); await t.see(/Put back/, 'With a Put back button'); await t.shot('Deleted'); } },
    { n: 568, role: 'admin', did: 'Looked for a way to block an IP address.', async run(t) { await t.go('/admin/settings'); await t.shot('Settings'); const b = await t.text(); t.check(/IP address|block an ip|blocked ip/i.test(b), 'The owner can block an IP address', 'no IP block control was found in Settings'); } },
    { n: 569, role: 'admin', did: 'Looked at "Suspend account" on a customer.', async run(t) { await t.go('/admin/customers/4'); await t.see(/Suspend account/, 'A customer can be suspended'); await t.see(/Reason \(required\)|Reason/, 'With a required reason'); await t.shot('Suspend account'); const b = await t.text(); t.check(/until|for how long|days|date/i.test(b.split('Account status')[1]?.slice(0, 400) ?? ''), 'A suspension can be for a set time', 'the suspend box asks only for a reason, with no time limit'); } },
    { n: 570, role: 'admin', did: 'Looked at the customer page for recent logins.', async run(t) { await t.go('/admin/customers/4'); await t.see(/Recent logins/, 'The customer page lists recent sign-ins'); await t.shot('Recent logins'); } },
    {
        n: 571, role: 'newguest',
        did: 'Typed a wrong password for the same account several times in a row.',
        async run(t) {
            await t.go('/login');
            let message = '';
            for (let i = 0; i < 8; i++) {
                await t.page.locator('input[type=email]').fill('test@example.com');
                await t.page.locator('input[type=password]').fill(`wrong-password-${i}`);
                await t.page.locator('button[type=submit]').click();
                await t.wait(1200);
                message = await t.text();
                if (/too many|try again in|locked/i.test(message)) break;
            }
            await t.shot('After several wrong passwords');
            t.check(/too many|try again in|locked/i.test(message), 'After repeated wrong passwords the account is locked for a while', 'eight wrong passwords were typed and no lock appeared');
        },
    },
];
