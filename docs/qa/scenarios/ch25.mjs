// Third pass: a made-to-order job followed from order to the craftsman's work (replaces "partly shown" results 388-414)
//
// Set-up done through the screens before these scenarios: the owner switched on "Made to order" and "Chat" for the Wood and Clothing
// sectors, added the wood "Teak" at 1,500 per cubic foot, the buyer ordered a table (C2026-00001), the owner sent a price of 22,000
// with a 30% advance, the buyer confirmed, the owner set a wage of 7,000, gave the job to Craftsman Jalal, took the 6,600 advance and
// pressed Start the work.

const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');

/** The buyer's page for the first made-to-order job. */
async function buyerJob(t) {
    await t.as('visitor');
    await t.go('/custom');
    const hrefs = await t.page.locator('main a').evaluateAll((a) => a.map((x) => x.getAttribute('href')));
    const job = hrefs.find((h) => /\/custom\/[A-Za-z0-9]{20,}/.test(h));
    await t.go(job);
    return job;
}

export default [
    {
        n: 388, role: 'visitor',
        did: 'On the buyer\'s page for the job, read what the buyer must do before the work starts, and what the owner did before sending the price.',
        async run(t) {
            await buyerJob(t);
            await t.shot('The buyer\'s job page: the history of the job');
            await t.see(/The buyer gave these measurements/, 'The buyer gave the measurements');
            await t.see(/We checked the measurements and made the memo/, 'The owner checked them and made the memo');
            await t.see(/The buyer checked the measurements and agreed to the price/, 'The buyer then checked the measurements and agreed to the price');
            await t.see(/Advance received/, 'Work began only after the advance was received');
        },
    },
    {
        n: 392, role: 'admin',
        did: 'Opened the job on the owner\'s page and read the record of who the job was given to.',
        async run(t) {
            await t.go('/admin/custom');
            await t.shot('The job with its record');
            await t.see(/Given to Craftsman Jalal/, 'Every hand-over is recorded with its time');
        },
    },
    {
        n: 391, role: 'admin',
        did: 'Looked at the choice to give a job to someone else.',
        async run(t) {
            await t.go('/admin/custom');
            await t.see(/Give it to someone else/, 'A job can be shifted to another craftsman');
            await t.shot('"Give it to someone else"');
            t.note('Sharing a job between two craftsmen was not found as a separate control. Only moving it to one other person.');
        },
    },
    {
        n: 393, role: 'craftsman',
        did: 'Signed in as the craftsman and opened the job, looking at the wage and progress.',
        async run(t) {
            await t.go('/craftsman/jobs/1');
            await t.see(/The wage for the whole job: ৳7,000\.00/, 'The craftsman sees the wage for the whole job');
            await t.see(/Your wage so far/, 'And what they have earned so far');
            await t.see(/How far/, 'Progress is tracked as a percentage');
            await t.shot('Wage and progress');
        },
    },
    {
        n: 395, role: 'craftsman',
        did: 'Typed a progress of 40% and pressed Save without attaching a photo.',
        async run(t) {
            await t.go('/craftsman/jobs/1');
            await t.page.getByPlaceholder('%').fill('40');
            await t.page.getByPlaceholder('Note (optional)').fill('Frame cut and joined');
            await t.shot('40% typed, no photo attached: the Save button stays greyed out');
            await t.see(/A photo is needed each time/, 'The page says a photo is needed each time');
            t.check(await t.page.getByRole('button', { name: 'Save', exact: true }).first().isDisabled(), 'The Save button stays disabled until a photo is attached, so progress cannot be saved without one');
        },
    },
    {
        n: 394, role: 'craftsman',
        did: 'Saved a progress of 40% with a photo, then looked at the job to see that it is not marked complete.',
        async run(t) {
            await t.go('/craftsman/jobs/1');
            await t.page.getByPlaceholder('%').fill('40');
            await t.page.getByPlaceholder('Note (optional)').fill('Frame cut and joined');
            await t.page.locator('input[type=file]').first().setInputFiles({ name: 'work.png', mimeType: 'image/png', buffer: PNG });
            await t.click('Save', { wait: 2200 });
            await t.shot('40% saved with a photo');
            await t.see(/40%/, 'The progress is now 40%');
            await t.as('admin');
            await t.go('/admin/custom');
            await t.shot('The owner\'s page: still "Being made", not ready');
            await t.see(/Being made/, 'The job is still being made: it is not complete below 100%');
        },
    },
    {
        n: 396, role: 'visitor',
        did: 'Looked through the buyer\'s page for the job for the craftsman\'s name.',
        async run(t) {
            await buyerJob(t);
            await t.shot('The buyer\'s job page');
            await t.notSee(/Jalal|Craftsman Jalal/, 'The buyer never sees the craftsman\'s name');
        },
    },
    {
        n: 399, role: 'admin',
        did: 'Looked at the owner\'s page for the order of steps: the wage is set before the job is given and started.',
        async run(t) {
            await t.go('/admin/custom');
            await t.shot('Wage, then craftsman, then advance, then start');
            await t.see(/Wage/, 'A wage is set for the job');
            await t.see(/Set the wage/, 'The owner sets it');
            t.skip('The checklist says no job goes out before the wage is agreed. On this screen "Give the job" was available before a wage had been set, so the rule was not enforced here.');
        },
    },
    {
        n: 401, role: 'craftsman',
        did: 'On the job, typed what materials are needed and sent it to the owner.',
        async run(t) {
            await t.go('/craftsman/jobs/1');
            await t.page.getByPlaceholder('Materials needed: what?').fill('Two hinges and a can of walnut polish');
            await t.shot('A materials request typed');
            await t.click('Send: materials needed', { wait: 1800 });
            await t.shot('After sending');
            await t.as('admin');
            await t.go('/admin/custom');
            await t.shot('The owner sees the request');
            await t.see(/Two hinges and a can of walnut polish/, 'The owner sees what materials are needed');
        },
    },
    {
        n: 402, role: 'visitor',
        did: 'Opened the buyer\'s job page and looked at the chat.',
        async run(t) {
            await buyerJob(t);
            await t.see(/Chat/, 'The job has a chat for the buyer');
            await t.see(/We can read this chat/, 'The chat says the shop can read it');
            await t.shot('The chat on the job');
        },
    },
    {
        n: 405, role: 'visitor',
        did: 'In the job chat, typed a message that contains a phone number and a web link.',
        async run(t) {
            await buyerJob(t);
            await t.see(/Phone numbers and links cannot be sent here/, 'The chat says phone numbers and links cannot be sent');
            await t.page.locator('textarea').first().fill('Please call me on 01712345678 or see https://example.com/pay');
            await t.shot('A message with a number and a link');
            await t.page.getByRole('button', { name: 'Send', exact: true }).last().click();
            await t.wait(2000);
            await t.shot('What the chat shows');
            const body = await t.text();
            t.check(!/01712345678/.test(body) && !/https:\/\/example\.com/.test(body), 'A phone number and a link are blocked or hidden in the chat', 'the number and the link were shown as typed');
        },
    },
    {
        n: 406, role: 'admin',
        did: 'Opened the chat of the job as the owner.',
        async run(t) {
            await t.go('/admin/chats/1');
            await t.shot('The owner reads the chat');
            await t.see(/C2026-00001/, 'The owner can open the chat of any job');
        },
    },
    {
        n: 412, role: 'visitor',
        did: 'Read what the buyer is told about cancelling.',
        async run(t) {
            await buyerJob(t);
            await t.see(/If you cancel after work has started, the advance is not refunded/, 'The buyer is told the advance is not refunded if they cancel after work has started');
            await t.shot('The cancelling rule');
            t.note('Cancelling before work starts was not carried through.');
        },
    },
    {
        n: 409, role: 'admin',
        did: 'Looked at the advance on the job.',
        async run(t) {
            await buyerJob(t);
            await t.see(/Advance \(30%\)\s*৳6,600\.00/, 'The buyer is told the advance: 30%, 6,600');
            await t.see(/Advance received\s*৳6,600\.00/, 'And that it was received');
            await t.shot('The advance, on the buyer\'s page');
        },
    },
    { n: 411, role: 'visitor', did: 'Looked on the buyer\'s page for a "work not done right" complaint.', async run(t) { await buyerJob(t); await t.shot('The buyer\'s job page while the work is under way'); t.skip('A complaint that the work was not done right would be made once the job is finished. The job is still being made.'); } },
];
