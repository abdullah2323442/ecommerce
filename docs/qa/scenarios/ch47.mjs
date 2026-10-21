// Partly-shown pass, our own delivery person (features 484, 485, 487, 488).
// The owner opens a delivery account "Rider Faruk", two shoppers order (Mirpur and Gulshan), the owner checks both and hands them to the rider.
import { ACCOUNTS } from '../runner/lib.mjs';

ACCOUNTS.rider = 'faruk.rider@siamsaqua.test';

const orders = []; // { id, token, area }

async function openRider(t) {
    await t.preConfirm();
    await t.as('admin');
    await t.go('/admin/staff');
    if (/Rider Faruk/.test(await t.text())) return;
    await t.page.getByPlaceholder('Name', { exact: true }).last().fill('Rider Faruk');
    await t.page.getByPlaceholder('Email', { exact: true }).last().fill(ACCOUNTS.rider);
    await t.page.getByPlaceholder('Phone', { exact: true }).last().fill('01700000031');
    await t.page.locator('main input[type=password]').last().fill('DevPass-2026!');
    await t.page.locator('main select').last().selectOption({ label: 'Delivery person' });
    await t.click('Create account', { wait: 2200 });
}

async function shopperOrders(t, area, address) {
    await t.as('visitor');
    await t.go('/medicines/1');
    await t.click('Add to cart', { wait: 1500 });
    await t.go('/checkout');
    await t.page.locator('#co-name').fill('QA Shopper');
    await t.page.locator('#co-phone').fill('01711111111');
    await t.page.locator('#co-district').selectOption({ label: 'Dhaka' });
    await t.page.locator('#co-area').fill(area);
    await t.page.locator('#co-address').fill(address);
    await t.click('Send order', { wait: 2500 });
    const token = t.page.url().split('/').pop();
    await t.as('admin');
    await t.go('/admin/orders');
    const hrefs = await t.page.locator('main a[href*="/admin/orders/"]').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
    const id = Math.max(...hrefs.map((h) => Number(h.split('/').pop())).filter(Boolean));
    await t.go(`/admin/orders/${id}`);
    await t.page.locator('main input[type=number]').last().fill('50');
    await t.page.getByRole('button', { name: 'Save', exact: true }).click();
    await t.wait(1500);
    await t.page.getByRole('button', { name: 'Full', exact: true }).click();
    await t.wait(1500);
    await t.page.getByRole('button', { name: 'Checking done' }).click();
    await t.wait(2200);
    await t.page.getByRole('button', { name: 'Rider Faruk' }).click();
    await t.wait(1800);
    orders.push({ id, token, area });
}

