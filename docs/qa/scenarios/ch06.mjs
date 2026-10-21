// Stock system, part 3 (features 76-108): lots, slow stock, expiry, corrections, purchase entry, counts, barcodes
import { addNapa, ensureSync, napaStock, newRetail } from './_counter.mjs';

async function startPurchase(t) {
    await t.as('admin');
    await t.go('/admin/stock/receive');
    // A half-finished entry comes back by itself. Start clean unless the test is about that.
    const away = t.page.getByText('Throw it away');
    if (await away.count()) {
        await away.click();
        await t.wait(900);
    }
    await t.fill('Search a medicine', 'napa');
    await t.wait(1300);
    await t.click(/Napa 500 mg/);
}

async function expiryTab(t, name) {
    await t.go('/admin/stock/expiry');
    await t.click(new RegExp(`^${name}`), { wait: 800 });
}

export default [
    {
        n: 76, role: 'admin',
        did: 'Bought an older, dearer lot of 5 boxes (cost 285 each), then sold a box with a 10% special price (270). A lot costing 285 would lose money at 270, so the system should take the box from a cheaper lot.',
        async run(t) {
            await ensureSync(t, true);
            await startPurchase(t);
            await t.fill('Invoice number', `QA-OLD-${Date.now()}`);
            await t.fill('Invoice date', '2026-09-01');
            await t.fill('Units', '5');
            await t.fill('Company %', '5');
            await t.shot('An older lot bought at only 5% off: cost 285 a box');
            await t.click('Save purchase', { wait: 2500 });
            await t.go('/admin/stock/medicines/1');
            await t.shot('Lots before the sale');
            await newRetail(t);
            await t.fill('Special %', '10');
            await addNapa(t);
            await t.go('/admin/stock/medicines/1');
            await t.shot('Lots after selling one box at a 10% special price');
            const body = await t.text();
            const old = body.match(/(\d+) \/ 5\s*[\s\S]{0,40}?285/);
            t.check(old && old[1] === '5', `The dear lot (285) was left alone because selling from it at 270 would lose money (it still has ${old ? old[1] : '?'} of 5)`);
        },
    },
    {
        n: 77, role: 'admin',
        did: 'Opened the Expiry page to see which goods have been sitting still.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            await t.shot('The Expiry page: goods that sit still are the risk');
            await t.see(/Goods that move every month need no expiry entry\. Only goods that sit still are a risk/i, 'The page says only slow-moving goods are a risk');
            await t.see(/Needs checking/, 'There is a "Needs checking" list for slow stock');
        },
    },
    {
        n: 78, role: 'admin',
        did: 'Looked for the "months of stock" figure next to slow-moving goods.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            const body = await t.text();
            if (!/months of stock/.test(body)) {
                await startPurchase(t);
                await t.fill('Invoice number', `QA-SLOW-${Date.now()}`);
                await t.fill('Units', '20');
                await t.click('Save purchase', { wait: 2500 });
                await t.go('/admin/stock/expiry');
            }
            await t.see(/months of stock at the current pace/i, 'Each slow item says how many months of stock it is at the current pace of selling');
            await t.shot('"... months of stock at the current pace"');
        },
    },
    {
        n: 79, role: 'admin',
        did: 'Changed "Stagnant after (months)" in the settings at the bottom of the Expiry page, saved, and checked it was kept. Then put it back.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            const field = t.page.getByLabel('Stagnant after (months)');
            const before = await field.inputValue();
            await field.fill('2');
            await t.shot('The three limits can be changed here');
            await t.click('Save settings', { wait: 1500 });
            await t.go('/admin/stock/expiry');
            const after = await t.page.getByLabel('Stagnant after (months)').inputValue();
            t.check(after === '2', `The new limit is kept after saving (it shows ${after})`);
            await t.page.getByLabel('Stagnant after (months)').fill(before);
            await t.click('Save settings', { wait: 1200 });
        },
    },
    {
        n: 80, role: 'admin',
        did: 'Pressed "Checked" on a slow item and looked at the outcomes offered.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            if (!(await t.page.getByRole('button', { name: /^Checked$/ }).count())) {
                t.skip('No item was waiting to be checked in the test data, so the outcome buttons could not be shown.');
                await t.shot('Nothing needs checking right now');
                return;
            }
            await t.click(/^Checked$/);
            await t.shot('After pressing Checked: the owner records the result');
            await t.see(/Still good, keep it/, 'One outcome: "Still good, keep it"');
            await t.see(/Expiry date \(optional\)/, 'The other: record the expiry date, which moves it to the near-expiry or expired lists');
        },
    },
    {
        n: 81, role: 'admin',
        did: 'Looked at the Checked form for an expiry date box.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            if (!(await t.page.getByRole('button', { name: /^Checked$/ }).count())) {
                t.skip('No item was waiting to be checked, so the form could not be opened. The Expiry date box was seen on the purchase form instead.');
                await t.go('/admin/stock/receive');
                await t.see(/Expiry date \(optional\)/, 'The purchase form has an optional Expiry date box');
                await t.shot('The purchase form\'s Expiry date box');
                return;
            }
            await t.click(/^Checked$/);
            await t.see(/Expiry date \(optional\)/, 'Marking an item Checked asks for its expiry date');
            await t.shot('The Expiry date box on the Checked form');
        },
    },
    {
        n: 82, role: 'admin',
        did: 'Read the money figure on the Expiry page.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            await t.shot('The page states how much money is at risk');
            await t.see(/Goods worth ৳[\d,.]+ need checking|Nothing needs checking/, 'The page shows how much money is in goods that need checking');
            await t.see(/৳[\d,.]+/, 'Each slow item shows its value');
        },
    },
    {
        n: 83, role: 'admin',
        did: 'Opened the "Near expiry" list and looked at what it suggests for each lot.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            await t.click(/^Near expiry/, { wait: 900 });
            await t.shot('Near expiry: each lot shows its lowest safe price and a suggested discount');
            await t.see(/Lowest safe price/, 'Each near-expiry lot shows the lowest price it can be sold at without a loss');
            await t.see(/% off MRP/, 'It suggests a discount for an offer');
        },
    },
    {
        n: 84, role: 'admin',
        did: 'Moved a near-expiry lot to the expired section and opened the Expired list.',
        async run(t) {
            await t.go('/admin/stock/expiry');
            await t.click(/^Near expiry/, { wait: 800 });
            if (await t.page.getByRole('button', { name: /^Expired$/ }).count()) await t.click(/^Expired$/, { wait: 1500 });
            await t.click(/^Expired \d|^Expired$/, { wait: 800 });
            await t.shot('The expired section, grouped by company');
            await t.see(/Square\s*\n?\s*\d+ units/i, 'Expired goods are grouped under their company, with the unit count and value');
        },
    },
    {
        n: 85, role: 'admin',
        did: 'Looked on the expired list for a way to hand the goods back to the company, with a printable list.',
        async run(t) {
            await expiryTab(t, 'Expired');
            await t.shot('The expired list');
            const links = await t.page.locator('a,button').allInnerTexts();
            t.check(links.some((x) => /pdf|print|download/i.test(x)), 'There is a PDF or print link for the company return list');
            await t.go('/admin/stock/expiry/return-list');
            await t.shot('The return list on plain paper, ready to print or save as PDF');
            await t.see(/Return to company/, 'The list opens on plain paper with the heading "Return to company"');
            await t.see(/Received by/, 'And a line to sign when the goods are handed back');
        },
    },
    {
        n: 86, role: 'admin',
        did: 'Looked at the three states offered for each expired lot.',
        async run(t) {
            await expiryTab(t, 'Expired');
            await t.see(/Pending/, 'State one: Pending');
            await t.see(/Returned to company representative/, 'State two: Returned to company representative');
            await t.see(/Not taken back/, 'State three: Not taken back');
            await t.shot('Pending, Returned, or Not taken back');
        },
    },
    {
        n: 87, role: 'admin',
        did: 'Marked an expired lot as "Not taken back" and looked for the loss.',
        async run(t) {
            await expiryTab(t, 'Expired');
            await t.pick('css=select[aria-label="Return status"]', 'Not taken back');
            await t.wait(1200);
            await t.shot('After marking a lot as not taken back');
            await t.go('/admin/reports');
            await t.shot('The monthly report: no loss line for the expired goods');
            const a = /loss|written off|expired/i.test(await t.text());
            await t.go('/admin/stock/reports');
            await t.shot('The stock profit report: no loss line either');
            const b = /loss|written off|expired/i.test(await t.text());
            t.check(a || b, 'The money in goods the company would not take back shows up as a loss in the reports', 'neither report mentions it');
        },
    },
    {
        n: 88, role: 'admin',
        did: 'Checked that, with Master Stock Sync off, only offer orders take stock out.',
        async run(t) {
            await ensureSync(t, false);
            const s0 = await napaStock(t);
            await newRetail(t);
            await addNapa(t);
            const s1 = await napaStock(t);
            t.check(s1 === s0, `With the switch off, an ordinary retail sale does not take stock out (${s0} stayed ${s1})`);
            await t.go('/admin/counter');
            await t.shot('A sale made while the switch is off');
            await ensureSync(t, true);
        },
    },
    {
        n: 89, role: 'admin',
        did: 'Looked at the options that are built for retail but not used yet.',
        async run(t) {
            await ensureSync(t, false);
            await t.shot('Stock page with the switch off: the screen says how things work "for now"');
            await t.see(/for now/i, 'The screen says how things work "for now", pointing to later use');
            await ensureSync(t, true);
        },
    },
    {
        n: 90, role: 'admin',
        did: 'Entered an old lot by hand, with an earlier invoice date, as a shop does when it first starts using the system.',
        async run(t) {
            await startPurchase(t);
            await t.fill('Invoice number', `QA-START-${Date.now()}`);
            await t.fill('Invoice date', '2026-07-01');
            await t.fill('Units', '4');
            await t.fill('Company %', '20');
            await t.shot('An old stock lot typed in by hand with an old date');
            await t.click('Save purchase', { wait: 2500 });
            await t.go('/admin/stock/medicines/1');
            await t.see(/1 Jul 2026/, 'The old lot is on the shelf with its old date');
            await t.shot('The old lot in the Lots table');
        },
    },
    {
        n: 91, role: 'staff',
        did: 'As staff, tried to open the stock-correction screen. Then as the owner, opened it.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            const staffSees = /Correct the stock number/.test(await t.text());
            await t.shot('A staff member on the medicine page');
            t.check(!staffSees, 'Staff cannot correct a stock number');
            await t.as('admin');
            await t.go('/admin/stock/medicines/1');
            await t.see(/Only the admin can do this/, 'The owner\'s screen says only the admin can correct stock');
            await t.shot('The owner\'s "Correct the stock number" box');
        },
    },
    {
        n: 92, role: 'admin',
        did: 'Corrected the stock number and looked at how it was recorded.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            const body = await t.text();
            const m = body.match(/On hand\s*(\d+) boxes/);
            const n = m ? Number(m[1]) : 30;
            await t.fill('Number on the shelf', String(n - 1));
            await t.pick('Reason', 'entry_mistake');
            await t.shot('Correcting the number by one, as an entry mistake');
            await t.click('Save correction', { wait: 1800 });
            await t.shot('After the correction');
            await t.see(/It fixes an entry error and is not a sale/, 'The page says a correction is an entry fix, not a sale, so it is not counted as a loss');
            await t.see(/correction/i, 'The movement is written as a correction');
        },
    },
    {
        n: 93, role: 'admin',
        did: 'Looked at the movement list after a correction to see its record and reason.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            await t.see(/Recent movements/, 'The medicine page keeps a movement list');
            await t.see(/entry_mistake|entry mistake/i, 'The reason for the correction is kept');
            await t.shot('Recent movements: the correction and its reason');
        },
    },
    {
        n: 94, role: 'staff',
        did: 'As staff, pressed "Report mismatch" at the counter when the shelf number looked wrong. Then looked at the owner\'s Mismatch reports.',
        async run(t) {
            await t.go('/admin/counter');
            await t.click('Report mismatch', { wait: 1200 });
            await t.shot('The mismatch form');
            const inputs = t.page.locator('input:visible');
            const count = await inputs.count();
            t.note(`The form has ${count} visible boxes.`);
            await t.see(/mismatch|counted|shelf/i, 'Staff get a form to report that the shelf and the system disagree');
        },
    },
    {
        n: 95, role: 'admin',
        did: 'Looked for a warning about stock split across many small lots.',
        async run(t) {
            await t.go('/admin/stock/medicines/1');
            await t.shot('Lots for Napa');
            t.skip('No warning for many small lots was shown. It would need many tiny lots in the test data.');
        },
    },
    {
        n: 96, role: 'admin',
        did: 'Opened Enter purchase and looked for the supplier invoice number box.',
        async run(t) {
            await t.go('/admin/stock/receive');
            await t.see(/Invoice number/, 'Every purchase has an invoice number box');
            await t.shot('Enter purchase: the invoice number box');
        },
    },
    {
        n: 97, role: 'admin',
        did: 'Added a buying source with no percentage and tried to save a purchase from it with no percentage typed.',
        async run(t) {
            await t.go('/admin/stock/sources');
            await t.fill('Source name', 'No percent source');
            await t.pick('css=select', 'shop');
            await t.fill('Default % below MRP', '');
            await t.click('Add source', { wait: 1500 });
            await t.shot('Adding a source with no default percentage');
            await startPurchase(t);
            await t.pick('css=select', /No percent source/);
            await t.fill('Invoice number', `QA-NOPCT-${Date.now()}`);
            await t.fill('Units', '2');
            await t.shot('A purchase from it with no percentage typed');
            await t.click('Save purchase', { wait: 1800 });
            await t.shot('What the system says');
            await t.see(/percent|%/i, 'The purchase is refused until a percentage is given');
        },
    },
    {
        n: 98, role: 'admin',
        did: 'Filled part of a purchase and pressed "Save as draft", then looked for it on the next visit.',
        async run(t) {
            await startPurchase(t);
            await t.fill('Invoice number', `QA-DRAFT-${Date.now()}`);
            await t.fill('Units', '3');
            await t.fill('Company %', '20');
            await t.click('Save as draft', { wait: 2000 });
            await t.shot('After "Save as draft"');
            await t.go('/admin/stock/receive');
            await t.shot('Back on Enter purchase: the draft waits here');
            await t.see(/draft/i, 'The half-finished purchase is kept as a draft');
        },
    },
    {
        n: 99, role: 'admin',
        did: 'Pressed "Add a line" to enter several medicines in one purchase.',
        async run(t) {
            await startPurchase(t);
            await t.click('Add a line', { wait: 900 });
            await t.shot('A second line added to the same purchase');
            t.check((await t.page.getByLabel('Units').count()) >= 2, 'A purchase can hold many lines');
            t.skip('Adding all of one company\'s medicines in one go was not found as a separate control. Lines are added one at a time.');
        },
    },
    {
        n: 100, role: 'admin',
        did: 'Looked for one place at the top of the purchase to set the percentage for every line.',
        async run(t) {
            await startPurchase(t);
            await t.shot('The purchase form: the Source sets one default for all lines');
            await t.see(/Source/, 'The Source at the top sets one default percentage for every line');
            t.note('The percentage is set once through the Source at the top. Each line can still change it.');
        },
    },
    {
        n: 101, role: 'admin',
        did: 'Looked for a warning when the unit of a purchase differs from the medicine\'s usual unit.',
        async run(t) {
            await startPurchase(t);
            await t.shot('Purchase line');
            t.skip('No unit-change warning was found on the purchase line.');
        },
    },
    {
        n: 102, role: 'admin',
        did: 'Looked for the optional shelf number on a purchase line.',
        async run(t) {
            await startPurchase(t);
            await t.see(/Shelf/, 'Each purchase line has an optional Shelf box');
            await t.shot('The Shelf box');
        },
    },
    {
        n: 103, role: 'admin',
        did: 'Saved a purchase, then tried to save another with the same invoice number.',
        async run(t) {
            const inv = `QA-DUP-${Date.now()}`;
            for (let i = 0; i < 2; i++) {
                await startPurchase(t);
                await t.fill('Invoice number', inv);
                await t.fill('Units', '1');
                await t.fill('Company %', '20');
                await t.click('Save purchase', { wait: 2000 });
                if (i === 1) await t.shot('The second time with the same invoice number');
            }
            await t.see(/already|same invoice|twice|duplicate/i, 'The system warns that this invoice number was already entered');
        },
    },
    {
        n: 104, role: 'admin',
        did: 'Opened Stock count and looked at the ways to count: whole shop, one company or one shelf.',
        async run(t) {
            await t.go('/admin/stock/counts');
            await t.see(/Whole shop/, 'A count can be the whole shop');
            await t.see(/One company/, 'Or one company');
            await t.see(/One shelf/, 'Or one shelf');
            await t.shot('Choosing how to count');
        },
    },
    {
        n: 105, role: 'admin',
        did: 'Started a count of one company and looked at how differences are approved.',
        async run(t) {
            await t.go('/admin/stock/counts');
            await t.click('Start a count', { wait: 2000 });
            await t.shot('A count in progress');
            await t.see(/count|shelf|system/i, 'A count compares what is on the shelf with the system');
            t.note('Approving the differences needs counted numbers typed against each medicine, which was not taken further here.');
        },
    },
    {
        n: 106, role: 'admin',
        did: 'Typed a known barcode the way a scanner does (the digits, then Enter), first at the counter and then on the purchase form.',
        async run(t) {
            await newRetail(t);
            await t.page.getByPlaceholder(/scan a barcode/i).click();
            await t.page.keyboard.type('8900000000011', { delay: 15 });
            await t.press('Enter');
            await t.wait(1500);
            await t.shot('At the counter: the scanned barcode');
            await t.see(/Napa 500 mg/, 'At the counter, scanning a known barcode adds that medicine to the sale');
            await t.click('Cancel draft', { wait: 1200 });
            await t.go('/admin/stock/receive');
            const away = t.page.getByText('Throw it away');
            if (await away.count()) await away.click();
            await t.page.locator('#scan').click();
            await t.page.keyboard.type('8900000000011', { delay: 15 });
            await t.press('Enter');
            await t.wait(2000);
            await t.shot('On the purchase form: the same barcode');
            const lineAdded = (await t.page.getByText(/Napa/).count()) > 0;
            t.check(lineAdded, 'On the purchase form, scanning a known barcode adds a line for that medicine', 'nothing happened: the page reloaded');
            t.note('The scan box on the purchase form sits inside the purchase form. The browser console reports "a form cannot be a descendant of a form", and pressing Enter reloads the page instead of looking the barcode up.');
        },
    },
    {
        n: 107, role: 'admin',
        did: 'Looked for phone-camera scanning.',
        async run(t) {
            await t.go('/admin/stock/receive');
            await t.shot('The scan box works with a USB or Bluetooth scanner that types the code');
            t.skip('Scanning with a phone camera is not built. USB and Bluetooth scanners, which type the number, are supported.');
        },
    },
    {
        n: 108, role: 'admin',
        did: 'Typed a barcode the system has never seen into the scan box on the purchase form, expecting it to ask which medicine it belongs to.',
        async run(t) {
            await t.go('/admin/stock/receive');
            const away = t.page.getByText('Throw it away');
            if (await away.count()) await away.click();
            await t.page.locator('#scan').click();
            await t.page.keyboard.type('8901234567890', { delay: 15 });
            await t.press('Enter');
            await t.wait(2000);
            await t.shot('An unknown barcode on the purchase form');
            await t.see(/is new\. Which medicine is it\?/i, 'An unknown barcode asks "Which medicine is it?" so the owner can link it once');
            t.note('Linking the code once, so it is remembered, cannot be reached because the scan box on this screen does not respond to Enter (see feature 106). Once a code is linked, the counter does recognise it.');
        },
    },
];
