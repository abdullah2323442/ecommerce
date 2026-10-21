// Partly-shown pass, returns on a retail sale (features 268, 271).
// A retail sale has no customer account, so there is nothing to credit: the buyer gets cash or an exchange.
import { addNapa, ensureSync, finishSale, newRetail } from './_counter.mjs';

let memo = null;

export default [
    {
        n: 268, role: 'admin',
        did: 'Sold a strip of Napa at the counter to a walk-in buyer (no account). Then, on the Returns screen, found the memo and recorded a return of that strip, asking for cash back. The owner approved it.',
        async run(t) {
            await ensureSync(t, true);
            await newRetail(t);
            await addNapa(t, 'Strip', '100');
            await finishSale(t);
            memo = (await t.text()).match(/M\d{4}-\d{6}/)?.[0];
            await t.shot(`A walk-in sale, memo ${memo}`);
            await t.go('/admin/returns');
            await t.fill('Memo number', memo);
            await t.click('Search', { wait: 2000 });
            await t.page.locator('main button, main a').filter({ hasText: memo }).first().click();
            await t.wait(1500);
            await t.shot('The retail memo opened on the Returns screen');
            await t.page.getByLabel('Quantity').first().fill('1');
            await t.page.getByLabel('They want').selectOption('credit');
            await t.page.getByLabel(/Reason \(required\)/).fill('Wrong strip');
            await t.click('Record the return', { wait: 2000 });
            await t.shot('Asking for credit on a retail sale: there is no account to credit');
            await t.see(/no account to credit|Use exchange or cash/i, 'A retail sale cannot be credited, and the screen says so: "A retail sale has no account to credit. Use exchange or cash."');
            await t.page.getByLabel('They want').selectOption('cash');
            await t.click('Record the return', { wait: 2000 });
            await t.shot('Cash back recorded, waiting for the owner');
            await t.see(/Waiting to be approved[\s\S]{0,400}Retail/, 'The return is recorded against "Retail" and waits for the owner');
            await t.page.getByRole('button', { name: 'Approve', exact: true }).first().click();
            await t.wait(2000);
            await t.shot('Approved: the return is decided');
            await t.see(/Recently decided[\s\S]{0,600}Retail/, 'The approved retail return appears in "Recently decided"');
        },
    },
    {
        n: 271, role: 'admin',
        did: 'Opened a retail memo on the Returns screen and looked for the optional "Will receive money" box. Typed an amount and recorded the return.',
        async run(t) {
            await ensureSync(t, true);
            await newRetail(t);
            await addNapa(t, 'Strip', '100');
            await finishSale(t);
            const m = (await t.text()).match(/M\d{4}-\d{6}/)?.[0];
            await t.go('/admin/returns');
            await t.fill('Memo number', m);
            await t.click('Search', { wait: 2000 });
            await t.page.locator('main button, main a').filter({ hasText: m }).first().click();
            await t.wait(1500);
            await t.see(/Will receive money \(optional\)/, 'A retail return has an optional "Will receive money" box');
            await t.page.getByLabel('Quantity').first().fill('1');
            await t.page.getByLabel('They want').selectOption('cash');
            await t.page.getByLabel(/Will receive money/).fill('45');
            await t.page.getByLabel(/Reason \(required\)/).fill('Not needed');
            await t.shot('An amount typed in "Will receive money"');
            await t.click('Record the return', { wait: 2000 });
            await t.shot('The return keeps what the buyer expects to receive');
            await t.see(/The buyer expects to receive ৳45.00/, 'The amount the buyer expects is kept on the return and shown to the owner when deciding');
        },
    },
];