export default [
    {
        n: 484, role: 'admin',
        did: 'The owner opened a delivery account, two shoppers ordered (one in Mirpur, one in Gulshan), the owner checked both orders and gave them to the rider. The rider opened "My deliveries".',
        async run(t) {
            await openRider(t);
            await shopperOrders(t, 'Mirpur', 'House 12, Road 4');
            await shopperOrders(t, 'Gulshan', 'Flat 5B, Road 9');
            await t.as('rider');
            await t.go('/admin/deliveries');
            await t.shot('The rider\'s list, grouped by area');
            await t.see(/Mirpur/, 'The list has a group for Mirpur');
            await t.see(/Gulshan/, 'And a group for Gulshan, so the rider can plan the trip by area');
            const buttons = await t.page.evaluate(() => [...document.querySelectorAll('main button')].filter((x) => x.offsetWidth).map((x) => x.innerText.trim()).join(' | '));
            t.note(`The rider's buttons: ${buttons}`);
        },
    },
    {
        n: 485, role: 'rider',
        did: 'As the rider, pressed "I have arrived" on the Gulshan delivery. Then, as the buyer, opened the order page.',
        async run(t) {
            await t.go('/admin/deliveries');
            t.note(`Orders in this run: ${JSON.stringify(orders)}`);
            const arrive = t.page.locator(`xpath=//button[normalize-space()='I have arrived'][ancestor::*[contains(., '#${orders[1].id} ') and count(.//button[normalize-space()='I have arrived'])=1]]`);
            await arrive.first().click();
            await t.wait(2000);
            await t.shot('The rider has arrived');
            await t.see(/The buyer knows you are at the door/, 'The delivery is marked "arrived" and the rider is told the buyer knows');
            await t.as('visitor');
            await t.go(`/orders/${orders[1].token}`);
            await t.wait(2000);
            await t.shot('The buyer order page: the rider is at the door, and the code to tell them');
            const body = await t.text();
            t.check(/Our delivery person is at your door/.test(body), 'The buyer is told the delivery person has arrived: "Our delivery person is at your door."', 'the buyer page does not say the rider arrived');
            t.check(/Tell this code to our delivery person[\s\S]{0,200}?\d{6}/.test(body), 'And is shown a six-digit code to tell the rider', 'no code was shown to the buyer');
            t.note(`Around the code: ${(body.match(/Our delivery person[\s\S]{0,260}/) ?? [''])[0].replace(/\s+/g, ' ')}`);
        },
    },
    {
        n: 487, role: 'rider',
        did: 'The buyer told the rider the code. The rider typed it with the money taken and pressed "Delivered" on the Gulshan order. For the Mirpur order the rider attached a photo of the parcel instead.',
        async run(t) {
            await t.as('visitor');
            await t.go(`/orders/${orders[1].token}`);
            await t.wait(2500);
            const code = ((await t.text()).match(/Tell this code to our delivery person[\s\S]{0,160}?(\d{6})/) ?? [])[1];
            await t.as('rider');
            await t.go('/admin/deliveries');
            const field = (id, n) => t.page.locator(`xpath=(//input[not(@type='file')][ancestor::*[contains(., '#${id} ') and count(.//button[normalize-space()='Delivered'])=1]])[${n}]`).first();
            await field(orders[1].id, 1).fill(code);
            await field(orders[1].id, 2).fill('350');
            await t.shot('The buyer code and the money taken, typed by the rider');
            await t.page.locator(`xpath=//button[normalize-space()='Delivered'][ancestor::*[contains(., '#${orders[1].id} ') and count(.//button[normalize-space()='Delivered'])=1]]`).first().click();
            await t.wait(2200);
            await t.shot('After confirming with the code');
            await t.see(/Delivered\. Thank you\./, 'The right code finishes the delivery');
            await t.go('/admin/deliveries');
            await t.shot('The rider list: the finished delivery is gone');
            await t.notSee(new RegExp(`#${orders[1].id} `), 'The delivered order is off the rider list');
            const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
            await t.page.locator(`xpath=//input[@type='file'][ancestor::*[contains(., '#${orders[0].id} ') and count(.//button[normalize-space()='Delivered'])=1]]`).first().setInputFiles({ name: 'parcel.png', mimeType: 'image/png', buffer: PNG });
            await t.page.locator(`xpath=//button[normalize-space()='Delivered'][ancestor::*[contains(., '#${orders[0].id} ') and count(.//button[normalize-space()='Delivered'])=1]]`).first().click();
            await t.wait(2200);
            await t.shot('Finished with a photo of the parcel instead of the code');
            await t.see(/Delivered\. Thank you\./, 'A photo of the parcel can finish a delivery when the buyer has no code');
        },
    },
    {
        n: 488, role: 'visitor',
        did: 'After the rider confirmed with the code, opened the buyer order and then the owner order.',
        async run(t) {
            await t.go(`/orders/${orders[1].token}`);
            await t.wait(1500);
            await t.shot('The buyer order after the code was accepted');
            const body = await t.text();
            t.check(!/Tell this code to our delivery person/.test(body), 'The code is no longer asked for: the sale is complete');
            t.check(/Delivered|Done|delivered/.test(body), 'The order shows as delivered', 'the order does not show as delivered');
            await t.as('admin');
            await t.go(`/admin/orders/${orders[1].id}`);
            await t.shot('The owner order after delivery');
            const o = await t.text();
            t.check(!/Mark as sent|Checking done/.test(o), 'The owner order is finished: a complete sale, not a draft', 'the order still looks open');
        },
    },
];
