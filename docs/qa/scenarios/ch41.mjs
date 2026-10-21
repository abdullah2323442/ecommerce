// Partly-shown pass, made to order: chat cards and the wage rule (features 403, 404, 407, 399).
// Job C2026-00002 (a study table) was sent by the shopper and is waiting for a price. Job 1 already has a wage.

const JOB2 = '/custom/Lw6cFD00taRQezAUb5HrKWEK4nxqoB7iWet0Qs3x';

export default [
    {
        n: 403, role: 'visitor',
        did: 'As the buyer, pressed "Send measurements" in the job chat, filled four measurements and sent them.',
        async run(t) {
            await t.go(JOB2);
            await t.see(/Send measurements/, 'The chat has a "Send measurements" button');
            await t.click('Send measurements', { wait: 800 });
            const boxes = t.page.locator('main input:not([type]), main input[type=text]');
            const n = await boxes.count();
            for (const [i, v] of ['5', '2.5', '3', '1'].entries()) await boxes.nth(n - 4 + i).fill(v);
            await t.shot('The four measurements typed into the chat');
            await t.click('Send these measurements', { wait: 2200 });
            await t.shot('After sending');
            await t.see(/Length[\s\S]{0,40}5/, 'The measurements land in the chat');
        },
    },
    {
        n: 407, role: 'admin',
        did: 'As the owner, opened the same job chat and looked at how the buyer\'s measurements appear.',
        async run(t) {
            await t.go('/admin/chats/2');
            await t.shot('The owner\'s view of the chat: the measurements arrive as a card, apart from the text messages');
            await t.see(/Measurements|Length[\s\S]{0,40}5/, 'The measurements show as their own card');
            await t.page.getByRole('button', { name: 'Send offer' }).click();
            await t.wait(700);
            const f = t.page.locator('main input:not([type]), main input[type=text]');
            const n = await f.count();
            await f.nth(n - 4).fill('19500');
            await f.nth(n - 2).fill('30');
            await f.nth(n - 1).fill('Teak, two coats of walnut polish');
            await t.shot('An offer typed: price, days, advance, note');
            await t.click('Send the offer', { wait: 2200 });
            await t.shot('The offer is in the chat as its own card');
            await t.see(/19,?500/, 'The offer shows in the chat as a card with its price');
        },
    },
    {
        n: 404, role: 'visitor',
        did: 'As the buyer, looked in the job chat for the owner\'s offer.',
        async run(t) {
            await t.go(JOB2);
            await t.shot('The buyer sees the offer in the chat');
            await t.see(/19,?500/, 'The buyer sees the offer the owner sent from the chat, with its price');
        },
    },
    {
        n: 399, role: 'admin',
        did: 'Compared two jobs on the owner\'s page: one with a wage set, one still waiting for its wage.',
        async run(t) {
            await t.go('/admin/custom');
            const body = await t.text();
            const waiting = body.slice(body.indexOf('With the buyer to confirm'), body.indexOf('Confirmed: advance and start'));
            await t.shot('A priced job whose wage is not set yet');
            t.check(!/Give it to someone else/.test(waiting) && /Ask for a wage/.test(waiting), 'A priced job with no wage yet offers "Ask for a wage" and no "Give it to someone else": it cannot be handed to a craftsman', 'the waiting job already offers to hand it on');
            t.check(/Give it to someone else/.test(body.slice(body.indexOf('Being made'))), 'A job whose wage is set (and is being made) can be handed on');
            t.note('The same rule is also checked inside the system: asking to give a job out before its wage is set is refused with "Set the wages before you give the job to a craftsman". This has an automatic test.');
        },
    },
];
