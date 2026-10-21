// Pre-order, memos, messages, notes, questions, wallet and tokens (features 495-528)
import { sendOrder } from './_paikari.mjs';

export default [
    {
        n: 495, role: 'admin',
        did: 'Opened the owner\'s Pre-orders page and the shop\'s own pre-order page.',
        async run(t) {
            await t.go('/admin/preorders');
            await t.see(/Pre-orders/, 'Pre-orders have one page on the owner\'s side');
            await t.shot('The owner\'s Pre-orders page');
            await t.as('paikari');
            await t.go('/preorders');
            await t.shot('The same system, seen by a shop');
            await t.see(/pre-order/i, 'And one page on the shop\'s side');
        },
    },
    {
        n: 496, role: 'admin',
        did: 'Read where pre-orders are switched on and who they go to.',
        async run(t) {
            await t.go('/admin/preorders');
            await t.see(/Where pre-order is on/, 'Pre-order is switched on in one place');
            await t.see(/Turn it on for a whole company or for one medicine, with the time you promise/, 'The owner turns it on for a company or one medicine, with a promised time');
            await t.shot('Where pre-order is on');
        },
    },
    {
        n: 497, role: 'admin',
        did: 'Looked at the choices for a whole company or one medicine.',
        async run(t) {
            await t.go('/admin/preorders');
            await t.see(/A medicine setting beats its company/, 'A medicine setting beats its company\'s');
            await t.shot('By company or by medicine');
        },
    },
    { n: 498, role: 'admin', did: 'Looked for pre-order by size and colour in other sectors.', async run(t) { await t.go('/admin/preorders'); await t.shot('Pre-orders'); t.skip('Pre-order by size and colour was not found on this screen.'); } },
    { n: 499, role: 'admin', did: 'Looked for limited options on pre-orders.', async run(t) { await t.go('/admin/preorders'); await t.shot('Pre-orders'); t.skip('Not carried through.'); } },
    {
        n: 500, role: 'admin',
        did: 'Looked at the buying list that gathers pre-orders together.',
        async run(t) {
            await t.go('/admin/preorders');
            await t.see(/Waiting for your answer/, 'Pre-orders waiting for the owner are gathered in one list');
            await t.see(/Accepted, waiting for the goods/, 'And accepted ones waiting for the goods');
            await t.shot('Pre-orders gathered together');
        },
    },
    {
        n: 501, role: 'admin',
        did: 'Read how pre-orders relate to stock.',
        async run(t) {
            await t.go('/admin/preorders');
            await t.see(/The buyer is not linked to stock\. Receive writes in how much came, and that becomes the real order/, 'A pre-order is not tied to stock; when the goods are received, the real order is made');
            await t.shot('Not linked to stock');
        },
    },
    { n: 502, role: 'paikari', did: 'Looked at the shop\'s messages for a note that goods have arrived.', async run(t) { await t.go('/inbox'); await t.shot('The shop\'s inbox'); t.skip('Needs a pre-order whose goods arrive. Not carried through.'); } },
    { n: 503, role: 'admin', did: 'Looked for reserve or general stock for pre-orders.', async run(t) { await t.go('/admin/preorders'); await t.shot('Pre-orders'); t.skip('Not carried through.'); } },
    { n: 504, role: 'admin', did: 'Looked for the editable draft memo of a pre-order.', async run(t) { await t.go('/admin/preorders'); await t.shot('Pre-orders'); t.skip('Needs an accepted pre-order. Not carried through.'); } },
    { n: 505, role: 'paikari', did: 'Looked for the buyer seeing the memo on "Receive".', async run(t) { await t.go('/preorders'); await t.shot('Pre-orders'); t.skip('Needs a pre-order. Not carried through.'); } },
    {
        n: 506, role: 'admin',
        did: 'Looked at the advance a pre-order asks for.',
        async run(t) {
            await t.go('/admin/settings');
            await t.see(/A share of the price asked up front when a buyer pre-orders\. 0 asks for nothing/, 'A share of the price can be asked up front on a pre-order');
            await t.shot('The advance on a pre-order');
        },
    },
    { n: 507, role: 'paikari', did: 'Looked for a sorry note when goods do not come.', async run(t) { await t.go('/inbox'); await t.shot('Inbox'); t.skip('Needs a declined pre-order. Not carried through.'); } },
    { n: 508, role: 'admin', did: 'Looked for a pre-order turning red when its promised time passes.', async run(t) { await t.go('/admin/preorders'); await t.shot('Pre-orders'); t.skip('Needs time to pass. Not carried through.'); } },
    { n: 509, role: 'admin', did: 'Looked for earlier orders being served first when supply is short.', async run(t) { await t.go('/admin/preorders'); await t.shot('Pre-orders'); t.skip('Not carried through.'); } },
    {
        n: 510, role: 'admin',
        did: 'Opened a finished memo.',
        async run(t) {
            await t.go('/memo/213');
            await t.shot('A memo');
            await t.see(/Siam's Aqua/, 'The memo carries the shop\'s name');
            await t.see(/Items/, 'And its items');
            await t.see(/Served by/, 'And who served the customer');
        },
    },
    { n: 511, role: 'admin', did: 'Looked at the memo lines.', async run(t) { await t.go('/memo/213'); await t.see(/1 Box × ৳300\.00/, 'Each line shows the quantity, unit and price'); await t.shot('Memo lines'); } },
    { n: 512, role: 'admin', did: 'Looked at the memo number.', async run(t) { await t.go('/memo/213'); await t.see(/M2026-\d{6}/, 'The memo number includes the year (2026)'); await t.shot('The year is in the memo number'); } },
    {
        n: 513, role: 'admin',
        did: 'Opened a memo and looked at the paper sizes.',
        async run(t) {
            await t.go('/memo/213');
            await t.see(/58 mm/, 'A 58 mm receipt printer');
            await t.see(/80 mm/, 'An 80 mm receipt printer');
            await t.see(/\bA4\b/, 'Or a full A4 page');
            await t.shot('58 mm, 80 mm or A4');
        },
    },
    { n: 514, role: 'admin', did: 'Looked at the correction screen for a new memo number.', async run(t) { await t.go('/memo/213'); await t.shot('A memo'); t.skip('Making a correction and seeing the old number cancelled was not carried through.'); } },
    {
        n: 515, role: 'admin',
        did: 'Looked on the memo for ways to send it: PDF, email and WhatsApp.',
        async run(t) {
            await t.go('/memo/213');
            const buttons = await t.page.locator('button,a').allInnerTexts();
            await t.shot('Memo: print only');
            t.check(buttons.some((b) => /pdf|email|whatsapp/i.test(b)), 'A memo can be sent as PDF, email or WhatsApp', 'only a Print button was found');
        },
    },
    { n: 516, role: 'admin', did: 'Looked at the print-friendly format.', async run(t) { await t.go('/memo/213'); await t.see(/Print/, 'A memo has a Print button and a plain printing layout'); await t.shot('Print'); } },
    {
        n: 517, role: 'admin',
        did: 'Opened the Messages page, where the owner controls the automatic status messages.',
        async run(t) {
            await t.go('/admin/messages');
            await t.see(/What a customer is told about their order, and by which way/, 'Customers are told about their order automatically');
            await t.see(/We have received your order/, 'For example when the order is received');
            await t.shot('Automatic status messages');
        },
    },
    {
        n: 518, role: 'admin',
        did: 'Looked at the channels on the Messages page.',
        async run(t) {
            await t.go('/admin/messages');
            for (const c of ['Inbox', 'Email', 'SMS', 'Phone notification']) await t.see(c, `Channel: ${c}`);
            await t.see(/The inbox is always on\. SMS costs money, so it starts off/, 'The inbox is always on; SMS starts off because it costs money');
            await t.shot('Four channels');
            t.skip('The checklist marks the channels as partly built. Only the inbox was seen working; email, SMS and phone notifications were not sent.');
        },
    },
    { n: 519, role: 'admin', did: 'Looked at the table that controls each message.', async run(t) { await t.go('/admin/messages'); await t.see(/How important/, 'Each message has an importance'); await t.shot('Controlled from admin'); } },
    { n: 520, role: 'paikari', did: 'Sent an order and looked at the order page for a way to add a note, a voice note or a photo.', async run(t) { await sendOrder(t); await t.see(/Add a note, voice note or photo/, 'The order page lets the shop add a note, a voice note or a photo'); await t.shot('Add a note, voice note or photo'); } },
    { n: 521, role: 'paikari', did: 'Looked at the order page for the time and photo with orders.', async run(t) { await t.go('/paikari'); await t.shot('The ordering screen'); t.skip('Not carried through.'); } },
    {
        n: 522, role: 'guest',
        did: 'Looked at the questions and answers area on a product page.',
        async run(t) {
            await t.go('/market/78');
            await t.see(/Questions and answers/, 'A product page has a questions and answers area');
            await t.shot('Questions and answers');
        },
    },
    {
        n: 523, role: 'admin',
        did: 'Opened the owner\'s Questions page.',
        async run(t) {
            await t.go('/admin/questions');
            await t.see(/Buyers ask under a product\. Everyone can read the answer/, 'Buyers ask under a product and everyone reads the answer');
            await t.see(/Waiting for an answer/, 'Unanswered questions come to the owner');
            await t.shot('Questions waiting for an answer');
        },
    },
    {
        n: 524, role: 'paikari',
        did: 'Opened the shop\'s wallet.',
        async run(t) {
            await t.go('/wallet');
            await t.see(/Your Aqua Tokens/, 'The first part: Aqua Tokens');
            await t.see(/Works in one place only/, 'The second part: money from a return, which works in one place only');
            await t.shot('The wallet has two parts');
        },
    },
    {
        n: 525, role: 'admin',
        did: 'Opened the owner\'s Aqua Tokens page.',
        async run(t) {
            await t.go('/admin/tokens');
            await t.see(/Only we give these\. Each one works in every sector, once, and comes back if the order is cancelled/, 'The owner gives tokens that work once, in any sector');
            await t.shot('Aqua Tokens');
            t.skip('The two levels (the shop\'s tokens and a seller\'s tokens) were seen on separate screens, but giving a token and using it was not carried through.');
        },
    },
    {
        n: 526, role: 'wholesale',
        did: 'Looked at the seller\'s own token form.',
        async run(t) {
            await t.go('/wholesale/sell');
            await t.see(/Your Aqua Token/, 'A seller can make a token');
            await t.see(/One token for all your buyers, only on your own products/, 'It works only on the seller\'s own products');
            await t.shot('A seller\'s token');
        },
    },
    { n: 527, role: 'admin', did: 'Looked at the token rule on cancellation.', async run(t) { await t.go('/admin/tokens'); await t.see(/comes back if the order is cancelled/, 'A token comes back if the order is cancelled'); await t.shot('A token comes back on cancellation'); } },
    { n: 528, role: 'paikari', did: 'Read the rule that a token does not go with an offer.', async run(t) { await t.go('/wallet'); await t.see(/it does not go with an offer price/, 'A token cannot be used together with an offer price'); await t.shot('Token and offer do not combine'); } },
];
