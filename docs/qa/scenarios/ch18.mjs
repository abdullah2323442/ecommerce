// Multi-sector and custom orders (features 366-415)

async function sectorsBlock(t) {
    await t.as('admin');
    await t.go('/admin/market');
    await t.page.getByText('Sectors', { exact: true }).first().scrollIntoViewIfNeeded().catch(() => {});
}

export default [
    {
        n: 366, role: 'admin',
        did: 'Opened the Sectors area of the owner\'s Market page, where each kind of shop is set up.',
        async run(t) {
            await sectorsBlock(t);
            await t.shot('Each sector has its own settings: no programmer needed');
            await t.see(/Fields this kind of business needs/, 'Each sector has a list of the fields its business needs');
            await t.see(/Sellers pick from the catalog/, 'And whether sellers must pick from the catalog');
        },
    },
    {
        n: 367, role: 'admin',
        did: 'Looked at the fields a sector can ask sellers for.',
        async run(t) {
            await sectorsBlock(t);
            for (const f of ['Colour', 'Size', 'Measurements', 'Weight', 'Expiry date', 'Warranty']) await t.see(f, `A sector can ask for "${f}"`);
            await t.shot('Fields a sector can ask for');
            t.skip('A unit for each sector (piece, metre, kilo) was not found on this screen. The unit shows on product pages, such as "/ Piece".');
        },
    },
    {
        n: 368, role: 'admin',
        did: 'Looked for the "Fractions allowed" switch on a sector.',
        async run(t) {
            await sectorsBlock(t);
            await t.see(/Fractions allowed/, 'A sector can allow fractional quantities, such as 2.5 metres');
            await t.shot('Fractions allowed');
        },
    },
    {
        n: 369, role: 'admin',
        did: 'Looked for the Pre-order and Chat switches on a sector.',
        async run(t) {
            await sectorsBlock(t);
            await t.see(/Pre-order/, 'A sector can switch pre-order on or off');
            await t.see(/\bChat\b/, 'A sector can switch chat on or off');
            await t.shot('Pre-order and Chat switches');
        },
    },
    {
        n: 370, role: 'wholesale',
        did: 'Opened the seller\'s "Add a listing" form and looked for the colour and size grid.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.click('Add colours and sizes', { wait: 1200 });
            await t.shot('The "Add colours and sizes" grid on a listing');
            const body = await t.text();
            t.check(/Colour[\s\S]{0,400}Size|Size[\s\S]{0,400}Colour/.test(body), 'A listing can carry a grid of colours and sizes', 'no colour and size grid appeared');
        },
    },
    { n: 371, role: 'guest', did: 'Looked for a colour greying out when it sells out.', async run(t) { await t.go('/market?sector=clothing'); await t.shot('Clothing'); t.skip('Needs a clothing product with several colours and one sold out. Not in the test data.'); } },
    { n: 372, role: 'guest', did: 'Looked for a price and photo for each colour and size.', async run(t) { await t.go('/market?sector=clothing'); await t.shot('Clothing'); t.skip('Needs a clothing product with a variant grid. Not in the test data.'); } },
    { n: 373, role: 'guest', did: 'Looked for sizes on wooden items.', async run(t) { await t.go('/market?sector=wood'); await t.shot('Wood'); t.skip('Needs a wooden product with sizes. Not carried through.'); } },
    {
        n: 374, role: 'wholesale',
        did: 'Looked at the seller\'s reports for best and worst sellers and what each earns.',
        async run(t) {
            await t.go('/wholesale/sell/reports');
            await t.see(/Sells the most/, 'The seller sees their best sellers');
            await t.see(/Sells the least/, 'And their worst sellers');
            await t.see(/Earns the most/, 'And which product earns the most');
            await t.shot('Basic stock figures for sellers');
        },
    },
    { n: 375, role: 'admin', did: 'Looked for one product in many categories.', async run(t) { await t.go('/admin/catalog'); await t.shot('The catalog'); t.skip('Not carried through.'); } },
    { n: 376, role: 'admin', did: 'Looked for a prohibited-category list.', async run(t) { await t.go('/admin/market'); await t.shot('The owner\'s Market page'); t.skip('No prohibited-category list was found on the owner\'s screens.'); } },
    { n: 377, role: 'admin', did: 'Looked for wood and clothing returns in the same Return section.', async run(t) { await t.go('/admin/returns'); await t.shot('Returns'); t.skip('Not carried through.'); } },
    {
        n: 378, role: 'visitor',
        did: 'Opened "Made to order" and looked at the two kinds of work.',
        async run(t) {
            await t.go('/custom');
            await t.shot('Made to order: wood and clothing');
            await t.see(/Wood/, 'Wood can be made to order');
            await t.see(/Clothing/, 'Clothing can be made to order');
            await t.see(/1\. Tell us what you need/, 'Step one: tell us what you need');
            await t.see(/2\. We send a price/, 'Step two: we send a price');
            await t.see(/3\. We make it/, 'Step three: we make it');
        },
    },
    {
        n: 379, role: 'visitor',
        did: 'As an ordinary shopper, opened the made-to-order form. Then did the same as a paikari shop and as a stranger.',
        async run(t) {
            await t.go('/custom/new/wood');
            await t.shot('An ordinary shopper on the made-to-order form');
            t.check(new URL(t.page.url()).pathname.startsWith('/custom/new'), 'An ordinary shopper can start a made-to-order job');
            await t.as('paikari');
            await t.go('/custom/new/wood');
            t.check(new URL(t.page.url()).pathname.startsWith('/custom/new'), 'A shop account can start one too');
            await t.as('guest');
            await t.go('/custom/new/wood');
            await t.shot('A stranger is asked to sign in first, because a job has a chat, a price and money to follow');
            t.note('A stranger is sent to sign in first. The job needs a name, a chat and a payment trail, so "everyone" here means every kind of account.');
        },
    },
    {
        n: 380, role: 'visitor',
        did: 'Opened the clothing order form and looked at the measurements it asks for.',
        async run(t) {
            await t.go('/custom/new/clothing');
            for (const m of ['Chest', 'Waist', 'Length', 'Sleeve', 'Shoulder', 'Neck', 'Trouser length', 'Thigh', 'Hem (mohori)']) await t.see(m, `The form asks for ${m}`);
            await t.shot('The measurement form');
        },
    },
    {
        n: 381, role: 'visitor',
        did: 'Opened the wood order form and looked at how the wood type is chosen and priced.',
        async run(t) {
            await t.go('/custom/new/wood');
            await t.shot('Which wood?');
            await t.see(/Which wood\?/, 'The buyer chooses the wood');
            await t.as('admin');
            await t.go('/admin/custom');
            await t.see(/Each has its own rate: for each cubic foot of wood, for each metre of fabric\. The buyer sees the rate when they choose/, 'Each wood has its own rate, shown to the buyer when they choose');
            await t.shot('Woods and fabrics each have a rate');
        },
    },
    {
        n: 382, role: 'visitor',
        did: 'Looked at the fabric choices on the clothing form.',
        async run(t) {
            await t.go('/custom/new/clothing');
            await t.see(/Which fabric\?/, 'The buyer chooses the fabric');
            await t.see(/I bring my own fabric/, 'Or brings their own');
            await t.see(/You supply the fabric/, 'Or the shop supplies it');
            await t.shot('Fabric choices');
        },
    },
    {
        n: 383, role: 'visitor',
        did: 'Looked for an upload box for a photo of the space.',
        async run(t) {
            await t.go('/custom/new/wood');
            await t.see(/A photo of the place/, 'There is a box to upload a photo of the place');
            await t.shot('A photo of the place');
        },
    },
    {
        n: 384, role: 'visitor',
        did: 'Saved a set of measurements under the name "Rahim" on the Saved measurements page.',
        async run(t) {
            await t.go('/custom/profiles');
            await t.fill('Name', `Rahim ${String(Date.now()).slice(-4)}`);
            await t.page.getByLabel("A man's clothes").check().catch(() => {});
            await t.fill('Chest', '40');
            await t.fill('Waist', '34');
            await t.fill('Length', '30');
            await t.shot('A measurement profile filled in');
            await t.click('Save', { wait: 1800 });
            await t.shot('The saved profile');
            await t.see(/Rahim/, 'The measurements are saved under a name');
        },
    },
    {
        n: 385, role: 'visitor',
        did: 'Looked at the clothing form for choosing a saved profile and how many pieces.',
        async run(t) {
            await t.go('/custom/new/clothing');
            await t.see(/Measurements/, 'The form lets the buyer pick saved measurements');
            await t.see(/How many/, 'And how many pieces');
            await t.shot('Saved measurements and a quantity');
        },
    },
    {
        n: 386, role: 'visitor',
        did: 'Looked at the saved profile for its measuring date.',
        async run(t) {
            await t.go('/custom/profiles');
            await t.shot('Saved profiles');
            const body = await t.text();
            t.check(/measured|\d{1,2} \w{3} 20\d\d/i.test(body), 'Each profile shows the date it was measured, so an old one can be spotted', 'no measuring date is shown');
        },
    },
    { n: 387, role: 'visitor', did: 'Looked for previous orders on a profile.', async run(t) { await t.go('/custom/profiles'); await t.shot('Saved profiles'); t.skip('Needs a job made from a saved profile. Not carried through.'); } },
    { n: 388, role: 'visitor', did: 'Looked for both sides agreeing the measurements.', async run(t) { await t.go('/custom'); await t.shot('Made to order'); t.skip('Needs a job in progress. Not carried through.'); } },
    { n: 389, role: 'visitor', did: 'Looked for a trial step on clothing.', async run(t) { await t.go('/custom'); await t.shot('Made to order'); t.skip('Needs a job in progress. Not carried through.'); } },
    {
        n: 390, role: 'admin',
        did: 'Opened the Craftsmen page.',
        async run(t) {
            await t.go('/admin/craftsmen');
            await t.see(/Our own people\. They see only their own jobs and wages/, 'Craftsmen are the shop\'s own people and see only their own jobs and wages');
            await t.shot('Craftsmen');
        },
    },
    { n: 391, role: 'admin', did: 'Looked for shifting or sharing a job.', async run(t) { await t.go('/admin/custom'); await t.shot('Made to order jobs'); t.skip('Needs a job in progress. Not carried through.'); } },
    { n: 392, role: 'admin', did: 'Looked for a record of shifts.', async run(t) { await t.go('/admin/custom'); await t.shot('Made to order jobs'); t.skip('Needs a job in progress. Not carried through.'); } },
    {
        n: 393, role: 'admin',
        did: 'Looked at the Craftsmen page for wages and progress.',
        async run(t) {
            await t.go('/admin/craftsmen');
            await t.see(/Earned this month/, 'Wages earned this month are tracked');
            await t.see(/Owed/, 'And what is owed');
            await t.shot('Wages');
        },
    },
    { n: 394, role: 'craftsman', did: 'Looked for the rule that a job is not complete below 100%.', async run(t) { await t.go('/craftsman'); await t.shot('The craftsman page'); t.skip('Needs a job in progress. Not carried through.'); } },
    { n: 395, role: 'craftsman', did: 'Looked for a photo being required with progress.', async run(t) { await t.go('/craftsman'); await t.shot('The craftsman page'); t.skip('Needs a job in progress. Not carried through.'); } },
    { n: 396, role: 'visitor', did: 'Looked at the buyer\'s job page for craftsman names.', async run(t) { await t.go('/custom'); await t.shot('The buyer\'s jobs'); t.skip('Needs a job in progress. Not carried through.'); } },
    { n: 397, role: 'admin', did: 'Looked at where the owner assigns jobs.', async run(t) { await t.go('/admin/custom'); await t.shot('Made to order'); await t.see(/Being made/, 'Jobs move through stages; the owner assigns a craftsman'); } },
    {
        n: 398, role: 'admin',
        did: 'Looked at the active job count shown for each craftsman.',
        async run(t) {
            await t.go('/admin/craftsmen');
            await t.see(/Active jobs: \d+ · Late jobs: \d+/, 'Each craftsman shows active and late jobs');
            await t.shot('Active jobs per craftsman');
        },
    },
    { n: 399, role: 'admin', did: 'Looked for the rule that no job goes out before wages are agreed.', async run(t) { await t.go('/admin/custom'); await t.shot('Made to order'); t.skip('Needs a job in progress. Not carried through.'); } },
    {
        n: 400, role: 'admin',
        did: 'Looked at the craftsman accounting.',
        async run(t) {
            await t.go('/admin/craftsmen');
            await t.see(/Paid wages/, 'There is a place to record wages paid');
            await t.see(/Earned in all/, 'And a total earned');
            await t.shot('Owed and paid');
        },
    },
    { n: 401, role: 'admin', did: 'Looked for sending "materials needed".', async run(t) { await t.go('/admin/custom'); await t.shot('Made to order'); t.skip('Needs a job in progress. Not carried through.'); } },
    { n: 402, role: 'admin', did: 'Looked at the chat page used for made-to-order jobs.', async run(t) { await t.go('/admin/chats'); await t.shot('Chats'); await t.see(/chat/i, 'There is a chat page'); } },
    { n: 403, role: 'admin', did: 'Looked for the "Send measurements" button in chat.', async run(t) { await t.go('/admin/chats'); await t.shot('Chats'); t.skip('Needs a chat on a job. Not carried through.'); } },
    { n: 404, role: 'admin', did: 'Looked for the "Send offer" button in chat.', async run(t) { await t.go('/admin/chats'); await t.shot('Chats'); t.skip('Needs a chat on a job. Not carried through.'); } },
    { n: 405, role: 'admin', did: 'Looked for numbers and links being blocked in chat.', async run(t) { await t.go('/admin/chats'); await t.shot('Chats'); t.skip('Needs a chat on a job. Not carried through.'); } },
    { n: 406, role: 'admin', did: 'Looked at whether the owner can see every chat.', async run(t) { await t.go('/admin/chats'); await t.shot('The owner\'s chats page'); await t.see(/chat/i, 'The owner has a page for chats'); } },
    { n: 407, role: 'admin', did: 'Looked for measurements and offers as cards in chat.', async run(t) { await t.go('/admin/chats'); await t.shot('Chats'); t.skip('Needs a chat on a job. Not carried through.'); } },
    { n: 408, role: 'admin', did: 'Looked for a notice when a craftsman does not reply.', async run(t) { await t.go('/admin/chats'); await t.shot('Chats'); t.skip('Needs time to pass. Not carried through.'); } },
    {
        n: 409, role: 'admin',
        did: 'Looked at the stages of a made-to-order job for the advance.',
        async run(t) {
            await t.go('/admin/custom');
            await t.see(/Confirmed: advance and start/, 'A confirmed job takes an advance before work starts');
            await t.shot('The stages of a job');
        },
    },
    {
        n: 410, role: 'visitor',
        did: 'Read the return rule on the made-to-order page.',
        async run(t) {
            await t.go('/custom');
            await t.see(/Returns do not apply to made-to-order work/, 'The page says returns do not apply to made-to-order work');
            await t.shot('No returns on made-to-order work');
        },
    },
    { n: 411, role: 'visitor', did: 'Looked for a "work not done right" complaint.', async run(t) { await t.go('/custom'); await t.shot('Made to order'); t.skip('Needs a finished job. Not carried through.'); } },
    { n: 412, role: 'visitor', did: 'Looked for cancellation rules before and after work starts.', async run(t) { await t.go('/custom'); await t.shot('Made to order'); t.skip('Needs a job in progress. Not carried through.'); } },
    {
        n: 413, role: 'visitor',
        did: 'Looked at the urgent box on the wood order form.',
        async run(t) {
            await t.go('/custom/new/wood');
            await t.see(/I need it urgently \(extra 20%\)/, 'An urgent job costs an extra 20%, and the buyer is told so');
            await t.shot('Urgent order: extra 20%');
        },
    },
    { n: 414, role: 'admin', did: 'Looked for wood being taken from stock.', async run(t) { await t.go('/admin/custom'); await t.shot('Made to order'); t.skip('Needs a finished job. Not carried through.'); } },
    {
        n: 415, role: 'admin',
        did: 'Read the note about commission on made-to-order work.',
        async run(t) {
            await t.go('/admin/custom');
            await t.see(/There is no commission and no return on this work/, 'No commission is charged on made-to-order work');
            await t.shot('No commission on made-to-order work');
        },
    },
];
